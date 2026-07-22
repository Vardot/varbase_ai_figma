<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma\Plugin\AiFunctionCall;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
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
use Drupal\varbase_ai_figma\CanvasPageEditor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AI Agent tool: generate descriptive alt text for a Canvas page's images.
 *
 * Scans a canvas_page for images missing alt text and drafts a concise,
 * descriptive alt for each, using the surrounding page context (the page title,
 * nearby headings/copy) and the image filename. Likely-decorative images (icon,
 * divider, spacer, background) are flagged and given an empty alt (alt="").
 * Suggestions are returned per image for review; when apply=true the tool makes
 * a best-effort write-back onto the referenced file entity's alt or the
 * component's alt prop.
 *
 * Note: true vision is NOT used - alt is drafted from the filename plus the
 * page context, so suggestions should be reviewed.
 *
 * Covers PRD story 2.13 (generate alt text).
 */
#[FunctionCall(
  id: 'varbase_ai_figma:alt_text',
  function_name: 'varbase_figma_alt_text',
  name: 'Describe the images for screen readers',
  description: 'Scans a Drupal Canvas page for images missing alt text and generates descriptive alt text for each, using the surrounding page context (nearby headings/copy and the image filename). Returns per-image suggestions for review. When "apply" is "true" the suggested alt is written, best-effort, onto the referenced media/file entity or the component\'s alt prop. Likely-decorative images (icon, divider, spacer, background) are detected and given an empty alt ("") so they are skipped by assistive technology. True image vision is not used - alt is drafted from the filename plus the page context, so suggestions should be reviewed.',
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
      description: new TranslatableMarkup('Pass "true" to write the suggested alt text back onto the images. Defaults to false (review the suggestions only).'),
      required: FALSE,
    ),
  ],
)]
class GenerateAltText extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The recommended maximum alt text length, in characters.
   */
  protected const MAX_CHARS = 125;

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
   * The Canvas page editor (applies targeted mutations).
   *
   * @var \Drupal\varbase_ai_figma\CanvasPageEditor
   */
  protected CanvasPageEditor $editor;

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
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

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
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->entityFieldManager = $container->get('entity_field.manager');
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

    // 2. A model is required to draft the alt text.
    if (!$this->assistant->isAvailable()) {
      $this->result = 'No AI chat provider is configured (ai.settings:default_providers). Set a default chat provider to generate alt text.';
      return;
    }

    // 3. Find the images that need alt text.
    $summary = $this->analyzer->summary($page);
    $images = $this->imagesNeedingAlt($summary);
    if (!$images) {
      $this->result = sprintf('Canvas page %d ("%s") has no images missing alt text. Nothing to do.', (int) $page->id(), (string) ($summary['title'] ?? $page->label()));
      return;
    }

    // 4. Build the page context the model uses to describe each image.
    $context = $this->pageContext($summary);

    // 5. Draft a suggestion per image.
    $suggestions = [];
    foreach ($images as $image) {
      $suggestions[] = $this->suggestForImage((string) $image['src'], $context);
    }

    // 6. Optionally write the alt text back.
    $applied = [];
    if ($apply) {
      foreach ($suggestions as $suggestion) {
        $applied[] = $this->applyAlt($page, $summary, $suggestion);
      }
    }

    // 7. Report.
    $this->result = $this->formatOutput($page, (string) ($summary['title'] ?? $page->label()), $suggestions, $apply, $applied);
    $this->loggerFactory->get('ai_figma')->info('alt_text ran: @s', ['@s' => sprintf('%d image(s)%s', count($suggestions), $apply ? ' applied' : '')]);
  }

  /**
   * Selects the page's images that are missing or have an empty alt.
   *
   * @param array $summary
   *   The analyzer summary (key: images => [{src, alt, missing_alt}, …]).
   *
   * @return array[]
   *   The images needing alt, each ['src' => string, 'alt' => string].
   */
  protected function imagesNeedingAlt(array $summary): array {
    $out = [];
    foreach (($summary['images'] ?? []) as $image) {
      if (!is_array($image)) {
        continue;
      }
      $src = trim((string) ($image['src'] ?? ''));
      if ($src === '') {
        continue;
      }
      $alt = trim((string) ($image['alt'] ?? ''));
      // Missing entirely, or present but empty (and not explicitly decorative,
      // which the analyzer reports as missing_alt=false with an empty alt).
      if (!empty($image['missing_alt']) || $alt === '') {
        $out[] = ['src' => $src, 'alt' => $alt];
      }
    }
    return $out;
  }

  /**
   * Builds the page context string: title + headings + first paragraph.
   *
   * @param array $summary
   *   The analyzer summary.
   *
   * @return string
   *   A compact context block describing what the page is about.
   */
  protected function pageContext(array $summary): string {
    $parts = [];
    $title = trim((string) ($summary['title'] ?? ''));
    if ($title !== '') {
      $parts[] = 'Page title: ' . $title;
    }
    $headings = [];
    foreach (($summary['headings'] ?? []) as $heading) {
      if (is_array($heading) && trim((string) ($heading['text'] ?? '')) !== '') {
        $headings[] = trim((string) $heading['text']);
      }
    }
    if ($headings) {
      $parts[] = 'Headings: ' . implode('; ', array_slice($headings, 0, 8));
    }
    // The first substantial piece of on-page copy stands in for the lead
    // paragraph.
    foreach (($summary['texts'] ?? []) as $text) {
      $value = is_array($text) ? trim((string) ($text['text'] ?? '')) : trim((string) $text);
      if ($value !== '') {
        $parts[] = 'Lead copy: ' . mb_substr($value, 0, 400);
        break;
      }
    }
    return implode("\n", $parts);
  }

  /**
   * Drafts an alt suggestion for one image (decorative images get alt="").
   *
   * If the filename or context strongly implies decoration the image is marked
   * decorative and no model call is made; otherwise the model is asked for a
   * concise descriptive alt given the filename and the page context.
   *
   * @param string $src
   *   The image src/URL.
   * @param string $context
   *   The page context block.
   *
   * @return array{src:string, suggested_alt:string, decorative:bool}
   *   The per-image suggestion.
   */
  protected function suggestForImage(string $src, string $context): array {
    $filename = $this->filename($src);

    if ($this->looksDecorative($filename)) {
      return ['src' => $src, 'suggested_alt' => '', 'decorative' => TRUE];
    }

    $system = 'You write accessible HTML image alt text. Given an image filename and the page it appears on, write ONE concise, descriptive alt text of at most ' . self::MAX_CHARS . ' characters: describe what the image shows, no quotes, no "image of"/"picture of" prefix. If the filename strongly implies the image is purely decorative (an icon, divider, spacer, or background), respond with exactly the word DECORATIVE instead.';
    $user = "Image filename: {$filename}\nImage src: {$src}\n\nPage context:\n{$context}";

    $answer = trim($this->assistant->ask($system, $user));
    $answer = trim($answer, " \t\n\r\0\x0B\"'");

    if ($answer === '' || strcasecmp($answer, 'DECORATIVE') === 0) {
      return [
        'src' => $src,
        'suggested_alt' => '',
        // An empty model answer is review-only; an explicit DECORATIVE verdict
        // means alt="".
        'decorative' => strcasecmp($answer, 'DECORATIVE') === 0,
      ];
    }

    // Enforce the length cap.
    if (mb_strlen($answer) > self::MAX_CHARS) {
      $answer = rtrim(mb_substr($answer, 0, self::MAX_CHARS));
    }
    return ['src' => $src, 'suggested_alt' => $answer, 'decorative' => FALSE];
  }

  /**
   * Best-effort write-back of a suggested alt onto a resolvable target.
   *
   * Tries, in order: a file entity located by its URI (set its image field's
   * alt), then a component instance carrying an alt-like prop (set it via the
   * editor). When neither resolves the image is reported as review-only.
   *
   * @param \Drupal\Core\Entity\EntityInterface $page
   *   The canvas_page entity.
   * @param array $summary
   *   The analyzer summary (unused target hints may be added later).
   * @param array $suggestion
   *   The per-image suggestion (src, suggested_alt, decorative).
   *
   * @return string
   *   A human-readable note describing what was applied for this image.
   */
  protected function applyAlt($page, array $summary, array $suggestion): string {
    $src = (string) $suggestion['src'];
    $alt = (string) $suggestion['suggested_alt'];

    // 1. Try to resolve a managed file entity from the src URI and set the
    // alt on the first referencing entity's image field. File alt lives on the
    // image-field item that references the file, not on the file itself, so we
    // look for an image-field item across content that points at this file.
    if ($this->applyToFileReference($src, $alt)) {
      return sprintf('Applied alt to the file referenced by "%s".', $src);
    }

    // 2. Try a component instance on this page that carries an alt-like prop.
    if ($this->applyToComponentProp($page, $src, $alt)) {
      return sprintf('Applied alt to the component prop for "%s".', $src);
    }

    return sprintf('review-only: could not resolve a writable alt target for %s', $src);
  }

  /**
   * Attempts to set the alt on an image-field item that references the file.
   *
   * @param string $src
   *   The image src/URL.
   * @param string $alt
   *   The alt text to write.
   *
   * @return bool
   *   TRUE when an alt was written, FALSE when no file/reference resolved.
   */
  protected function applyToFileReference(string $src, string $alt): bool {
    $fid = $this->resolveFileId($src);
    if ($fid === NULL) {
      return FALSE;
    }
    try {
      // Find an image-field item, on any content entity, that references this
      // file id, and update its alt. We scan the field map for image fields.
      $field_map = $this->entityFieldManager->getFieldMapByFieldType('image');
      foreach ($field_map as $entity_type_id => $fields) {
        $storage = $this->entityTypeManager->getStorage($entity_type_id);
        foreach (array_keys($fields) as $field_name) {
          $ids = $storage->getQuery()
            ->accessCheck(FALSE)
            ->condition($field_name . '.target_id', $fid)
            ->range(0, 1)
            ->execute();
          if (!$ids) {
            continue;
          }
          $entity = $storage->load(reset($ids));
          if (!$entity instanceof FieldableEntityInterface) {
            continue;
          }
          foreach ($entity->get($field_name) as $item) {
            if ((int) $item->target_id === $fid) {
              $item->alt = $alt;
              $entity->save();
              return TRUE;
            }
          }
        }
      }
    }
    catch (\Throwable) {
      return FALSE;
    }
    return FALSE;
  }

  /**
   * Resolves a managed file id from an image src URL.
   *
   * Maps a public-files URL (…/sites/default/files/<path>) to its public://
   * URI and looks the file up by URI. Returns NULL when not a local managed
   * file (e.g. an external or themed asset).
   *
   * @param string $src
   *   The image src/URL.
   *
   * @return int|null
   *   The file id, or NULL when unresolvable.
   */
  protected function resolveFileId(string $src): ?int {
    if ($src === '' || !preg_match('#/files/(.+)$#', parse_url($src, PHP_URL_PATH) ?: $src, $m)) {
      return NULL;
    }
    $uri = 'public://' . urldecode($m[1]);
    try {
      $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $uri]);
      if ($files) {
        return (int) reset($files)->id();
      }
    }
    catch (\Throwable) {
      return NULL;
    }
    return NULL;
  }

  /**
   * Attempts to set an alt-like prop on a page component that uses this image.
   *
   * Scans the page's component rows for one whose inputs both reference the
   * image (by src/filename) and expose an alt-like prop (alt / image_alt /
   * alt_text), and sets that prop via the editor.
   *
   * @param \Drupal\Core\Entity\EntityInterface $page
   *   The canvas_page entity.
   * @param string $src
   *   The image src/URL.
   * @param string $alt
   *   The alt text to write.
   *
   * @return bool
   *   TRUE when a prop was set + saved, FALSE otherwise.
   */
  protected function applyToComponentProp($page, string $src, string $alt): bool {
    $filename = $this->filename($src);
    $rows = $this->editor->rows($page);
    foreach ($rows as $index => $row) {
      $inputs = $row['inputs'] ?? [];
      if (is_string($inputs)) {
        $decoded = json_decode($inputs, TRUE);
        $inputs = is_array($decoded) ? $decoded : [];
      }
      if (!is_array($inputs) || !$inputs) {
        continue;
      }
      // Does this component reference the image at all?
      $serialised = mb_strtolower(json_encode($inputs) ?: '');
      if ($filename !== '' && !str_contains($serialised, mb_strtolower($filename)) && !str_contains($serialised, mb_strtolower($src))) {
        continue;
      }
      foreach (['alt', 'image_alt', 'alt_text', 'imageAlt'] as $prop) {
        if (array_key_exists($prop, $inputs)) {
          $rows = $this->editor->setProp($rows, $index, $prop, $alt);
          $this->editor->save($page, $rows);
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Extracts the bare filename from a src/URL.
   */
  protected function filename(string $src): string {
    $path = parse_url($src, PHP_URL_PATH);
    $base = basename($path !== NULL && $path !== '' ? $path : $src);
    // Drop a query/fragment that survived a path-less src.
    return preg_replace('/[?#].*$/', '', $base) ?? $base;
  }

  /**
   * Whether a filename strongly implies a decorative image (icon, spacer, …).
   *
   * @param string $filename
   *   The image filename.
   *
   * @return bool
   *   TRUE when the name signals decoration.
   */
  protected function looksDecorative(string $filename): bool {
    return (bool) preg_match('/\b(icon|divider|spacer|separator|bg|background|decoration|ornament|pattern|texture|bullet|dot)\b/i', $filename);
  }

  /**
   * Formats the readable output: missing count, suggestions, apply status.
   *
   * @param \Drupal\Core\Entity\EntityInterface $page
   *   The canvas_page entity.
   * @param string $title
   *   The page title.
   * @param array[] $suggestions
   *   The per-image suggestions.
   * @param bool $apply
   *   Whether an apply was requested.
   * @param string[] $applied
   *   The per-image apply notes (empty when apply was not requested).
   *
   * @return string
   *   The composed report.
   */
  protected function formatOutput($page, string $title, array $suggestions, bool $apply, array $applied): string {
    $lines = [];
    $lines[] = sprintf('Canvas page %d ("%s"): %d image(s) missing alt text.', (int) $page->id(), $title, count($suggestions));
    $lines[] = 'Alt drafted from filename + page context (no image vision); review before publishing.';
    $lines[] = 'Suggestions:';
    foreach ($suggestions as $i => $suggestion) {
      if ($suggestion['decorative']) {
        $lines[] = sprintf('  %d. %s -> decorative, alt="" (skipped by assistive tech).', $i + 1, $suggestion['src']);
      }
      elseif ($suggestion['suggested_alt'] === '') {
        $lines[] = sprintf('  %d. %s -> (no suggestion - review manually).', $i + 1, $suggestion['src']);
      }
      else {
        $lines[] = sprintf('  %d. %s -> "%s" (%d chars).', $i + 1, $suggestion['src'], $suggestion['suggested_alt'], mb_strlen($suggestion['suggested_alt']));
      }
      if ($apply && isset($applied[$i])) {
        $lines[] = '     ' . $applied[$i];
      }
    }
    if (!$apply) {
      $lines[] = 'Review only (apply was not requested). Re-run with apply="true" to write the alt text back.';
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
