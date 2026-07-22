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
 * AI Agent tool: translate / localize a Canvas page's visible text.
 *
 * Reads every human-readable text prop off an existing canvas_page, asks the
 * site's AI provider to translate (or localize) those strings into a target
 * language while preserving structure and inline formatting, and returns a
 * field-by-field change set. When apply=true the translated strings are written
 * back onto the same page (in place); otherwise the tool only previews them.
 *
 * The page's component structure, layout and non-textual props are never
 * touched - only the copy is replaced. To keep the original language as well,
 * the agent should translate a duplicate / language-specific copy of the page
 * rather than overwrite this one.
 *
 * Covers PRD story 2.8 (localize / translate page content).
 */
#[FunctionCall(
  id: 'varbase_ai_figma:translate_page',
  function_name: 'varbase_figma_translate_page',
  name: 'Translate the page',
  description: 'Translates or localizes the visible text on a Drupal Canvas page into a target language. Localization adapts idioms, date formats, currency and cultural references for the target locale, not just a literal translation. Preserves HTML tags, links and inline formatting exactly, and keeps the page structure unchanged. Returns a field-by-field preview (original vs translated for every text prop); when apply=true it writes the translated text back onto the page in place. To keep the original language too, duplicate the page (or create a language translation of it) first and translate the copy, rather than overwriting the source.',
  group: 'modification_tools',
  module_dependencies: ['varbase_ai_figma', 'ai_figma'],
  context_definitions: [
    'page' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Page'),
      description: new TranslatableMarkup('The Canvas page to translate: its numeric canvas_page id, or its exact title.'),
      required: TRUE,
    ),
    'language' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Target language'),
      description: new TranslatableMarkup('The language to translate / localize into, e.g. "French", "fr", or "Arabic".'),
      required: TRUE,
    ),
    'localize' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Localize'),
      description: new TranslatableMarkup('"true" to adapt idioms, date formats, currency and cultural references for the target locale (not just a literal translation). Defaults to true.'),
      required: FALSE,
    ),
    'apply' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Apply'),
      description: new TranslatableMarkup('"true" to write the translations back onto the page in place. Defaults to false (preview only).'),
      required: FALSE,
    ),
  ],
)]
class TranslatePage extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The largest number of strings sent in a single model request.
   *
   * Larger pages are chunked into batches of this size so the JSON payload (and
   * the model's response) stays well within a reasonable size.
   */
  protected const BATCH_SIZE = 40;

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The Canvas page analyzer (loads a page + extracts its text props).
   *
   * @var \Drupal\varbase_ai_figma\CanvasPageAnalyzer
   */
  protected CanvasPageAnalyzer $analyzer;

  /**
   * The Canvas page editor (locates rows by uuid + writes props back).
   *
   * @var \Drupal\varbase_ai_figma\CanvasPageEditor
   */
  protected CanvasPageEditor $editor;

  /**
   * The AI assistant (wraps the site's default chat provider).
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
      throw new \Exception('You do not have permission to translate Canvas pages.');
    }

    $page_ref = trim((string) $this->getContextValue('page'));
    $language = trim((string) $this->getContextValue('language'));
    $localize = $this->isTrue((string) ($this->getContextValue('localize') ?? ''), TRUE);
    $apply = $this->isTrue((string) ($this->getContextValue('apply') ?? ''), FALSE);

    if ($page_ref === '' || $language === '') {
      $this->result = 'Both a "page" (canvas_page id or exact title) and a target "language" are required.';
      return;
    }

    // 1. Load the page.
    $page = $this->analyzer->loadPage($page_ref);
    if (!$page) {
      $this->result = sprintf('No Canvas page found for "%s". Pass a numeric canvas_page id or the page\'s exact title.', $page_ref);
      return;
    }

    // 1b. A model is required to translate; degrade gracefully when absent.
    if (!$this->assistant->isAvailable()) {
      $this->result = 'No AI provider is configured for translation. Set a default chat provider/model at /admin/config/ai/settings, then try again.';
      return;
    }

    // 2. Collect the translatable strings (each {uuid, prop, text}).
    $texts = $this->analyzer->summary($page)['texts'] ?? [];
    if (!$texts) {
      $this->result = sprintf('Canvas page %d ("%s") has no translatable text props to translate.', (int) $page->id(), (string) $page->label());
      return;
    }

    // 3. Ask the model to translate, batching large pages so each request stays
    // a reasonable size. Translations are index-aligned to $texts order.
    $translations = $this->translateAll($texts, $language, $localize);

    // 4. Build the field-by-field change set (original vs translated). Only
    // strings the model actually returned (and that changed) are counted as
    // translated; the rest keep their original copy.
    $changes = [];
    foreach ($texts as $i => $text) {
      $original = (string) $text['text'];
      $translated = isset($translations[$i]) ? trim((string) $translations[$i]) : '';
      $has_translation = $translated !== '' && $translated !== $original;
      $changes[] = [
        'component_uuid' => (string) $text['uuid'],
        'prop' => (string) $text['prop'],
        'original' => $original,
        'translated' => $has_translation ? $translated : $original,
        'changed' => $has_translation,
      ];
    }

    // 5. Optionally write the translations back onto the page (in place). Each
    // changed string is located by its component uuid and its prop is set; the
    // page is saved once after all props are updated.
    $applied = FALSE;
    $applied_count = 0;
    if ($apply) {
      $rows = $this->editor->rows($page);
      foreach ($changes as $change) {
        if (!$change['changed']) {
          continue;
        }
        $index = $this->editor->indexOf($rows, $change['component_uuid']);
        if ($index === NULL) {
          continue;
        }
        $rows = $this->editor->setProp($rows, $index, $change['prop'], $change['translated']);
        $applied_count++;
      }
      if ($applied_count > 0) {
        $this->editor->save($page, $rows);
        $applied = TRUE;
      }
    }

    $this->result = $this->report($page, $language, $localize, $changes, $apply, $applied, $applied_count);
    $this->loggerFactory->get('ai_figma')->info('translate_page ran: @s', ['@s' => sprintf('%s%s', $language, $applied ? ' (applied)' : '')]);
  }

  /**
   * Translates every collected string, batching large pages.
   *
   * @param array[] $texts
   *   The collected strings, each {uuid, prop, text}, in document order.
   * @param string $language
   *   The target language.
   * @param bool $localize
   *   Whether to localize (adapt idioms/dates/culture) rather than translate
   *   literally.
   *
   * @return array<int, string>
   *   A map of $texts index → translated string. Missing indexes mean the
   *   model did not return a translation for that string.
   */
  protected function translateAll(array $texts, string $language, bool $localize): array {
    $system = $this->systemInstruction($language, $localize);
    $translations = [];
    foreach (array_chunk($texts, self::BATCH_SIZE, TRUE) as $batch) {
      $translations += $this->translateBatch($batch, $system);
    }
    return $translations;
  }

  /**
   * Translates one batch of strings via a single askJson() call.
   *
   * @param array<int, array> $batch
   *   A batch of collected strings, keyed by their original $texts index.
   * @param string $system
   *   The system instruction for the translator.
   *
   * @return array<int, string>
   *   A map of original $texts index → translated string.
   */
  protected function translateBatch(array $batch, string $system): array {
    // Build an index → original-string object so the model can return an
    // index-aligned {"translations": {"<index>": "<translated>"}} map.
    $payload = [];
    foreach ($batch as $i => $text) {
      $payload[(string) $i] = (string) $text['text'];
    }
    $user = 'Translate each value of this JSON object. Return ONLY an object of the form '
      . '{"translations": {"0": "…", "1": "…"}} whose keys are exactly the same keys as the '
      . 'input and whose values are the translated strings. Input: '
      . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $response = $this->assistant->askJson($system, $user);
    $map = $response['translations'] ?? [];
    if (!is_array($map)) {
      return [];
    }

    $out = [];
    foreach ($map as $key => $value) {
      if (is_string($value) && ctype_digit((string) $key)) {
        $out[(int) $key] = $value;
      }
    }
    return $out;
  }

  /**
   * Builds the translator/localizer system instruction.
   *
   * @param string $language
   *   The target language.
   * @param bool $localize
   *   Whether to localize rather than translate literally.
   *
   * @return string
   *   The system instruction.
   */
  protected function systemInstruction(string $language, bool $localize): string {
    return 'You are a professional translator/localizer. Translate the given strings into ' . $language . '. '
      . ($localize
        ? 'Localize: adapt idioms, date formats, currency and cultural references for the target locale, not a literal translation. '
        : '')
      . 'Preserve any HTML tags, links and inline formatting exactly. Keep the same JSON keys.';
  }

  /**
   * Renders the YAML report of the translation change set.
   *
   * @param \Drupal\Core\Entity\EntityInterface $page
   *   The canvas_page entity.
   * @param string $language
   *   The target language.
   * @param bool $localize
   *   Whether localization was requested.
   * @param array[] $changes
   *   The field-by-field change set.
   * @param bool $apply
   *   Whether writing back was requested.
   * @param bool $applied
   *   Whether the page was actually saved.
   * @param int $applied_count
   *   How many props were written back.
   *
   * @return string
   *   The YAML report.
   */
  protected function report($page, string $language, bool $localize, array $changes, bool $apply, bool $applied, int $applied_count): string {
    $changed = array_values(array_filter($changes, static fn(array $c): bool => $c['changed']));
    $fields = array_map(static fn(array $c): array => [
      'component_uuid' => $c['component_uuid'],
      'prop' => $c['prop'],
      'original' => $c['original'],
      'translated' => $c['translated'],
    ], $changes);

    $data = [
      'page_id' => (int) $page->id(),
      'page_title' => (string) $page->label(),
      'target_language' => $language,
      'localized' => $localize,
      'text_prop_count' => count($changes),
      'translated_count' => count($changed),
      'applied' => $applied,
    ];
    if ($apply) {
      $data['applied_prop_count'] = $applied_count;
    }
    $data['fields'] = $fields;
    // Always remind the agent that applying overwrites the source language: to
    // keep the original too, translate a COPY / language translation instead.
    $data['note'] = $applied
      ? 'The translations were written back onto this page, overwriting the original-language copy in place. To keep the original language as well, translate a duplicate / language translation of the page next time rather than overwriting the source.'
      : ($apply
        ? 'Nothing was written back (no string changed, or no target row matched). This was effectively a preview.'
        : 'Preview only - nothing was written. Call again with apply=true to write the translations back. To keep the original language as well, translate a duplicate / language translation of the page rather than overwriting the source.');

    return Yaml::dump($data, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
  }

  /**
   * Interprets a loosely-typed boolean context value.
   *
   * @param string $value
   *   The raw context value.
   * @param bool $default
   *   The value to use when the context value is empty / unset.
   *
   * @return bool
   *   The resolved boolean.
   */
  protected function isTrue(string $value, bool $default): bool {
    $value = strtolower(trim($value));
    if ($value === '') {
      return $default;
    }
    return in_array($value, ['1', 'true', 'yes', 'y', 'on'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
