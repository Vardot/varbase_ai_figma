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
use Drupal\varbase_ai_figma\CanvasPageEditor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * AI Agent tool: improve a piece of copy on an existing Canvas page.
 *
 * Reads one text from a canvas_page component - a headline, a body paragraph,
 * or a call-to-action label - asks the site's default AI chat provider for
 * 2-3 improved variants (each with a one-line rationale), and, when asked,
 * writes the chosen variant back to the component's prop. The improvement is
 * tuned by "kind": a headline is made sharper and scannable, body copy clearer
 * and more concise, a CTA more specific and action-oriented. Meaning, links
 * and inline formatting are preserved.
 *
 * The text to improve is located in natural language - by the component's
 * uuid, label, or machine name, OR by passing the exact current copy. When the
 * target is ambiguous or not found, the tool returns the page's text list
 * (uuid / prop / snippet) so the agent can disambiguate.
 *
 * Covers PRD stories 2.5 (shorten/improve a headline), 2.6 (improve body copy)
 * and 2.18 (strengthen CTA copy).
 */
#[FunctionCall(
  id: 'varbase_ai_figma:improve_text',
  function_name: 'varbase_figma_improve_text',
  name: 'Improve the wording',
  description: 'Improves a piece of copy on a Drupal Canvas page - a headline (shorter/sharper), a body paragraph (clearer/for an audience), or a CTA label (action-oriented) - using the site\'s AI chat provider. Returns 2-3 alternatives, each with a one-line rationale; when "apply" is "true" it writes the chosen alternative back to the component\'s prop (pick which with "choice", the 1-based variant index). Preserves the original meaning, any links and inline formatting. The page is identified by its canvas_page id or exact title. The text to improve is identified by "target": the component\'s uuid, exact label, or machine name, OR the exact current text to locate. "prop" (the prop holding the text) is auto-detected from the component\'s textual props when omitted. "kind" is headline | body | cta (default body). When the target is ambiguous or not found the tool lists the page\'s texts (uuid / prop / snippet) so you can disambiguate.',
  group: 'modification_tools',
  module_dependencies: ['varbase_ai_figma', 'ai_figma'],
  context_definitions: [
    'page' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Page'),
      description: new TranslatableMarkup('The Canvas page whose copy to improve: its numeric canvas_page id, or its exact title.'),
      required: TRUE,
    ),
    'target' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Target'),
      description: new TranslatableMarkup("What to improve: the component's uuid, exact label, or machine name, OR the exact current text to locate on the page."),
      required: TRUE,
    ),
    'prop' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Prop'),
      description: new TranslatableMarkup("Optional: the prop that holds the text. Auto-detected from the component's textual props when omitted."),
      required: FALSE,
    ),
    'kind' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Kind'),
      description: new TranslatableMarkup('The kind of copy: headline | body | cta. Defaults to body.'),
      required: FALSE,
    ),
    'instruction' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Instruction'),
      description: new TranslatableMarkup('Optional extra guidance, e.g. "make it shorter" or "for a technical audience".'),
      required: FALSE,
    ),
    'apply' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Apply'),
      description: new TranslatableMarkup('"true" to write the chosen variant back to the component prop. Defaults to false (just suggest).'),
      required: FALSE,
    ),
    'choice' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Choice'),
      description: new TranslatableMarkup('When apply is true: the 1-based index of the variant to apply. Defaults to 1 (the best variant).'),
      required: FALSE,
    ),
  ],
)]
class ImproveText extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

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
   * The Canvas page editor (reads rows, sets a prop, persists).
   *
   * @var \Drupal\varbase_ai_figma\CanvasPageEditor
   */
  protected CanvasPageEditor $editor;

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
    $instance->editor = $container->get('varbase_ai_figma.page_editor');
    $instance->assistant = $container->get('varbase_ai_figma.assistant');
    $instance->loggerFactory = $container->get('logger.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    if (
      !$this->currentUser->hasPermission('administer ai agents')
      && !$this->currentUser->hasPermission('use Drupal Canvas AI')
    ) {
      throw new \Exception('You do not have permission to improve Canvas page copy.');
    }

    $page_ref = trim((string) $this->getContextValue('page'));
    $target = trim((string) $this->getContextValue('target'));
    $prop = trim((string) $this->getContextValue('prop'));
    $kind = strtolower(trim((string) $this->getContextValue('kind'))) ?: 'body';
    $instruction = trim((string) ($this->getContextValue('instruction') ?? ''));
    $apply = in_array(strtolower(trim((string) $this->getContextValue('apply'))), ['true', '1', 'yes'], TRUE);
    $choice = (int) trim((string) $this->getContextValue('choice'));
    if ($choice < 1) {
      $choice = 1;
    }

    if ($page_ref === '' || $target === '') {
      $this->result = 'Both a "page" (canvas_page id or exact title) and a "target" (component uuid/label/name, or the exact current text) are required.';
      return;
    }
    if (!in_array($kind, ['headline', 'body', 'cta'], TRUE)) {
      $this->result = sprintf('Unknown kind "%s". Allowed: headline | body | cta.', $kind);
      return;
    }

    // 1. Load the page; refuse early when no AI chat provider is configured.
    $page = $this->analyzer->loadPage($page_ref);
    if (!$page) {
      $this->result = sprintf('No Canvas page found for "%s". Pass a numeric canvas_page id or the page\'s exact title.', $page_ref);
      return;
    }
    if (!$this->assistant->isAvailable()) {
      $this->result = 'No AI chat provider is configured, so copy cannot be improved. Set a default chat provider at Admin → AI (Configuration → AI → Settings).';
      return;
    }

    // 2. Locate the text. First try the editor's component resolver (uuid /
    // label / machine name). If that fails the target may be the exact current
    // copy: search the page's text list for it and use its uuid + prop.
    $rows = $this->editor->rows($page);
    $index = $this->editor->indexOf($rows, $target);
    $located_prop = '';
    if ($index === NULL) {
      $match = $this->locateByText($page, $target);
      if ($match === NULL) {
        $this->result = $this->textListMessage($page, sprintf('Could not locate "%s" on this page (not a component reference, and no matching text found).', $target));
        return;
      }
      $index = $this->editor->indexOf($rows, $match['uuid']);
      $located_prop = (string) $match['prop'];
      if ($index === NULL) {
        $this->result = $this->textListMessage($page, sprintf('Found the text but could not resolve its component (uuid %s).', $match['uuid']));
        return;
      }
    }

    // 3. Determine the prop holding the text + the current text. An explicit
    // "prop" wins; otherwise use the prop the text-match located; otherwise
    // auto-detect a textual prop from the component's inputs.
    [$current_inputs] = $this->decodeRowInputs($rows[$index] ?? []);
    $resolved_prop = $prop !== '' ? $prop : ($located_prop !== '' ? $located_prop : $this->detectTextualProp($current_inputs, $target));
    if ($resolved_prop === '') {
      $this->result = $this->textListMessage($page, sprintf('Could not determine which prop holds the text on the target component. Pass "prop" explicitly.'));
      return;
    }
    $current_text = isset($current_inputs[$resolved_prop]) && is_string($current_inputs[$resolved_prop])
      ? trim($current_inputs[$resolved_prop])
      : '';
    if ($current_text === '') {
      $this->result = $this->textListMessage($page, sprintf('Prop "%s" on the target component holds no text to improve.', $resolved_prop));
      return;
    }

    // 4. Ask the model for variants, tuned by kind.
    $system = $this->systemPrompt($kind, $instruction);
    $user = sprintf("Current text:\n%s\n\nReturn JSON of the shape {\"variants\":[{\"text\":\"...\",\"why\":\"...\"}]} with 2-3 variants, each \"why\" a single short rationale line.", $current_text);
    $decoded = $this->assistant->askJson($system, $user);
    $variants = $this->normaliseVariants($decoded);
    if (!$variants) {
      $this->result = sprintf('The AI provider returned no usable variants for "%s" (prop "%s"). Try again or refine the instruction.', mb_substr($current_text, 0, 80), $resolved_prop);
      return;
    }

    // 5. Optionally write the chosen variant back to the prop.
    $applied = NULL;
    if ($apply) {
      $pick = min($choice, count($variants));
      $rows = $this->editor->setProp($rows, $index, $resolved_prop, $variants[$pick - 1]['text']);
      $this->editor->save($page, $rows);
      $applied = $pick;
    }

    // 6. Build the YAML report: the original, the variants + rationale, and an
    // applied note when a variant was written back.
    $component_label = $this->rowLabel($rows[$index] ?? []);
    $out = [
      'page' => sprintf('%d (%s)', (int) $page->id(), (string) $page->label()),
      'component' => $component_label,
      'prop' => $resolved_prop,
      'kind' => $kind,
      'original' => $current_text,
      'variants' => array_map(static fn(int $i, array $v): array => [
        'n' => $i + 1,
        'text' => $v['text'],
        'why' => $v['why'],
      ], array_keys($variants), $variants),
    ];
    if ($applied !== NULL) {
      $out['applied'] = sprintf('Variant %d was written to %s.%s. Page saved.', $applied, $component_label, $resolved_prop);
    }
    else {
      $out['applied'] = 'No change written (apply was not set). Re-run with apply="true" and choice=<n> to apply one.';
    }
    $this->result = Yaml::dump($out, 6, 2);
    $this->loggerFactory->get('ai_figma')->info('improve_text ran: @s', ['@s' => sprintf('%s %s', $kind, $applied !== NULL ? 'applied' : 'preview')]);
  }

  /**
   * Locates a text on the page by its exact (or near-exact) current copy.
   *
   * Searches the analyzer's text summary (uuid / prop / text) for a row whose
   * text equals the target (case-insensitively, trimmed); falls back to a
   * unique substring containment match so "improve the Get started copy" can
   * still find "Get started today".
   *
   * @param \Drupal\Core\Entity\EntityInterface $page
   *   The canvas_page entity.
   * @param string $target
   *   The text to find.
   *
   * @return array{uuid:string,prop:string,text:string}|null
   *   The matched text row, or NULL when not found / ambiguous.
   */
  protected function locateByText($page, string $target): ?array {
    $texts = $this->analyzer->summary($page)['texts'] ?? [];
    $needle = mb_strtolower($target);
    // Exact (trimmed, case-insensitive) match wins.
    foreach ($texts as $row) {
      if (mb_strtolower(trim((string) $row['text'])) === $needle) {
        return ['uuid' => (string) $row['uuid'], 'prop' => (string) $row['prop'], 'text' => (string) $row['text']];
      }
    }
    // Otherwise a unique substring containment match (either direction).
    $hits = [];
    foreach ($texts as $row) {
      $hay = mb_strtolower(trim((string) $row['text']));
      if ($hay !== '' && (str_contains($hay, $needle) || str_contains($needle, $hay))) {
        $hits[] = ['uuid' => (string) $row['uuid'], 'prop' => (string) $row['prop'], 'text' => (string) $row['text']];
      }
    }
    return count($hits) === 1 ? $hits[0] : NULL;
  }

  /**
   * Auto-detects which input prop holds the text to improve.
   *
   * Prefers the prop whose string value matches the located target text; else
   * the longest string-valued input (the most copy-like prop). Skips inputs
   * that look like urls/classes/enums/ids via the same heuristic the analyzer
   * uses for its text summary.
   *
   * @param array $inputs
   *   The component instance inputs (decoded).
   * @param string $target
   *   The target text/reference, used to prefer an exact value match.
   *
   * @return string
   *   The detected prop name, or '' when none is text-like.
   */
  protected function detectTextualProp(array $inputs, string $target): string {
    $needle = mb_strtolower(trim($target));
    $best = '';
    $best_len = -1;
    foreach ($inputs as $name => $value) {
      if (!is_string($value)) {
        continue;
      }
      $text = trim($value);
      if ($text === '' || !$this->looksTextual((string) $name, $text)) {
        continue;
      }
      // An exact match to the target text is the strongest signal.
      if ($needle !== '' && mb_strtolower($text) === $needle) {
        return (string) $name;
      }
      $len = mb_strlen($text);
      if ($len > $best_len) {
        $best_len = $len;
        $best = (string) $name;
      }
    }
    return $best;
  }

  /**
   * Whether a prop/value pair is human-readable copy (not a class/enum/url).
   *
   * Mirrors CanvasPageAnalyzer::looksTextual() so prop auto-detection agrees
   * with the page's text summary.
   */
  protected function looksTextual(string $prop, string $value): bool {
    if (preg_match('/url|href|variant|color|colour|class|icon|id$|^id|align|size|ratio|width|height/i', $prop)) {
      return FALSE;
    }
    return str_contains(trim($value), ' ') || mb_strlen(trim($value)) > 24;
  }

  /**
   * Builds the kind-specific system prompt for the rewrite.
   *
   * @param string $kind
   *   One of headline, body or cta.
   * @param string $instruction
   *   Optional extra guidance to fold into the instruction.
   *
   * @return string
   *   The system instruction.
   */
  protected function systemPrompt(string $kind, string $instruction): string {
    $extra = $instruction !== '' ? ' ' . rtrim($instruction, '.') . '.' : '';
    return match ($kind) {
      'headline' => 'Rewrite this headline to be sharper and scannable, same meaning, strong active verb, no longer than the original.' . $extra,
      'cta' => 'Rewrite this call-to-action button label to be specific and action-oriented (describe the benefit, not "Click here"/"Submit"); 1-4 words.' . $extra,
      default => 'Rewrite this paragraph to be clearer and more concise' . ($instruction !== '' ? ' ' . rtrim($instruction, '.') : '') . '; preserve any links and inline formatting and the original intent.',
    };
  }

  /**
   * Normalises the model's JSON into a clean list of {text, why} variants.
   *
   * Tolerates a bare list, a {variants:[…]} wrapper, and string-only entries
   * (treated as text with an empty rationale). Drops empties and caps at 3.
   *
   * @param array $decoded
   *   The decoded JSON from the assistant.
   *
   * @return array<int,array{text:string,why:string}>
   *   The cleaned variants, 0-3 of them.
   */
  protected function normaliseVariants(array $decoded): array {
    $list = $decoded['variants'] ?? $decoded;
    if (!is_array($list)) {
      return [];
    }
    $out = [];
    foreach ($list as $entry) {
      if (is_string($entry)) {
        $text = trim($entry);
        $why = '';
      }
      elseif (is_array($entry)) {
        $text = trim((string) ($entry['text'] ?? $entry['variant'] ?? ''));
        $why = trim((string) ($entry['why'] ?? $entry['rationale'] ?? $entry['reason'] ?? ''));
      }
      else {
        continue;
      }
      if ($text !== '') {
        $out[] = ['text' => $text, 'why' => $why];
      }
      if (count($out) >= 3) {
        break;
      }
    }
    return $out;
  }

  /**
   * Decodes a raw component row's inputs, tolerating a JSON-encoded string.
   *
   * @param array $row
   *   The raw component row.
   *
   * @return array{0:array}
   *   [inputs array].
   */
  protected function decodeRowInputs(array $row): array {
    $raw = $row['inputs'] ?? [];
    if (is_string($raw)) {
      $decoded = json_decode($raw, TRUE);
      return [is_array($decoded) ? $decoded : []];
    }
    return [is_array($raw) ? $raw : []];
  }

  /**
   * The display label for a raw component row (label, then component id).
   */
  protected function rowLabel(array $row): string {
    $label = $row['label'] ?? NULL;
    if ($label !== NULL && (string) $label !== '') {
      return (string) $label;
    }
    return (string) ($row['component_id'] ?? '(unknown)');
  }

  /**
   * A clarification message that appends the page's text list.
   *
   * Lets the agent disambiguate by uuid/prop when a target could not be
   * resolved (satisfies the "ambiguous target → list candidates" behaviour).
   *
   * @param \Drupal\Core\Entity\EntityInterface $page
   *   The canvas_page entity.
   * @param string $prefix
   *   The leading explanation.
   *
   * @return string
   *   The prefix followed by the page's located texts.
   */
  protected function textListMessage($page, string $prefix): string {
    $texts = $this->analyzer->summary($page)['texts'] ?? [];
    if (!$texts) {
      return $prefix . ' This page has no editable text props.';
    }
    $items = [];
    foreach ($texts as $row) {
      $snippet = mb_substr(trim((string) $row['text']), 0, 60);
      $items[] = sprintf('uuid=%s prop=%s "%s"', $row['uuid'] ?? '', $row['prop'] ?? '', $snippet);
    }
    return $prefix . ' Page texts: ' . implode('; ', $items) . '.';
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
