<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma\Plugin\AiFunctionCall;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
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
use Drupal\varbase_ai_figma\AiAssistant;
use Drupal\varbase_ai_figma\CanvasPageAnalyzer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AI Agent tool: generate or improve a Canvas page's SEO meta description.
 *
 * Reads a canvas_page, gathers its title and on-page copy, and asks the site's
 * default AI provider for a compelling, keyword-relevant meta description of at
 * most 155 characters. When the page has no meta description one is generated
 * from the content; when it has one that is too long, too short, or weak it is
 * rewritten. The tool returns 2-3 variants with their character counts so an
 * author can choose, and (when apply=true) writes the chosen variant onto the
 * page's metatag/description field.
 *
 * Writing is defensive: canvas_page does not necessarily carry a Metatag field,
 * so the tool tries a metatag-type field, then a plain page property, and
 * otherwise returns the variants and reports that no writable meta field was
 * found (set it via the Metatag UI).
 *
 * Covers PRD story 2.10 (meta description).
 */
#[FunctionCall(
  id: 'varbase_ai_figma:meta_description',
  function_name: 'varbase_figma_meta_description',
  name: 'Write the search-engine summary',
  description: 'Generates or improves the SEO meta description for a Drupal Canvas page. If the page has no meta description it auto-generates one from the page content; if one exists but is too long, too short, or weak it rewrites it. Returns 2-3 candidate variants with their character counts (target at most 155 characters) so you can choose one. When "apply" is "true" the chosen variant (1-based "choice", default 1) is written to the page\'s metatag/description field. canvas_page may not expose a writable meta field; in that case the variants are still returned and the tool reports that the description must be set via the Metatag UI.',
  group: 'modification_tools',
  module_dependencies: ['varbase_ai_figma', 'ai_figma'],
  context_definitions: [
    'page' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Page'),
      description: new TranslatableMarkup('The Canvas page: its numeric canvas_page id, or its exact title.'),
      required: TRUE,
    ),
    'apply' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Apply'),
      description: new TranslatableMarkup('Pass "true" to write the chosen variant to the page\'s meta description. Defaults to false (preview the variants only).'),
      required: FALSE,
    ),
    'choice' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Choice'),
      description: new TranslatableMarkup('Which variant to apply, 1-based (1, 2 or 3). Defaults to 1.'),
      required: FALSE,
    ),
  ],
)]
class MetaDescription extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The recommended maximum meta description length, in characters.
   */
  protected const MAX_CHARS = 155;

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
   * The AI assistant (site default chat provider).
   *
   * @var \Drupal\varbase_ai_figma\AiAssistant
   */
  protected AiAssistant $assistant;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

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
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->configFactory = $container->get('config.factory');
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
      throw new \Exception('You do not have permission to edit Canvas pages.');
    }

    $page_ref = trim((string) $this->getContextValue('page'));
    $apply = strtolower(trim((string) ($this->getContextValue('apply') ?? ''))) === 'true';
    $choice = (int) trim((string) ($this->getContextValue('choice') ?? '1'));
    if ($choice < 1) {
      $choice = 1;
    }

    if ($page_ref === '') {
      $this->result = 'A "page" (canvas_page id or exact title) is required.';
      return;
    }

    // 1. Load the page.
    $page = $this->analyzer->loadPage($page_ref);
    if (!$page) {
      $this->result = sprintf('No Canvas page found for "%s". Pass a numeric canvas_page id or the page\'s exact title.', $page_ref);
      return;
    }

    // 2. A model is required to draft the description.
    if (!$this->assistant->isAvailable()) {
      $this->result = 'No AI chat provider is configured (ai.settings:default_providers). Set a default chat provider to generate meta descriptions.';
      return;
    }

    // 3. Gather the page content: title + the page's on-page copy.
    $summary = $this->analyzer->summary($page);
    $title = (string) ($summary['title'] ?? $page->label());
    $copy = $this->collectCopy($summary);

    // 4. Read any existing meta description so we can show + improve it.
    $meta_field = $this->findMetaField($page);
    $existing = $meta_field !== NULL ? $this->readMeta($page, $meta_field) : '';

    // 5. Ask the model for 2-3 variants with char counts.
    $variants = $this->generateVariants($title, $copy, $existing);
    if (!$variants) {
      $this->result = sprintf('Could not generate a meta description for Canvas page %d ("%s") - the AI provider returned no usable variants. Try again or set the description via the Metatag UI.', (int) $page->id(), $title);
      return;
    }

    // 6. Optionally write the chosen variant.
    $applied_note = '';
    if ($apply) {
      $index = min($choice, count($variants)) - 1;
      $chosen = $variants[$index]['text'];
      $applied_note = $this->applyMeta($page, $meta_field, $chosen, $choice);
    }

    // 7. Report.
    $this->result = $this->formatOutput($page, $title, $existing, $variants, $apply, $applied_note);
    $this->loggerFactory->get('ai_figma')->info('meta_description ran: @s', ['@s' => sprintf('page %d %s', (int) $page->id(), $apply ? 'applied' : 'preview')]);
  }

  /**
   * Collects the page's on-page copy (title's texts), joined and trimmed.
   *
   * @param array $summary
   *   The analyzer summary (keys: title, texts, headings, …).
   *
   * @return string
   *   The combined, length-capped copy used as the description source.
   */
  protected function collectCopy(array $summary): string {
    $parts = [];
    foreach (($summary['headings'] ?? []) as $heading) {
      if (is_array($heading) && trim((string) ($heading['text'] ?? '')) !== '') {
        $parts[] = trim((string) $heading['text']);
      }
    }
    foreach (($summary['texts'] ?? []) as $text) {
      $value = is_array($text) ? trim((string) ($text['text'] ?? '')) : trim((string) $text);
      if ($value !== '') {
        $parts[] = $value;
      }
    }
    $copy = trim(implode(' ', array_unique($parts)));
    // Cap the source copy so the prompt stays compact; the model only needs
    // enough to summarise the page.
    return mb_substr($copy, 0, 2000);
  }

  /**
   * Asks the model for 2-3 meta description variants with character counts.
   *
   * Recomputes each variant's character count server-side (the model's own
   * count is advisory) and enforces a sane, length-ordered set.
   *
   * @param string $title
   *   The page title.
   * @param string $copy
   *   The page's on-page copy.
   * @param string $existing
   *   The existing meta description, if any.
   *
   * @return array[]
   *   Rows of ['text' => string, 'chars' => int]; empty when none usable.
   */
  protected function generateVariants(string $title, string $copy, string $existing): array {
    $system = 'You are an SEO copywriter. Write a compelling, keyword-relevant SEO meta description, at most ' . self::MAX_CHARS . ' characters, no quotes, summarising the page. Return 2 to 3 distinct variants.';
    $user = "Page title: {$title}\n\nPage content:\n{$copy}";
    if ($existing !== '') {
      $user .= "\n\nExisting meta description (improve on it; it may be too long, too short, or weak):\n{$existing}";
    }
    $user .= "\n\nReturn JSON of the form {\"variants\":[{\"text\":\"…\",\"chars\":N}, …]} with 2 or 3 variants.";

    $decoded = $this->assistant->askJson($system, $user);
    $raw = $decoded['variants'] ?? (array_is_list($decoded) ? $decoded : []);
    if (!is_array($raw)) {
      return [];
    }

    $variants = [];
    foreach ($raw as $item) {
      $text = '';
      if (is_array($item)) {
        $text = trim((string) ($item['text'] ?? ''));
      }
      elseif (is_string($item)) {
        $text = trim($item);
      }
      // Defensively strip any wrapping quotes the model may have added.
      $text = trim($text, " \t\n\r\0\x0B\"'");
      if ($text === '') {
        continue;
      }
      $variants[$text] = ['text' => $text, 'chars' => mb_strlen($text)];
      if (count($variants) >= 3) {
        break;
      }
    }
    return array_values($variants);
  }

  /**
   * Finds a writable meta target on the page entity.
   *
   * Canvas_page does not necessarily carry a Metatag field, so this probes, in
   * order: a metatag-type field (field_metatags / metatag), then a plain
   * "description"/"metatag" property. Returns NULL when nothing writable
   * exists.
   *
   * @param \Drupal\Core\Entity\EntityInterface $page
   *   The canvas_page entity.
   *
   * @return array{name:string, type:string}|null
   *   The resolved field: type is "metatag" (serialised array with a
   *   'description' key) or "plain" (a scalar property); NULL when none.
   */
  protected function findMetaField($page): ?array {
    if (!$page instanceof FieldableEntityInterface) {
      return NULL;
    }
    // 1. A Metatag-type field, by field type then by conventional name.
    foreach ($page->getFieldDefinitions() as $field_name => $definition) {
      if ($definition->getType() === 'metatag') {
        return ['name' => $field_name, 'type' => 'metatag'];
      }
    }
    foreach (['field_metatags', 'metatag', 'field_meta_tags'] as $candidate) {
      if ($page->hasField($candidate)) {
        return ['name' => $candidate, 'type' => 'metatag'];
      }
    }
    // 2. A plain scalar description property/field.
    foreach (['description', 'meta_description', 'field_description'] as $candidate) {
      if ($page->hasField($candidate)) {
        return ['name' => $candidate, 'type' => 'plain'];
      }
    }
    return NULL;
  }

  /**
   * Reads the existing meta description from the resolved field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $page
   *   The canvas_page entity.
   * @param array $meta_field
   *   The resolved field (from findMetaField()).
   *
   * @return string
   *   The current description, or '' when none.
   */
  protected function readMeta($page, array $meta_field): string {
    try {
      $value = $page->get($meta_field['name'])->value ?? '';
      if ($meta_field['type'] === 'metatag') {
        // Metatag fields serialise an array (keyed by tag) as a string.
        $decoded = is_string($value) ? @unserialize($value, ['allowed_classes' => FALSE]) : $value;
        if (!is_array($decoded) && is_string($value) && $value !== '') {
          $decoded = json_decode($value, TRUE);
        }
        return is_array($decoded) ? trim((string) ($decoded['description'] ?? '')) : '';
      }
      return trim((string) $value);
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Writes the chosen variant to the page's meta field (when one exists).
   *
   * @param \Drupal\Core\Entity\EntityInterface $page
   *   The canvas_page entity.
   * @param array|null $meta_field
   *   The resolved field, or NULL when the page has no writable meta target.
   * @param string $text
   *   The chosen description text.
   * @param int $choice
   *   The 1-based variant number (for the report).
   *
   * @return string
   *   A human-readable note on whether/where the description was applied.
   */
  protected function applyMeta($page, ?array $meta_field, string $text, int $choice): string {
    if ($meta_field === NULL) {
      return 'No writable meta field found on canvas_page; apply was skipped - set it via the Metatag UI.';
    }
    try {
      if ($meta_field['type'] === 'metatag') {
        // Metatag fields store a serialized array with a 'description' key.
        $current = $page->get($meta_field['name'])->value ?? NULL;
        $tags = is_string($current) ? @unserialize($current, ['allowed_classes' => FALSE]) : $current;
        if (!is_array($tags)) {
          $tags = [];
        }
        $tags['description'] = $text;
        $page->set($meta_field['name'], serialize($tags));
      }
      else {
        $page->set($meta_field['name'], $text);
      }
      $page->save();
      return sprintf('Applied variant %d to the "%s" field (%d chars).', $choice, $meta_field['name'], mb_strlen($text));
    }
    catch (\Throwable $e) {
      return sprintf('Could not write the meta description to "%s": %s. Set it via the Metatag UI.', $meta_field['name'], $e->getMessage());
    }
  }

  /**
   * Formats the readable output: existing meta, variants, and apply status.
   *
   * @param \Drupal\Core\Entity\EntityInterface $page
   *   The canvas_page entity.
   * @param string $title
   *   The page title.
   * @param string $existing
   *   The existing meta description, if any.
   * @param array[] $variants
   *   The generated variants.
   * @param bool $apply
   *   Whether an apply was requested.
   * @param string $applied_note
   *   The apply result note (empty when apply was not requested).
   *
   * @return string
   *   The composed report.
   */
  protected function formatOutput($page, string $title, string $existing, array $variants, bool $apply, string $applied_note): string {
    $lines = [];
    $lines[] = sprintf('Meta description for Canvas page %d ("%s"):', (int) $page->id(), $title);
    $lines[] = $existing !== ''
      ? sprintf('Existing meta description (%d chars): %s', mb_strlen($existing), $existing)
      : 'Existing meta description: (none).';
    $lines[] = sprintf('Candidate variants (target <= %d chars):', self::MAX_CHARS);
    foreach ($variants as $i => $variant) {
      $flag = $variant['chars'] > self::MAX_CHARS ? ' [over limit]' : '';
      $lines[] = sprintf('  %d. (%d chars)%s %s', $i + 1, $variant['chars'], $flag, $variant['text']);
    }
    if ($apply) {
      $lines[] = $applied_note;
    }
    else {
      $lines[] = 'Preview only (apply was not requested). Re-run with apply="true" and the chosen "choice" to write it.';
    }
    return implode("\n", $lines);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
