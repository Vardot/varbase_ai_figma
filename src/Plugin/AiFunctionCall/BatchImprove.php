<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma\Plugin\AiFunctionCall;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\varbase_ai_figma\CanvasPageAnalyzer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * AI Agent tool: batch-audit multiple Canvas pages and aggregate the findings.
 *
 * Read-only and fully DETERMINISTIC (no LLM call). Resolves a set of
 * canvas_page entities - an explicit comma-separated list of ids/titles, or
 * every page on the site capped at a safe maximum - and runs lightweight
 * quality checks (accessibility, SEO basics, links) over each page's structured
 * summary. It returns one compact row per page plus aggregated site-wide
 * totals, so a user can spot issues across the whole site at a glance and then
 * drill into a single page with the audit_page tool for the full, per-finding
 * detail.
 *
 * It reports only; low-risk fixes (alt text, meta descriptions, spacing) are
 * applied per-page afterwards with the targeted tools, after review.
 *
 * Covers PRD story 2.22 (batch audit / improve across multiple pages).
 */
#[FunctionCall(
  id: 'varbase_ai_figma:batch_improve',
  function_name: 'varbase_figma_batch_improve',
  name: 'Check many pages at once',
  description: 'Runs lightweight quality checks (accessibility, SEO basics, links) across MULTIPLE Drupal Canvas pages at once and returns an aggregated, per-page report so you can spot issues site-wide and then drill into a specific page with the audit_page tool for full detail. Read-only and deterministic (no AI call). "pages" is an optional comma-separated list of canvas_page ids or exact titles; when empty it audits ALL canvas_page entities, capped at 50 (the cap is noted in the output). "checks" is an optional comma-separated subset of accessibility, seo, links (default: all three). The per-page rows report images missing alt text, heading issues and generic-text links, with a needs_attention flag, and are sorted with the pages needing attention first. Low-risk fixes (alt text, meta, spacing) are applied per-page with the targeted tools after review.',
  group: 'information_tools',
  module_dependencies: ['varbase_ai_figma', 'ai_figma'],
  context_definitions: [
    'pages' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Pages'),
      description: new TranslatableMarkup('Optional comma-separated canvas_page ids or exact titles to audit. When empty, audits ALL canvas_page entities (capped at 50, noted in the output).'),
      required: FALSE,
    ),
    'checks' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Checks'),
      description: new TranslatableMarkup('Optional comma-separated subset of: accessibility, seo, links. Defaults to all three.'),
      required: FALSE,
    ),
  ],
)]
class BatchImprove extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The maximum number of pages audited when no explicit set is given.
   */
  protected const PAGE_CAP = 50;

  /**
   * The check categories this tool can run.
   */
  protected const CHECKS = ['accessibility', 'seo', 'links'];

  /**
   * Anchor texts that count as empty/generic for the links check.
   */
  protected const GENERIC_LINK_TEXT = [
    'click here',
    'read more',
    'here',
    'link',
    '',
  ];

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The Canvas page analyzer (loads + summarises an existing page).
   *
   * @var \Drupal\varbase_ai_figma\CanvasPageAnalyzer
   */
  protected CanvasPageAnalyzer $analyzer;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The collected readable output.
   */
  protected string $result = '';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
      $container->get('plugin.manager.ai_data_type_converter'),
    );
    $instance->currentUser = $container->get('current_user');
    $instance->analyzer = $container->get('varbase_ai_figma.page_analyzer');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->loggerFactory = $container->get('logger.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    if (
      !$this->currentUser->hasPermission('use Drupal Canvas AI')
      && !$this->currentUser->hasPermission('administer ai agents')
    ) {
      throw new \Exception('You do not have permission to batch-audit Canvas pages.');
    }

    // Resolve the requested checks (empty / unknown falls back to all three).
    $checks = $this->resolveChecks((string) ($this->getContextValue('checks') ?? ''));

    // Resolve the page set: an explicit list, else all pages capped at
    // PAGE_CAP.
    $pages_raw = trim((string) ($this->getContextValue('pages') ?? ''));
    [$pages, $unresolved, $cap_applied, $total_available] = $this->resolvePages($pages_raw);
    if (!$pages) {
      $message = $pages_raw !== ''
        ? sprintf('No Canvas pages matched "%s". Pass comma-separated canvas_page ids or exact titles.', $pages_raw)
        : 'There are no Canvas pages to audit.';
      $this->result = $message;
      return;
    }

    // Audit each page deterministically and aggregate the totals.
    $rows = [];
    $totals = [
      'images_missing_alt' => 0,
      'heading_issues' => 0,
      'generic_links' => 0,
      'pages_needing_attention' => 0,
    ];
    foreach ($pages as $page) {
      $row = $this->auditPage($page, $checks);
      $rows[] = $row;
      $totals['images_missing_alt'] += $row['images_missing_alt'];
      $totals['heading_issues'] += $row['heading_issues'];
      $totals['generic_links'] += $row['generic_links'];
      if (!empty($row['needs_attention'])) {
        $totals['pages_needing_attention']++;
      }
    }

    // Sort the rows with the pages needing attention first, then by descending
    // total issue count, so the worst offenders are at the top of the report.
    usort($rows, static function (array $a, array $b): int {
      $score = static fn(array $r): int => (int) $r['images_missing_alt'] + (int) $r['heading_issues'] + (int) $r['generic_links'];
      return ((int) $b['needs_attention'] <=> (int) $a['needs_attention']) ?: ($score($b) <=> $score($a));
    });

    $out = [
      'pages_audited' => count($rows),
      'checks_run' => array_values($checks),
      'totals' => $totals,
      'pages' => $rows,
      'note' => 'Read-only batch audit (deterministic). Run ai_figma:audit_page on a specific page for full per-finding detail, then apply low-risk fixes (alt text, meta, spacing) with the targeted tools after review.',
    ];
    if ($cap_applied) {
      $out['cap_note'] = sprintf('No explicit page list given: audited the first %d of %d Canvas pages (capped at %d). Pass "pages" to target specific ones.', count($rows), $total_available, self::PAGE_CAP);
    }
    if ($unresolved) {
      $out['unresolved'] = sprintf('Could not match: %s.', implode(', ', $unresolved));
    }

    $this->result = Yaml::dump($out, 6, 2);
    $this->loggerFactory->get('ai_figma')->info('batch_improve ran: @s', ['@s' => sprintf('%d page(s) audited', count($rows))]);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

  /**
   * Normalises the "checks" context value into a validated category list.
   *
   * @param string $raw
   *   The comma-separated checks value (may be empty or a subset).
   *
   * @return string[]
   *   The categories to run, in canonical order. Falls back to all categories
   *   when nothing valid was requested.
   */
  protected function resolveChecks(string $raw): array {
    $raw = strtolower(trim($raw));
    if ($raw === '' || $raw === 'all') {
      return self::CHECKS;
    }
    $wanted = [];
    foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $token) {
      if (in_array($token, self::CHECKS, TRUE)) {
        $wanted[$token] = $token;
      }
    }
    if (!$wanted) {
      return self::CHECKS;
    }
    return array_values(array_filter(self::CHECKS, static fn(string $c): bool => isset($wanted[$c])));
  }

  /**
   * Resolves the page set from the "pages" value, or all pages capped.
   *
   * When "pages" is given, each comma-separated token is resolved (by id or by
   * exact title) and unmatched tokens are collected. When it is empty, every
   * canvas_page id is loaded, capped at PAGE_CAP.
   *
   * @param string $raw
   *   The raw comma-separated "pages" value.
   *
   * @return array{0:\Drupal\Core\Entity\EntityInterface[],1:string[],2:bool,3:int}
   *   [resolved pages, unresolved tokens, whether the cap was applied, total
   *   available page count].
   */
  protected function resolvePages(string $raw): array {
    $storage = $this->entityTypeManager->getStorage('canvas_page');

    if ($raw !== '') {
      $tokens = array_filter(array_map('trim', explode(',', $raw)), static fn(string $t): bool => $t !== '');
      $pages = [];
      $unresolved = [];
      $seen = [];
      foreach ($tokens as $token) {
        $page = $this->analyzer->loadPage($token);
        if (!$page) {
          $unresolved[] = $token;
          continue;
        }
        $id = (string) $page->id();
        if (!isset($seen[$id])) {
          $seen[$id] = TRUE;
          $pages[] = $page;
        }
      }
      return [$pages, $unresolved, FALSE, count($pages)];
    }

    // No explicit list: audit all pages, capped.
    $ids = $storage->getQuery()->accessCheck(TRUE)->sort('id')->execute();
    $total = count($ids);
    $cap_applied = $total > self::PAGE_CAP;
    if ($cap_applied) {
      $ids = array_slice($ids, 0, self::PAGE_CAP);
    }
    $pages = $ids ? array_values($storage->loadMultiple($ids)) : [];
    return [$pages, [], $cap_applied, $total];
  }

  /**
   * Audits a single page deterministically into a compact row.
   *
   * @param \Drupal\Core\Entity\EntityInterface $page
   *   The canvas_page entity.
   * @param string[] $checks
   *   The active check categories.
   *
   * @return array{page_id:int,title:string,images_missing_alt:int,heading_issues:int,generic_links:int,needs_attention:bool}
   *   The per-page row.
   */
  protected function auditPage($page, array $checks): array {
    $summary = $this->analyzer->summary($page);
    $active = array_flip($checks);

    $images_missing_alt = isset($active['accessibility'])
      ? $this->countImagesMissingAlt($summary)
      : 0;
    $heading_issues = isset($active['seo']) || isset($active['accessibility'])
      ? $this->countHeadingIssues($summary)
      : 0;
    $generic_links = isset($active['links']) || isset($active['accessibility'])
      ? $this->countGenericLinks($summary)
      : 0;

    $needs_attention = ($images_missing_alt + $heading_issues + $generic_links) > 0;

    return [
      'page_id' => (int) ($summary['page_id'] ?? $page->id()),
      'title' => (string) ($summary['title'] ?? $page->label()),
      'has_title' => trim((string) ($summary['title'] ?? '')) !== '',
      'images_missing_alt' => $images_missing_alt,
      'heading_issues' => $heading_issues,
      'generic_links' => $generic_links,
      'needs_attention' => $needs_attention,
    ];
  }

  /**
   * Counts images that are missing alt text.
   *
   * @param array $summary
   *   The page summary.
   *
   * @return int
   *   The count of images with a truthy missing_alt flag.
   */
  protected function countImagesMissingAlt(array $summary): int {
    $count = 0;
    foreach (($summary['images'] ?? []) as $img) {
      if (!empty($img['missing_alt'])) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * Counts heading-hierarchy issues: missing H1, multiple H1s, skipped levels.
   *
   * Adds +1 for a missing H1, +1 for more than one H1, and +1 for every place
   * the heading level jumps by more than one between consecutive headings.
   *
   * @param array $summary
   *   The page summary.
   *
   * @return int
   *   The total heading issue count.
   */
  protected function countHeadingIssues(array $summary): int {
    $headings = $summary['headings'] ?? [];
    $issues = 0;

    $h1_count = 0;
    foreach ($headings as $h) {
      if ((int) ($h['level'] ?? 0) === 1) {
        $h1_count++;
      }
    }
    if ($h1_count === 0) {
      $issues++;
    }
    elseif ($h1_count > 1) {
      $issues++;
    }

    // Skipped levels between consecutive headings (e.g. H2 -> H4).
    $previous = NULL;
    foreach ($headings as $h) {
      $level = (int) ($h['level'] ?? 0);
      if ($level < 1) {
        continue;
      }
      if ($previous !== NULL && $level > $previous + 1) {
        $issues++;
      }
      $previous = $level;
    }

    return $issues;
  }

  /**
   * Counts links with empty or generic anchor text.
   *
   * @param array $summary
   *   The page summary.
   *
   * @return int
   *   The count of links whose (lower-cased, trimmed) text is empty or generic.
   */
  protected function countGenericLinks(array $summary): int {
    $count = 0;
    foreach (($summary['links'] ?? []) as $link) {
      $text = strtolower(trim((string) ($link['text'] ?? '')));
      if (in_array($text, self::GENERIC_LINK_TEXT, TRUE)) {
        $count++;
      }
    }
    return $count;
  }

}
