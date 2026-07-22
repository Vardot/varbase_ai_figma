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
use Drupal\varbase_ai_figma\AiAssistant;
use Drupal\varbase_ai_figma\CanvasPageAnalyzer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * AI Agent tool: suggest A/B test variants for a Canvas page's key elements.
 *
 * Read-only. Loads an existing canvas_page through the page analyzer, picks the
 * high-impact, conversion-relevant elements (the hero headline, the primary
 * call-to-action label and the above-the-fold copy), then asks the site's
 * default AI chat provider for one or two alternative versions of each. Every
 * variant comes with a short hypothesis (why it might perform better) and the
 * metric it aims to move (click-through rate, scroll depth, form submissions).
 *
 * The tool only suggests; it never edits the page and it does not create a live
 * experiment. Wiring the chosen variants into an actual A/B / experimentation
 * module is explicitly out of scope and is noted in the output so the agent
 * knows the next step is to push them to a connected testing tool.
 *
 * Covers PRD story 2.17 (suggest A/B test variants for high-impact elements).
 */
#[FunctionCall(
  id: 'varbase_ai_figma:ab_variants',
  function_name: 'varbase_figma_ab_variants',
  name: 'Suggest alternative wording to test',
  description: 'Suggests A/B test variants for the high-impact elements of a Drupal Canvas page - the primary call-to-action (CTA), the hero headline and the above-the-fold copy. For each identified element it generates 1-2 alternative versions, each with a brief hypothesis (why it might perform better) and the metric it aims to improve (click-through rate, scroll depth, form submissions). Read-only suggestions only: the tool never edits the page, and pushing the variants to an A/B testing / experimentation module to actually run the test is out of scope (noted in the output). The page is identified by its canvas_page id or exact title; "element" optionally focuses on one of hero | cta | headline, otherwise the high-impact elements are auto-picked.',
  group: 'information_tools',
  module_dependencies: ['varbase_ai_figma', 'ai_figma'],
  context_definitions: [
    'page' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Page'),
      description: new TranslatableMarkup('The Canvas page to generate variants for: its numeric canvas_page id, or its exact title.'),
      required: TRUE,
    ),
    'element' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Element'),
      description: new TranslatableMarkup('Optional: focus on a single high-impact element - hero | cta | headline. When omitted, the tool auto-picks the high-impact elements (hero headline, primary CTA, above-the-fold copy).'),
      required: FALSE,
    ),
  ],
)]
class AbVariants extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The element focuses this tool accepts (besides the default auto-pick).
   */
  protected const ELEMENTS = ['hero', 'cta', 'headline'];

  /**
   * Machine-name / label fragments that mark a CTA-bearing component.
   */
  protected const CTA_COMPONENT_HINTS = ['cta', 'button', 'hero', 'call', 'action'];

  /**
   * The longest a string can be and still read as a CTA button label.
   */
  protected const CTA_MAX_LENGTH = 40;

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
   * The AI assistant (thin wrapper over the site's default chat provider).
   *
   * @var \Drupal\varbase_ai_figma\AiAssistant
   */
  protected AiAssistant $assistant;

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
    $instance->assistant = $container->get('varbase_ai_figma.assistant');
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
      throw new \Exception('You do not have permission to suggest A/B variants for Canvas pages.');
    }

    $page_ref = trim((string) $this->getContextValue('page'));
    if ($page_ref === '') {
      $this->result = 'A "page" (canvas_page id or exact title) is required.';
      return;
    }

    // An optional element focus. Unknown values fall back to auto-pick so the
    // tool always produces a useful suggestion.
    $focus = strtolower(trim((string) ($this->getContextValue('element') ?? '')));
    if ($focus !== '' && !in_array($focus, self::ELEMENTS, TRUE)) {
      $focus = '';
    }

    // 1. Load the page; refuse early when no AI chat provider is configured.
    $page = $this->analyzer->loadPage($page_ref);
    if (!$page) {
      $this->result = sprintf('No Canvas page found for "%s". Pass a numeric canvas_page id or the page\'s exact title.', $page_ref);
      return;
    }
    if (!$this->assistant->isAvailable()) {
      $this->result = 'No AI chat provider is configured, so A/B variants cannot be generated. Set a default chat provider at Admin → AI (Configuration → AI → Settings).';
      return;
    }

    // 2. Identify the high-impact candidate elements from the page summary.
    $summary = $this->analyzer->summary($page);
    $candidates = $this->identifyCandidates($summary, $focus);
    if (!$candidates) {
      $this->result = sprintf(
        'No high-impact elements (hero headline / primary CTA / above-the-fold copy) could be identified on "%s". Add a headline or a CTA, or pass a different "element" focus.',
        (string) ($summary['title'] ?? $page_ref)
      );
      return;
    }

    // 3. Ask the model for 1-2 variants per element, each with a hypothesis and
    // the metric it aims to improve.
    $system = $this->systemPrompt();
    $user = $this->userPrompt($summary, $candidates);
    $decoded = $this->assistant->askJson($system, $user);
    $variants = $this->normaliseVariants($decoded, $candidates);
    if (!$variants) {
      $this->result = sprintf(
        'The AI provider returned no usable A/B variants for "%s". Try again or focus on a single "element".',
        (string) ($summary['title'] ?? $page_ref)
      );
      return;
    }

    // 4. Build the YAML report: the identified high-impact elements, the
    // variants (hypothesis + metric), and a closing note that running the test
    // requires pushing these to a connected A/B / experimentation module.
    $out = [
      'page' => sprintf('%d (%s)', (int) ($summary['page_id'] ?? $page->id()), (string) ($summary['title'] ?? $page->label())),
      'high_impact_elements' => array_map(static fn(array $c): array => [
        'element' => $c['element'],
        'original' => $c['original'],
      ], $candidates),
      'variants' => $variants,
      'note' => 'Read-only suggestions. To actually run the test, the agent should push these variants to a connected A/B / experimentation module - that wiring is out of scope here and was not done.',
    ];
    $this->result = Yaml::dump($out, 6, 2);
    $this->loggerFactory->get('ai_figma')->info('ab_variants ran: @s', ['@s' => sprintf('page %d', (int) ($summary['page_id'] ?? $page->id()))]);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

  /**
   * Identifies the high-impact candidate elements on the page.
   *
   * From the analyzer summary it derives:
   * - "hero headline": the first heading, preferring the largest (lowest level)
   *   one near the top of the document (the hero/H1 line);
   * - "primary CTA": the first short text that reads like a button label and
   *   sits on a CTA/button/hero-named component (the above-the-fold action);
   * - "above-the-fold copy": the first substantial body paragraph.
   *
   * @param array $summary
   *   The page summary from the analyzer.
   * @param string $focus
   *   An optional single-element focus (hero | cta | headline), or '' to pick
   *   every high-impact element.
   *
   * @return array<int,array{element:string,original:string}>
   *   The identified candidates, in priority order. May be empty.
   */
  protected function identifyCandidates(array $summary, string $focus): array {
    $candidates = [];

    // Hero headline: the first/largest heading. "hero" and "headline" both map
    // to this element.
    if ($focus === '' || $focus === 'hero' || $focus === 'headline') {
      $headline = $this->pickHeadline($summary['headings'] ?? []);
      if ($headline !== '') {
        $candidates[] = ['element' => 'hero headline', 'original' => $headline];
      }
    }

    // Primary CTA: the first text that looks like a CTA label.
    if ($focus === '' || $focus === 'cta' || $focus === 'hero') {
      $cta = $this->pickCtaLabel($summary);
      if ($cta !== '') {
        $candidates[] = ['element' => 'primary CTA', 'original' => $cta];
      }
    }

    // Above-the-fold copy: the first substantial body paragraph. Only when no
    // single element was requested (it is the supporting copy, not a focus
    // option in its own right).
    if ($focus === '') {
      $copy = $this->pickAboveFoldCopy($summary, $candidates);
      if ($copy !== '') {
        $candidates[] = ['element' => 'above-the-fold copy', 'original' => $copy];
      }
    }

    return $candidates;
  }

  /**
   * Picks the hero headline: the first heading, preferring the largest level.
   *
   * @param array $headings
   *   The summary headings (rows of ['level' => int, 'text' => string]).
   *
   * @return string
   *   The headline text, or '' when there are no headings.
   */
  protected function pickHeadline(array $headings): string {
    $best = '';
    $best_level = PHP_INT_MAX;
    foreach ($headings as $position => $h) {
      $text = trim((string) ($h['text'] ?? ''));
      if ($text === '') {
        continue;
      }
      $level = (int) ($h['level'] ?? 6);
      // The first heading wins by default; a strictly larger (lower-level)
      // heading that appears among the first few headings can supersede it.
      if ($best === '' || ($level < $best_level && $position < 3)) {
        $best = $text;
        $best_level = $level;
      }
    }
    return $best;
  }

  /**
   * Picks the primary CTA label from the page's texts.
   *
   * A CTA label is a short text (<= CTA_MAX_LENGTH chars, no sentence-ending
   * punctuation) sitting on a component whose machine name or label hints at a
   * CTA / button / hero. The first such text in document order is returned.
   *
   * @param array $summary
   *   The page summary.
   *
   * @return string
   *   The CTA label, or '' when none is detected.
   */
  protected function pickCtaLabel(array $summary): string {
    $cta_uuids = $this->ctaComponentUuids($summary['components'] ?? []);

    foreach (($summary['texts'] ?? []) as $row) {
      $text = trim((string) ($row['text'] ?? ''));
      if (!$this->looksLikeCtaLabel($text)) {
        continue;
      }
      // Prefer a text that lives on a CTA-named component; if we have no such
      // components at all, accept the first short label-like text.
      if ($cta_uuids === [] || in_array((string) ($row['uuid'] ?? ''), $cta_uuids, TRUE)) {
        return $text;
      }
    }
    return '';
  }

  /**
   * Picks the first substantial body paragraph as above-the-fold copy.
   *
   * Skips any text already chosen as the headline or CTA, and requires the copy
   * to be a real sentence-length paragraph rather than a label.
   *
   * @param array $summary
   *   The page summary.
   * @param array<int,array{element:string,original:string}> $chosen
   *   The candidates already chosen (to avoid duplicating their text).
   *
   * @return string
   *   The copy, or '' when no substantial body text is present.
   */
  protected function pickAboveFoldCopy(array $summary, array $chosen): string {
    $taken = [];
    foreach ($chosen as $c) {
      $taken[mb_strtolower($c['original'])] = TRUE;
    }
    foreach (($summary['texts'] ?? []) as $row) {
      $text = trim((string) ($row['text'] ?? ''));
      if ($text === '' || isset($taken[mb_strtolower($text)])) {
        continue;
      }
      // A paragraph: long enough to be more than a label/heading line.
      if (mb_strlen($text) >= 60) {
        return $text;
      }
    }
    return '';
  }

  /**
   * The uuids of components whose name/label hints they hold a CTA.
   *
   * @param array $components
   *   The summary components (rows with uuid / bare / label).
   *
   * @return string[]
   *   The matching component uuids.
   */
  protected function ctaComponentUuids(array $components): array {
    $uuids = [];
    foreach ($components as $c) {
      $haystack = strtolower(trim((string) ($c['bare'] ?? '')) . ' ' . trim((string) ($c['label'] ?? '')));
      foreach (self::CTA_COMPONENT_HINTS as $hint) {
        if ($haystack !== '' && str_contains($haystack, $hint)) {
          $uuids[] = (string) ($c['uuid'] ?? '');
          break;
        }
      }
    }
    return array_values(array_filter($uuids));
  }

  /**
   * Whether a text reads like a CTA button label (short, no sentence end).
   *
   * @param string $text
   *   The candidate text.
   *
   * @return bool
   *   TRUE when the text looks like a CTA label.
   */
  protected function looksLikeCtaLabel(string $text): bool {
    if ($text === '' || mb_strlen($text) > self::CTA_MAX_LENGTH) {
      return FALSE;
    }
    // Sentence-ending punctuation marks body copy, not a button label.
    if (preg_match('/[.!?]\s*$/', $text)) {
      return FALSE;
    }
    // A label is a handful of words, not a paragraph.
    return str_word_count($text) <= 6;
  }

  /**
   * Builds the system prompt for the variant generation.
   *
   * @return string
   *   The system instruction.
   */
  protected function systemPrompt(): string {
    return 'You are a conversion-rate-optimisation assistant. For each high-impact page element you are given, propose 1-2 alternative versions for an A/B test. Keep each variant the same kind and roughly the same length as the original (a headline stays a headline, a CTA label stays 1-4 words). For every variant give a short hypothesis (one sentence on why it might perform better) and name the single metric it aims to improve, chosen from: click-through rate (CTR), scroll depth, or form submissions.';
  }

  /**
   * Builds the user prompt listing the identified elements and the JSON shape.
   *
   * @param array $summary
   *   The page summary (for light page context).
   * @param array<int,array{element:string,original:string}> $candidates
   *   The identified high-impact elements.
   *
   * @return string
   *   The user message.
   */
  protected function userPrompt(array $summary, array $candidates): string {
    $lines = [];
    foreach ($candidates as $c) {
      $lines[] = sprintf('- %s: "%s"', $c['element'], $c['original']);
    }
    return sprintf(
      "Page: \"%s\".\nHigh-impact elements to generate A/B variants for:\n%s\n\nReturn JSON of the shape {\"variants\":[{\"element\":\"hero headline\",\"original\":\"…\",\"variant\":\"…\",\"hypothesis\":\"…\",\"metric\":\"…\"}]} with 1-2 entries per element. \"original\" must echo the element's current text. \"metric\" is one of: CTR, scroll depth, form submissions.",
      (string) ($summary['title'] ?? ''),
      implode("\n", $lines)
    );
  }

  /**
   * Normalises the model's JSON into clean A/B variant rows.
   *
   * Tolerates a {variants:[…]} wrapper or a bare list, and fills a missing
   * "original" from the matching candidate by element name. Drops entries with
   * no variant text and caps the total to two per element.
   *
   * @param array $decoded
   *   The decoded JSON from the assistant.
   * @param array<int,array{element:string,original:string}> $candidates
   *   The identified candidates (used to backfill the original text).
   *
   * @return array<int,array{element:string,original:string,variant:string,hypothesis:string,metric:string}>
   *   The cleaned variant rows.
   */
  protected function normaliseVariants(array $decoded, array $candidates): array {
    $list = $decoded['variants'] ?? $decoded;
    if (!is_array($list)) {
      return [];
    }

    // Map element name -> original text, for backfilling a missing "original".
    $originals = [];
    foreach ($candidates as $c) {
      $originals[mb_strtolower($c['element'])] = $c['original'];
    }

    $out = [];
    $per_element = [];
    foreach ($list as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $element = trim((string) ($entry['element'] ?? ''));
      $variant = trim((string) ($entry['variant'] ?? $entry['text'] ?? ''));
      if ($variant === '') {
        continue;
      }
      $key = mb_strtolower($element);
      // Cap at two variants per element.
      $count = $per_element[$key] ?? 0;
      if ($element !== '' && $count >= 2) {
        continue;
      }
      $original = trim((string) ($entry['original'] ?? ''));
      if ($original === '' && $key !== '' && isset($originals[$key])) {
        $original = $originals[$key];
      }
      $out[] = [
        'element' => $element !== '' ? $element : 'element',
        'original' => $original,
        'variant' => $variant,
        'hypothesis' => trim((string) ($entry['hypothesis'] ?? $entry['rationale'] ?? $entry['reason'] ?? '')),
        'metric' => trim((string) ($entry['metric'] ?? '')),
      ];
      if ($element !== '') {
        $per_element[$key] = $count + 1;
      }
    }
    return $out;
  }

}
