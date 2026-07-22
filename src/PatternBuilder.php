<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ai_figma\FigmaContextClient;
use Drupal\canvas\ComponentSource\ComponentSourceManager;

/**
 * Assembles Canvas Pattern component trees from the default theme's palette.
 *
 * A pattern is a reusable section/group. This builder starts from a small set
 * of section templates (shipped in data/pattern_templates.json, the same shapes
 * the Varbase Starter recipe ships as ready patterns) and fills them with the
 * content read from a Figma node. It follows a reuse-before-create rule:
 *
 * - The atomic palette is EVERY component the site's default theme
 *   registers (sdc.<default theme>.*). A template's nodes reference those
 *   components, so a built pattern reuses the existing palette by
 *   construction.
 * - When a template references a component that is NOT registered (e.g. a
 *   bespoke region with no theme equivalent), the builder scaffolds a
 *   minimal code component (js_component) and exposes it to Canvas so the
 *   pattern still assembles - the only case where a NEW component is
 *   created.
 *
 * The result is a plain list of component-instance rows (uuid, parent_uuid,
 * slot, component_id, component_version, inputs) using only static
 * inputs, ready for
 * \Drupal\canvas\Entity\Pattern::create(['component_tree' => $rows]).
 */
class PatternBuilder {

  /**
   * Maps a layout hint to a shipped template id.
   */
  protected const LAYOUT_TEMPLATE = [
    'hero' => 'hero_split',
    'hero_slider' => 'hero_slider',
    'slider' => 'hero_slider',
    'media' => 'media_banner_intro',
    'banner' => 'media_banner_intro',
    'feature' => 'feature_grid_icons',
    'features' => 'feature_grid_icons',
    'cards' => 'feature_cards',
    'card' => 'feature_cards',
    'featured' => 'featured_media_split',
    'pricing' => 'pricing_row',
    'testimonials' => 'testimonials_row',
    'testimonial' => 'testimonials_row',
    'logos' => 'logos_strip',
    'cta' => 'cta_band',
    'faq' => 'faq_accordion',
    'accordion' => 'faq_accordion',
    'intro' => 'intro_two_column',
    'two_column' => 'intro_two_column',
  ];

  public function __construct(
    protected readonly FigmaContextClient $figmaClient,
    protected readonly FigmaToCanvasBuilder $builder,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly UuidInterface $uuid,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly ComponentSourceManager $componentSourceManager,
    protected readonly PluginManagerInterface $sdcManager,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Builds a pattern component tree for a Figma node.
   *
   * @param string $file_key
   *   The Figma file key.
   * @param string $node_id
   *   The Figma node id (may be empty for the file top level).
   * @param string $label
   *   The pattern label (used only for logging here).
   * @param string $layout
   *   A layout hint (auto | hero | cards | feature | cta | media | pricing |
   *   testimonials | logos | faq | ...). "auto" infers from the design.
   *
   * @return array
   *   ['rows' => array, 'layout' => string, 'reused_components' => string[],
   *    'created_components' => string[]].
   */
  public function buildTree(string $file_key, string $node_id, string $label, string $layout = 'auto'): array {
    // 1. Read the design content (best-effort; an empty summary still yields a
    //    template filled with its own placeholder copy).
    $content = $this->readDesign($file_key, $node_id);

    // 2. Resolve the template for the requested (or inferred) layout.
    $templates = $this->templates();
    $template_id = $this->resolveTemplateId($layout, $content, $templates);
    $template = $templates[$template_id] ?? NULL;
    if (!$template) {
      throw new \RuntimeException(sprintf('No pattern template for layout "%s".', $layout));
    }

    // 3. Overlay the design's primary content onto the template leaves.
    $nodes = $this->fillTemplate($template['nodes'] ?? [], $content);

    // 4. Assemble rows, resolving component ids/versions against the default
    //    theme palette (reuse), scaffolding a code component only when a node's
    //    component is not registered (create).
    $prefix = $this->componentPrefix();
    $key_to_uuid = [];
    foreach ($nodes as $n) {
      $key_to_uuid[$n['key']] = $this->uuid->generate();
    }

    $rows = [];
    $reused = [];
    $created = [];
    foreach ($nodes as $n) {
      $name = (string) $n['component'];
      $component_id = $prefix . '.' . $name;
      $component = $this->resolveComponent($component_id, $name, $created);
      if (!$component) {
        // Could neither reuse nor create: skip this node rather than fail.
        $this->loggerFactory->get('varbase_ai_figma')->warning('Pattern node component @c unavailable; skipped.', ['@c' => $component_id]);
        continue;
      }
      $reused[$name] = TRUE;
      $row = [
        'uuid' => $key_to_uuid[$n['key']],
        'component_id' => $component->id(),
        'component_version' => (string) $component->getActiveVersion(),
        'inputs' => $this->normalizeInputs($name, (array) ($n['inputs'] ?? [])),
      ];
      if (!empty($n['parent'])) {
        $row['parent_uuid'] = $key_to_uuid[$n['parent']] ?? NULL;
        $row['slot'] = $n['slot'] ?? NULL;
      }
      $rows[] = $row;
    }

    return [
      'rows' => $rows,
      'layout' => $template_id,
      'reused_components' => array_values(array_diff(array_keys($reused), $created)),
      'created_components' => array_values(array_unique($created)),
    ];
  }

  /**
   * Reads the Figma node and derives its primary content.
   *
   * @return array
   *   ['headings' => string[], 'paragraphs' => string[], 'buttons' => array,
   *    'kind' => string].
   */
  protected function readDesign(string $file_key, string $node_id): array {
    $headings = [];
    $paragraphs = [];
    $buttons = [];
    try {
      $summary = $this->figmaClient->summarizeTokens($this->figmaClient->fetchNodes($file_key, $node_id));
      foreach ((array) ($summary['texts'] ?? []) as $t) {
        $text = trim((string) ($t['text'] ?? ''));
        if ($text === '') {
          continue;
        }
        $len = mb_strlen($text);
        $size = (float) ($t['size'] ?? 0);
        if ($size >= 24 && $len <= 80) {
          $headings[] = $text;
        }
        elseif ($len >= 40) {
          $paragraphs[] = $text;
        }
        elseif ($len <= 24 && str_word_count($text) >= 1 && str_word_count($text) <= 4 && !preg_match('/\d/', $text)) {
          $buttons[] = ['label' => $text];
        }
      }
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('varbase_ai_figma')->info('Figma read for pattern failed (@m); using template copy.', ['@m' => $e->getMessage()]);
    }
    return ['headings' => $headings, 'paragraphs' => $paragraphs, 'buttons' => $buttons];
  }

  /**
   * Picks the template id for a layout hint (or infers from the content).
   */
  protected function resolveTemplateId(string $layout, array $content, array $templates): string {
    $layout = strtolower(trim($layout));
    if ($layout !== '' && $layout !== 'auto') {
      if (isset($templates[$layout])) {
        return $layout;
      }
      if (isset(self::LAYOUT_TEMPLATE[$layout])) {
        return self::LAYOUT_TEMPLATE[$layout];
      }
    }
    // Infer: several short headings + paragraphs -> feature grid; a single
    // heading + CTA -> hero; a lone CTA -> cta band; else feature cards.
    $h = count($content['headings']);
    $p = count($content['paragraphs']);
    $b = count($content['buttons']);
    if ($h >= 3 && $p >= 3) {
      return 'feature_cards';
    }
    if ($h <= 1 && $b >= 1 && $p <= 1) {
      return 'cta_band';
    }
    if ($h >= 1) {
      return 'hero_split';
    }
    return 'feature_cards';
  }

  /**
   * Overlays the design's primary content onto the template's leaf inputs.
   *
   * V1 heuristic: the first design heading fills the first heading atom, the
   * first paragraph fills the first text atom, and the first CTA label/url
   * fills the first button atom. Everything else keeps the template's own
   * placeholder copy (which is real Varbase copy).
   */
  protected function fillTemplate(array $nodes, array $content): array {
    $heading = $content['headings'][0] ?? NULL;
    $paragraph = $content['paragraphs'][0] ?? NULL;
    $button = $content['buttons'][0]['label'] ?? NULL;
    $heading_used = $text_used = $button_used = FALSE;

    foreach ($nodes as &$n) {
      $name = (string) $n['component'];
      if ($name === 'heading' && !$heading_used && $heading !== NULL) {
        $n['inputs']['heading_text'] = $heading;
        unset($n['inputs']['content']);
        $heading_used = TRUE;
      }
      elseif ($name === 'text' && !$text_used && $paragraph !== NULL) {
        $n['inputs']['text'] = '<p>' . $paragraph . '</p>';
        unset($n['inputs']['value']);
        $text_used = TRUE;
      }
      elseif ($name === 'button' && !$button_used && $button !== NULL) {
        $n['inputs']['label'] = $button;
        $button_used = TRUE;
      }
    }
    return $nodes;
  }

  /**
   * Resolves a template node's component: reuse if registered, else create.
   *
   * @return \Drupal\canvas\Entity\ComponentInterface|null
   *   The placeable Component config entity, or NULL when unavailable.
   */
  protected function resolveComponent(string $component_id, string $name, array &$created) {
    $storage = $this->entityTypeManager->getStorage('component');
    $component = $storage->load($component_id);
    if ($component) {
      return $component;
    }
    // Not in the palette: scaffold a minimal code component so the pattern can
    // still be assembled (the reuse-before-create fallback).
    try {
      $js_storage = $this->entityTypeManager->getStorage('js_component');
      $machine = preg_replace('/[^a-z0-9_]+/', '_', strtolower($name));
      $title = ucwords(str_replace(['-', '_'], ' ', $name));
      if (!$js_storage->load($machine)) {
        $source = "export default function Component({ text = '' }) {\n"
          . "  return (<div className=\"$machine\">{text}</div>);\n}";
        $js_storage->create([
          'machineName' => $machine,
          'name' => $title,
          'status' => TRUE,
          'props' => [
            'text' => [
              'title' => 'Text',
              'type' => 'string',
              'examples' => [$title],
            ],
          ],
          'required' => [],
          'slots' => [],
          'js' => ['original' => $source, 'compiled' => ''],
          'css' => ['original' => '', 'compiled' => ''],
        ])->save();
      }
      $js = $js_storage->load($machine);
      if ($js && !$js->status()) {
        $js->enable()->save();
      }
      $this->componentSourceManager->generateComponents('js', [$machine]);
      $created[] = $machine;
      return $storage->load('js.' . $machine);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('varbase_ai_figma')->warning('Could not scaffold component for @n: @m', [
        '@n' => $name,
        '@m' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Normalises an instance's inputs to the SDC's declared props.
   *
   * Applies the same cross-cutting fixes the pattern build script does (heading
   * content->heading_text, text value->text, button alignment/href), then drops
   * any key the component does not declare (Canvas validates inputs against the
   * SDC schema, so unknown keys would fail).
   */
  protected function normalizeInputs(string $name, array $inputs): array {
    if ($name === 'heading' && isset($inputs['content']) && !isset($inputs['heading_text'])) {
      $inputs['heading_text'] = $inputs['content'];
      unset($inputs['content']);
    }
    if ($name === 'text' && isset($inputs['value']) && !isset($inputs['text'])) {
      $inputs['text'] = $inputs['value'];
      unset($inputs['value']);
    }
    if ($name === 'button') {
      if (isset($inputs['alignment']) && $inputs['alignment'] === 'start') {
        $inputs['alignment'] = 'left';
      }
      if (isset($inputs['href']) && is_array($inputs['href'])) {
        $inputs['href'] = (string) ($inputs['href']['uri'] ?? '/');
      }
      if (isset($inputs['button_text']) && !isset($inputs['label'])) {
        $inputs['label'] = $inputs['button_text'];
      }
      if (isset($inputs['button_url']) && !isset($inputs['href'])) {
        $inputs['href'] = $inputs['button_url'];
      }
    }
    // The button SDC only allows solid variants; coerce any btn-outline-* value
    // (on any component's button_variant/variant prop) to its solid equivalent
    // so it never fails the button template's enum at render time.
    foreach (['variant', 'button_variant'] as $vkey) {
      if (isset($inputs[$vkey]) && is_string($inputs[$vkey]) && str_starts_with($inputs[$vkey], 'btn-outline-')) {
        $inputs[$vkey] = 'btn-' . substr($inputs[$vkey], strlen('btn-outline-'));
      }
    }
    $declared = $this->declaredProps($name);
    if ($declared) {
      $inputs = array_intersect_key($inputs, array_flip($declared));
    }
    return $inputs;
  }

  /**
   * The declared prop names for a vartheme SDC (empty when unreadable).
   *
   * @return string[]
   *   The prop machine names the SDC declares, or [] when it cannot be read.
   */
  protected function declaredProps(string $name): array {
    $theme = $this->defaultTheme();
    try {
      $def = $this->sdcManager->getDefinition($theme . ':' . $name);
    }
    catch (\Throwable $e) {
      return [];
    }
    return array_keys($def['props']['properties'] ?? []);
  }

  /**
   * The site component id prefix, e.g. "sdc.vartheme_bs5".
   */
  protected function componentPrefix(): string {
    return 'sdc.' . $this->defaultTheme();
  }

  /**
   * The site default theme machine name.
   */
  protected function defaultTheme(): string {
    return (string) $this->configFactory->get('system.theme')->get('default') ?: 'vartheme_bs5';
  }

  /**
   * Loads the shipped pattern templates keyed by pattern id.
   *
   * @return array
   *   template_id => ['nodes' => array, ...].
   */
  protected function templates(): array {
    $file = dirname(__DIR__) . '/data/pattern_templates.json';
    $data = is_file($file) ? json_decode((string) file_get_contents($file), TRUE) : [];
    $out = [];
    foreach ((array) $data as $spec) {
      if (!empty($spec['pattern_id'])) {
        $out[$spec['pattern_id']] = $spec;
      }
    }
    return $out;
  }

}
