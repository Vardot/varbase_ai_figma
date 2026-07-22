<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma\Plugin\AiFunctionCall;

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
 * AI Agent tool: one consolidated "page intelligence" audit of a Canvas page.
 *
 * Read-only. Loads an existing canvas_page through the page analyzer and runs a
 * set of DETERMINISTIC checks over its structured summary (headings, images,
 * links, inline colours, text and component inputs), then returns findings
 * grouped by category, each with a severity (critical | warning | suggestion),
 * a plain-language explanation and a proposed fix. With checks=all it
 * doubles as the consolidated review panel - every category in one report,
 * counted by severity.
 *
 * This tool only reports; it never mutates the page. Apply the fixes it
 * suggests with the targeted edit/improve tools (ai_figma:page_edit and the
 * content/SEO improvement tools).
 *
 * Covers PRD stories 2.1 (spacing), 2.7 (missing sections), 2.9/2.10 (SEO),
 * 2.11 (heading hierarchy), 2.12/2.13 (accessibility incl. images missing alt -
 * detection only), 2.14 (colour contrast), 2.15/2.16 (heavy/oversized images),
 * 2.19 (internal links) and 2.20 (consolidated review panel).
 */
#[FunctionCall(
  id: 'varbase_ai_figma:audit_page',
  function_name: 'varbase_figma_audit_page',
  name: 'Check a page for problems',
  description: 'Audits an existing Drupal Canvas page across categories (accessibility, headings, contrast, SEO, spacing, images/performance, internal links, missing sections) and returns grouped findings, each with a severity (critical | warning | suggestion), a plain-language explanation, and a proposed fix; with checks=all it is a consolidated review of all categories. Read-only - it reports; apply fixes with the targeted edit/improve tools.',
  group: 'information_tools',
  module_dependencies: ['varbase_ai_figma', 'ai_figma'],
  context_definitions: [
    'page' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Page'),
      description: new TranslatableMarkup('The Canvas page to audit: its numeric canvas_page id, or its exact title.'),
      required: TRUE,
    ),
    'checks' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Checks'),
      description: new TranslatableMarkup('Optional comma-separated subset of: accessibility, headings, contrast, seo, spacing, images, links, sections. Defaults to all (a consolidated review of every category).'),
      required: FALSE,
    ),
  ],
)]
class AuditPage extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The categories this tool can audit.
   */
  protected const CHECKS = [
    'accessibility',
    'headings',
    'contrast',
    'seo',
    'spacing',
    'images',
    'links',
    'sections',
  ];

  /**
   * The minimum WCAG AA contrast ratio for normal-size text.
   */
  protected const CONTRAST_MIN = 4.5;

  /**
   * Anchor texts that fail "links have a clear purpose" (WCAG 2.4.4).
   */
  protected const GENERIC_LINK_TEXT = [
    'click here',
    'read more',
    'here',
    'link',
    'more',
    'learn more',
    'this',
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
      && !$this->currentUser->hasPermission('use ai figma design context')
    ) {
      throw new \Exception('You do not have permission to audit Canvas pages.');
    }

    $page_ref = trim((string) $this->getContextValue('page'));
    if ($page_ref === '') {
      $this->result = 'A "page" (canvas_page id or exact title) is required.';
      return;
    }

    // Resolve the requested checks. Empty / "all" / unknown values fall back to
    // the full set, so the tool always produces a useful report.
    $requested = $this->resolveChecks((string) ($this->getContextValue('checks') ?? ''));

    $page = $this->analyzer->loadPage($page_ref);
    if (!$page) {
      $this->result = sprintf('No Canvas page found for "%s". Pass a numeric canvas_page id or the page\'s exact title.', $page_ref);
      return;
    }

    $summary = $this->analyzer->summary($page);

    // The heading result is computed once: several checks (accessibility, seo)
    // reuse it, so it is not re-derived per category.
    $heading_findings = $this->checkHeadings($summary);

    $findings = [];
    foreach ($requested as $check) {
      $rows = match ($check) {
        'headings' => $heading_findings,
        'accessibility' => $this->checkAccessibility($summary, $heading_findings),
        'contrast' => $this->checkContrast($summary),
        'seo' => $this->checkSeo($summary, $heading_findings)['findings'],
        'spacing' => $this->checkSpacing($page),
        'images' => $this->checkImages($summary),
        'links' => $this->checkLinks($summary),
        'sections' => $this->checkSections($summary),
        default => [],
      };
      if ($rows) {
        $findings[$check] = $rows;
      }
    }

    // The SEO score is always reported (cheap, and a headline figure for the
    // review panel), even when the seo category was not explicitly requested.
    $seo = $this->checkSeo($summary, $heading_findings);

    $out = [
      'page' => [
        'id' => $summary['page_id'],
        'title' => $summary['title'],
      ],
      'checks_run' => array_values($requested),
      'score' => [
        'seo' => $seo['score'],
      ],
      'summary' => $this->severitySummary($findings),
      'findings' => $findings ?: [
        'ok' => [
          [
            'severity' => 'suggestion',
            'message' => 'No issues detected for the requested checks.',
            'fix' => 'Nothing to fix.',
          ],
        ],
      ],
      'note' => 'Read-only audit. Apply fixes with the targeted edit/improve tools (e.g. ai_figma:page_edit). Only inline colours are checked for contrast; computed CSS variables are not resolvable server-side. Image byte sizes are not available from HTML, so performance findings are heuristic.',
    ];

    $this->result = Yaml::dump($out, 8, 2);
    $this->loggerFactory->get('ai_figma')->info('audit_page ran: @s', ['@s' => sprintf('page %s, %d finding(s)', $summary['page_id'] ?? '?', $this->severitySummary($findings)['total'])]);
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
   *   The comma-separated checks value (may be empty, "all", or a subset).
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
    // Preserve canonical ordering regardless of input order.
    return array_values(array_filter(self::CHECKS, static fn(string $c): bool => isset($wanted[$c])));
  }

  /**
   * Story 2.11 - heading hierarchy.
   *
   * Flags a missing H1, multiple H1s and skipped levels (e.g. H2 -> H4), and
   * includes the current outline plus a corrected-level proposal.
   *
   * @param array $summary
   *   The page summary from the analyzer.
   *
   * @return array[]
   *   Finding rows.
   */
  protected function checkHeadings(array $summary): array {
    $headings = $summary['headings'] ?? [];
    $findings = [];

    $outline = [];
    foreach ($headings as $h) {
      $outline[] = sprintf('H%d: %s', (int) $h['level'], (string) $h['text']);
    }
    $outline_text = $outline ? implode(' | ', $outline) : '(no headings found)';

    $h1_count = 0;
    foreach ($headings as $h) {
      if ((int) $h['level'] === 1) {
        $h1_count++;
      }
    }

    if ($headings === []) {
      $findings[] = [
        'severity' => 'critical',
        'wcag' => 'WCAG 1.3.1',
        'message' => 'The page has no headings at all.',
        'fix' => 'Add a single H1 page title and structure the content with H2/H3 sub-headings.',
      ];
      return $findings;
    }

    if ($h1_count === 0) {
      $findings[] = [
        'severity' => 'critical',
        'wcag' => 'WCAG 1.3.1',
        'message' => sprintf('No H1 found. Current outline: %s', $outline_text),
        'fix' => 'Promote the main page title to an H1 so the document has exactly one top-level heading.',
      ];
    }
    elseif ($h1_count > 1) {
      $findings[] = [
        'severity' => 'critical',
        'wcag' => 'WCAG 1.3.1',
        'message' => sprintf('%d H1 headings found - a page should have exactly one. Outline: %s', $h1_count, $outline_text),
        'fix' => 'Keep one H1 (the page title) and demote the others to H2/H3 as appropriate.',
      ];
    }

    // Skipped levels: a heading whose level jumps more than one deeper than the
    // previous heading (e.g. H2 -> H4). Propose the corrected sequence.
    $previous = NULL;
    $proposed = [];
    foreach ($headings as $h) {
      $level = (int) $h['level'];
      if ($previous !== NULL && $level > $previous + 1) {
        $corrected = $previous + 1;
        $findings[] = [
          'severity' => 'warning',
          'wcag' => 'WCAG 1.3.1',
          'message' => sprintf('Heading level skips from H%d to H%d at "%s".', $previous, $level, (string) $h['text']),
          'fix' => sprintf('Change "%s" from H%d to H%d so levels increase by one.', (string) $h['text'], $level, $corrected),
        ];
        $proposed[] = sprintf('"%s": H%d -> H%d', (string) $h['text'], $level, $corrected);
        $previous = $corrected;
      }
      else {
        $previous = $level;
      }
    }

    if ($proposed) {
      $findings[] = [
        'severity' => 'suggestion',
        'message' => sprintf('Proposed corrected levels: %s', implode('; ', $proposed)),
        'fix' => 'Apply the proposed heading levels above with the edit tool.',
      ];
    }

    return $findings;
  }

  /**
   * Stories 2.12 / 2.13 - accessibility (WCAG-tagged).
   *
   * Reports images missing alt text (1.1.1), the heading findings (1.3.1), and
   * links with empty/generic anchor text (2.4.4). Detection only.
   *
   * @param array $summary
   *   The page summary.
   * @param array[] $heading_findings
   *   The already-computed heading findings, folded in here.
   *
   * @return array[]
   *   Finding rows.
   */
  protected function checkAccessibility(array $summary, array $heading_findings): array {
    $findings = [];

    foreach (($summary['images'] ?? []) as $img) {
      if (!empty($img['missing_alt'])) {
        $src = (string) ($img['src'] ?? '');
        $findings[] = [
          'severity' => 'critical',
          'wcag' => 'WCAG 1.1.1',
          'message' => sprintf('Image is missing alt text%s.', $src !== '' ? ' (' . $this->shortSrc($src) . ')' : ''),
          'fix' => 'Add a concise, descriptive alt attribute (or alt="" if the image is purely decorative).',
        ];
      }
    }

    // Fold in the heading hierarchy findings (they are accessibility issues).
    foreach ($heading_findings as $hf) {
      $findings[] = $hf + ['wcag' => 'WCAG 1.3.1'];
    }

    foreach (($summary['links'] ?? []) as $link) {
      $text = strtolower(trim((string) ($link['text'] ?? '')));
      $href = (string) ($link['href'] ?? '');
      if ($text === '' || in_array($text, self::GENERIC_LINK_TEXT, TRUE)) {
        $findings[] = [
          'severity' => 'warning',
          'wcag' => 'WCAG 2.4.4',
          'message' => sprintf('Link has %s anchor text%s.', $text === '' ? 'empty' : 'generic', $href !== '' ? ' (-> ' . $this->shortSrc($href) . ')' : ''),
          'fix' => 'Replace with descriptive link text that makes the destination clear out of context.',
        ];
      }
    }

    return $findings;
  }

  /**
   * Story 2.14 - colour contrast (inline colours only).
   *
   * For every inline foreground/background pair where BOTH values are concrete
   * hex/rgb, computes the WCAG contrast ratio and flags pairs below 4.5:1.
   *
   * @param array $summary
   *   The page summary.
   *
   * @return array[]
   *   Finding rows.
   */
  protected function checkContrast(array $summary): array {
    $findings = [];
    $seen = [];
    foreach (($summary['colors'] ?? []) as $pair) {
      $fg = $this->parseColor((string) ($pair['color'] ?? ''));
      $bg = $this->parseColor((string) ($pair['background'] ?? ''));
      if ($fg === NULL || $bg === NULL) {
        // One side is a CSS variable / keyword / gradient: cannot be checked.
        continue;
      }
      $key = implode('|', $fg) . '::' . implode('|', $bg);
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;

      $ratio = $this->contrastRatio($fg, $bg);
      if ($ratio < self::CONTRAST_MIN) {
        $findings[] = [
          'severity' => 'warning',
          'wcag' => 'WCAG 1.4.3',
          'message' => sprintf(
            'Low contrast: %s on %s has a ratio of %.2f:1 (needs at least %.1f:1 for normal text).',
            (string) $pair['color'],
            (string) $pair['background'],
            $ratio,
            self::CONTRAST_MIN
          ),
          'fix' => 'Darken/lighten the foreground or background until the ratio reaches 4.5:1 (3:1 for large text).',
        ];
      }
    }

    if ($findings === [] && ($summary['colors'] ?? []) === []) {
      $findings[] = [
        'severity' => 'suggestion',
        'message' => 'No inline colour pairs found to check. Computed CSS variables are not resolvable server-side.',
        'fix' => 'Verify contrast visually or with a browser axe-core run for theme/utility-class colours.',
      ];
    }

    return $findings;
  }

  /**
   * Stories 2.9 / 2.10 - SEO.
   *
   * Runs a handful of on-page checks and derives a simple 0-100 score from the
   * number that pass.
   *
   * @param array $summary
   *   The page summary.
   * @param array[] $heading_findings
   *   The already-computed heading findings (reused for the hierarchy check).
   *
   * @return array{score:int,findings:array[]}
   *   The SEO score and the SEO finding rows.
   */
  protected function checkSeo(array $summary, array $heading_findings): array {
    $headings = $summary['headings'] ?? [];
    $h1_count = 0;
    foreach ($headings as $h) {
      if ((int) $h['level'] === 1) {
        $h1_count++;
      }
    }

    $checks = [
      'single_h1' => $h1_count === 1,
      'title_set' => trim((string) ($summary['title'] ?? '')) !== '',
      'heading_order_ok' => $heading_findings === [],
      'has_body_text' => ($summary['texts'] ?? []) !== [],
      'images_have_alt' => $this->allImagesHaveAlt($summary),
    ];

    $passing = count(array_filter($checks));
    $total = count($checks);
    $score = $total > 0 ? (int) round(($passing / $total) * 100) : 0;

    $findings = [];
    if (!$checks['single_h1']) {
      $findings[] = [
        'severity' => 'warning',
        'message' => $h1_count === 0 ? 'No H1: search engines rely on a single, clear H1.' : sprintf('%d H1 headings: search engines expect exactly one.', $h1_count),
        'fix' => 'Set a single descriptive H1 that summarises the page topic.',
      ];
    }
    if (!$checks['title_set']) {
      $findings[] = [
        'severity' => 'warning',
        'message' => 'The page title is empty.',
        'fix' => 'Give the page a concise, keyword-relevant title.',
      ];
    }
    if (!$checks['heading_order_ok']) {
      $findings[] = [
        'severity' => 'suggestion',
        'message' => 'Heading order issues were found (see the headings category).',
        'fix' => 'Fix the heading hierarchy so the document outline reads logically.',
      ];
    }
    if (!$checks['has_body_text']) {
      $findings[] = [
        'severity' => 'warning',
        'message' => 'Little or no body copy detected - thin content ranks poorly.',
        'fix' => 'Add meaningful body text that describes the page subject.',
      ];
    }
    if (!$checks['images_have_alt']) {
      $findings[] = [
        'severity' => 'suggestion',
        'message' => 'Some images are missing alt text (also an accessibility issue).',
        'fix' => 'Add descriptive alt text to every meaningful image.',
      ];
    }

    // A meta description is not part of the canvas_page body, so it is always
    // surfaced as a reminder pointing at the dedicated tool.
    $findings[] = [
      'severity' => 'suggestion',
      'message' => 'Confirm the page has a meta description for search snippets.',
      'fix' => 'Set a 150-160 character meta description (use the meta_description tool / metatag fields).',
    ];

    return ['score' => $score, 'findings' => $findings];
  }

  /**
   * Story 2.1 - spacing (heuristic).
   *
   * Scans component inputs/props for spacing utilities and flags adjacent
   * sections that carry no vertical spacing, and any that carry very large
   * values. Best-effort and clearly labelled.
   *
   * @param \Drupal\Core\Entity\EntityInterface $page
   *   The canvas_page entity.
   *
   * @return array[]
   *   Finding rows.
   */
  protected function checkSpacing($page): array {
    $findings = [];
    $rows = $this->analyzer->tree($page);

    // Identify the top-level "section"-like components in document order.
    $sections = [];
    foreach ($rows as $row) {
      $bare = strtolower((string) ($row['bare'] ?? ''));
      $is_top = empty($row['parent_uuid']);
      if ($is_top && (str_contains($bare, 'section') || str_contains($bare, 'row') || str_contains($bare, 'hero') || str_contains($bare, 'container'))) {
        $sections[] = $row;
      }
    }

    foreach ($sections as $row) {
      $haystack = $this->flattenInputs($row['inputs'] ?? []);
      $has_spacing = (bool) preg_match('/\b[pm][tbxy]?-\d|\bpadding\b|\bmargin\b/i', $haystack);
      $label = $this->componentLabel($row);

      if (!$has_spacing) {
        $findings[] = [
          'severity' => 'suggestion',
          'message' => sprintf('Heuristic: section "%s" has no vertical spacing utility (py-*/my-*/padding/margin).', $label),
          'fix' => 'Consider adding vertical spacing (e.g. a py-* / my-* utility) so sections breathe.',
        ];
      }

      if (preg_match('/\b[pm][tbxy]?-([6-9]|1[0-9])\b/i', $haystack)) {
        $findings[] = [
          'severity' => 'suggestion',
          'message' => sprintf('Heuristic: section "%s" uses a very large spacing value.', $label),
          'fix' => 'Check the large spacing value is intentional and consistent with the design system.',
        ];
      }
    }

    if ($findings === []) {
      $findings[] = [
        'severity' => 'suggestion',
        'message' => 'Heuristic: no obvious spacing problems detected on top-level sections.',
        'fix' => 'Spacing analysis is best-effort from component props; confirm visually.',
      ];
    }

    return $findings;
  }

  /**
   * Stories 2.15 / 2.16 - heavy/oversized images (heuristic).
   *
   * Byte sizes are not available from rendered HTML, so this flags external
   * demo/stock sources and raw (non-image-style) file URLs that are likely
   * un-optimised, and reports the page image count.
   *
   * @param array $summary
   *   The page summary.
   *
   * @return array[]
   *   Finding rows.
   */
  protected function checkImages(array $summary): array {
    $images = $summary['images'] ?? [];
    $findings = [];

    $findings[] = [
      'severity' => 'suggestion',
      'message' => sprintf('The page has %d image(s).', count($images)),
      'fix' => 'Keep meaningful images optimised and served responsively.',
    ];

    foreach ($images as $img) {
      $src = (string) ($img['src'] ?? '');
      if ($src === '') {
        continue;
      }
      $short = $this->shortSrc($src);

      if (preg_match('#varbase-assets|varbase_media_demo#i', $src)) {
        $findings[] = [
          'severity' => 'suggestion',
          'message' => sprintf('Image uses a demo/stock source (%s).', $short),
          'fix' => 'Replace demo/stock imagery with real, optimised media before launch.',
        ];
      }

      // A raw file path with no image-style derivative segment is likely the
      // full-size original (Drupal image styles live under styles/<name>/).
      $is_files = str_contains($src, '/sites/default/files/');
      $is_derivative = (bool) preg_match('#/files/styles/[^/]+/#', $src);
      if ($is_files && !$is_derivative) {
        $findings[] = [
          'severity' => 'suggestion',
          'message' => sprintf('Image appears to be served full-size, not via an image style (%s).', $short),
          'fix' => 'Serve the image through an image style / responsive image style to reduce its weight.',
        ];
      }
    }

    return $findings;
  }

  /**
   * Story 2.19 - internal links.
   *
   * Lists internal links, flags empty/generic anchor text and obviously broken
   * hrefs ('#', empty), and suggests adding internal links when the body is
   * long but under-linked.
   *
   * @param array $summary
   *   The page summary.
   *
   * @return array[]
   *   Finding rows.
   */
  protected function checkLinks(array $summary): array {
    $links = $summary['links'] ?? [];
    $findings = [];

    $internal = array_values(array_filter($links, static fn(array $l): bool => !empty($l['internal'])));
    $internal_list = [];
    foreach ($internal as $l) {
      $internal_list[] = sprintf('%s -> %s', ((string) $l['text']) !== '' ? (string) $l['text'] : '(no text)', (string) $l['href']);
    }
    $findings[] = [
      'severity' => 'suggestion',
      'message' => sprintf('%d internal link(s)%s.', count($internal), $internal_list ? ': ' . implode(', ', array_slice($internal_list, 0, 10)) : ''),
      'fix' => 'Internal links help navigation and SEO; keep them descriptive and relevant.',
    ];

    foreach ($links as $l) {
      $href = trim((string) ($l['href'] ?? ''));
      $text = strtolower(trim((string) ($l['text'] ?? '')));
      if ($href === '' || $href === '#') {
        $findings[] = [
          'severity' => 'warning',
          'message' => sprintf('Link with no real destination (href "%s").', $href),
          'fix' => 'Point the link at a real URL, or remove it.',
        ];
      }
      if ($text === '' || in_array($text, self::GENERIC_LINK_TEXT, TRUE)) {
        $findings[] = [
          'severity' => 'warning',
          'message' => sprintf('Link with %s anchor text%s.', $text === '' ? 'empty' : 'generic', $href !== '' ? ' (-> ' . $this->shortSrc($href) . ')' : ''),
          'fix' => 'Use descriptive anchor text that states where the link goes.',
        ];
      }
    }

    // Long body but few internal links: suggest cross-linking.
    $body_chars = 0;
    foreach (($summary['texts'] ?? []) as $t) {
      $body_chars += mb_strlen((string) ($t['text'] ?? ''));
    }
    if ($body_chars > 600 && count($internal) < 2) {
      $findings[] = [
        'severity' => 'suggestion',
        'message' => 'The page has substantial body copy but very few internal links.',
        'fix' => 'Add a couple of relevant internal links to related pages to aid navigation and SEO.',
      ];
    }

    return $findings;
  }

  /**
   * Story 2.7 - missing sections (heuristic).
   *
   * Loosely classifies the page "kind" from component names and suggests
   * commonly-missing sections, ranked by likely impact. Heuristic and labelled.
   *
   * @param array $summary
   *   The page summary.
   *
   * @return array[]
   *   Finding rows.
   */
  protected function checkSections(array $summary): array {
    $findings = [];

    $names = [];
    foreach (($summary['components'] ?? []) as $c) {
      $names[] = strtolower((string) ($c['bare'] ?? ''));
      $names[] = strtolower((string) ($c['label'] ?? ''));
    }
    $blob = implode(' ', $names);

    $has = static fn(string $needle): bool => str_contains($blob, $needle);

    // Loose page "kind" detection (best-effort).
    $kind = 'generic';
    if ($has('contact') || $has('webform') || $has('form')) {
      $kind = 'contact';
    }
    elseif ($has('blog') || $has('article') || $has('post')) {
      $kind = 'blog';
    }
    elseif ($has('hero') || $has('cta') || $has('feature') || $has('pricing')) {
      $kind = 'landing';
    }

    // Likely-missing sections, ranked (first = highest likely impact).
    $candidates = [];
    if (!$has('cta') && !$has('call') && !$has('button')) {
      $candidates[] = [
        'no clear call-to-action (CTA) section detected',
        'Add a CTA section so visitors know the next step.',
      ];
    }
    if (!$has('testimonial') && !$has('review') && !$has('logo') && !$has('proof')) {
      $candidates[] = [
        'no social-proof / testimonials section detected',
        'Add testimonials, reviews or client logos to build trust.',
      ];
    }
    if (!$has('hero') && $kind === 'landing') {
      $candidates[] = [
        'no hero/banner section detected for a landing page',
        'Add a hero section to frame the page\'s main message.',
      ];
    }
    if ($kind === 'contact' && !$has('map') && !$has('address') && !$has('location')) {
      $candidates[] = ['contact page has no address/map section', 'Add an address or map block alongside the form.'];
    }
    if (!$has('faq') && !$has('accordion') && !$has('question')) {
      $candidates[] = ['no FAQ section detected', 'Consider an FAQ/accordion to answer common questions.'];
    }
    if (!$has('footer') && !$has('newsletter') && !$has('subscribe')) {
      $candidates[] = ['no newsletter/subscribe prompt detected', 'A subscribe prompt can capture interested visitors.'];
    }

    foreach ($candidates as $c) {
      $findings[] = [
        'severity' => 'suggestion',
        'message' => sprintf('Heuristic (%s page): %s.', $kind, $c[0]),
        'fix' => $c[1],
      ];
    }

    if ($findings === []) {
      $findings[] = [
        'severity' => 'suggestion',
        'message' => sprintf('Heuristic (%s page): no obviously-missing common sections detected.', $kind),
        'fix' => 'Section analysis is best-effort from component names; review against the page goal.',
      ];
    }

    return $findings;
  }

  /**
   * Counts findings by severity for the review-panel summary line.
   *
   * @param array<string,array[]> $grouped
   *   Findings grouped by category.
   *
   * @return array{critical:int,warning:int,suggestion:int,total:int}
   *   The per-severity counts.
   */
  protected function severitySummary(array $grouped): array {
    $counts = ['critical' => 0, 'warning' => 0, 'suggestion' => 0];
    foreach ($grouped as $rows) {
      foreach ($rows as $row) {
        $sev = (string) ($row['severity'] ?? 'suggestion');
        if (isset($counts[$sev])) {
          $counts[$sev]++;
        }
      }
    }
    $counts['total'] = array_sum($counts);
    return $counts;
  }

  /**
   * Whether every image on the page has non-empty alt text.
   *
   * @param array $summary
   *   The page summary.
   *
   * @return bool
   *   TRUE when no image is missing alt text.
   */
  protected function allImagesHaveAlt(array $summary): bool {
    foreach (($summary['images'] ?? []) as $img) {
      if (!empty($img['missing_alt'])) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Flattens an inputs array to a searchable string (for the spacing scan).
   *
   * @param array $inputs
   *   A component's decoded inputs.
   *
   * @return string
   *   A space-joined string of all scalar leaf values and keys.
   */
  protected function flattenInputs(array $inputs): string {
    $parts = [];
    array_walk_recursive($inputs, static function ($value, $key) use (&$parts): void {
      $parts[] = (string) $key;
      if (is_scalar($value)) {
        $parts[] = (string) $value;
      }
    });
    return implode(' ', $parts);
  }

  /**
   * A human label for a component row (label, else machine name, else uuid).
   *
   * @param array $row
   *   A normalised component row from the analyzer.
   *
   * @return string
   *   The label.
   */
  protected function componentLabel(array $row): string {
    $label = trim((string) ($row['label'] ?? ''));
    if ($label !== '') {
      return $label;
    }
    $bare = trim((string) ($row['bare'] ?? ''));
    if ($bare !== '') {
      return $bare;
    }
    return (string) ($row['uuid'] ?? 'component');
  }

  /**
   * Shortens a src/href for readable output.
   *
   * @param string $src
   *   The URL or path.
   *
   * @return string
   *   A trimmed representation (last path segment / first 80 chars).
   */
  protected function shortSrc(string $src): string {
    $src = trim($src);
    if (mb_strlen($src) <= 80) {
      return $src;
    }
    $tail = basename(parse_url($src, PHP_URL_PATH) ?: $src);
    return $tail !== '' ? '…/' . $tail : mb_substr($src, 0, 80) . '…';
  }

  /**
   * Parses a CSS colour (#rgb, #rrggbb, rgb()/rgba()) into [r, g, b] 0-255.
   *
   * Keywords, CSS variables, gradients and hsl() are intentionally NOT parsed
   * (they cannot be resolved deterministically server-side) and return NULL.
   *
   * @param string $value
   *   The raw colour string.
   *
   * @return int[]|null
   *   [r, g, b] with each 0-255, or NULL when the value is not a concrete
   *   hex/rgb colour.
   */
  protected function parseColor(string $value): ?array {
    $value = strtolower(trim($value));
    if ($value === '') {
      return NULL;
    }

    // #rgb / #rgba shorthand.
    if (preg_match('/^#([0-9a-f]{3})([0-9a-f])?$/', $value, $m)) {
      $hex = $m[1];
      return [
        hexdec($hex[0] . $hex[0]),
        hexdec($hex[1] . $hex[1]),
        hexdec($hex[2] . $hex[2]),
      ];
    }

    // #rrggbb / #rrggbbaa.
    if (preg_match('/^#([0-9a-f]{6})([0-9a-f]{2})?$/', $value, $m)) {
      $hex = $m[1];
      return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
      ];
    }

    // rgb()/rgba() with integer or percentage channels.
    if (preg_match('/^rgba?\(\s*([^)]+)\)$/', $value, $m)) {
      $parts = preg_split('/[\s,\/]+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
      if (count($parts) < 3) {
        return NULL;
      }
      $rgb = [];
      for ($i = 0; $i < 3; $i++) {
        $part = $parts[$i];
        if (str_ends_with($part, '%')) {
          $rgb[] = (int) round(((float) rtrim($part, '%') / 100) * 255);
        }
        elseif (is_numeric($part)) {
          $rgb[] = (int) round((float) $part);
        }
        else {
          return NULL;
        }
      }
      return [
        max(0, min(255, $rgb[0])),
        max(0, min(255, $rgb[1])),
        max(0, min(255, $rgb[2])),
      ];
    }

    return NULL;
  }

  /**
   * Computes the WCAG contrast ratio between two parsed colours.
   *
   * @param int[] $fg
   *   The foreground [r, g, b] 0-255.
   * @param int[] $bg
   *   The background [r, g, b] 0-255.
   *
   * @return float
   *   The contrast ratio (1.0 - 21.0), where higher is more contrast.
   */
  protected function contrastRatio(array $fg, array $bg): float {
    $l1 = $this->relativeLuminance($fg);
    $l2 = $this->relativeLuminance($bg);
    $lighter = max($l1, $l2);
    $darker = min($l1, $l2);
    return ($lighter + 0.05) / ($darker + 0.05);
  }

  /**
   * Computes the WCAG relative luminance of an [r, g, b] colour.
   *
   * @param int[] $rgb
   *   The colour channels, each 0-255.
   *
   * @return float
   *   The relative luminance (0.0 - 1.0).
   */
  protected function relativeLuminance(array $rgb): float {
    $channels = [];
    foreach ($rgb as $value) {
      $c = max(0, min(255, (int) $value)) / 255;
      $channels[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }
    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
  }

}
