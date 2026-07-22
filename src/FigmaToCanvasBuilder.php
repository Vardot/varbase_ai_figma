<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma;

use Drupal\ai_figma\FigmaContextClient;
use Drupal\Component\Serialization\Yaml;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Builds a Drupal Canvas page from Figma design context.
 *
 * Pulls colors, typography and real text from a Figma file/node and assembles a
 * section (cards, feature grid, CTA or media banner) using the configured
 * theme's Single Directory Components. Which component fills each role, and
 * which Figma text fills each prop, is read from `ai_figma.settings`
 * config (layouts + content_roles), not hard-coded.
 */
class FigmaToCanvasBuilder {

  /**
   * The layouts the builder can assemble.
   */
  public const LAYOUTS = ['cards', 'feature', 'cta', 'media'];

  /**
   * Placeholder link for generated buttons.
   *
   * Canvas's URL validator rejects "#", so demo buttons point at the front
   * page until an author edits them.
   */
  protected const LINK_PLACEHOLDER = '/';

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected FigmaContextClient $figmaClient,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected UuidInterface $uuid,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected $componentManager = NULL,
    protected $fileSystem = NULL,
    protected $httpClient = NULL,
  ) {}

  /**
   * Media entity ids fetched for the current node's foundation images.
   *
   * @var int[]
   */
  protected array $mediaPool = [];

  /**
   * Memoised component-id → active-version map (per request).
   *
   * @var array<string, string>
   */
  protected array $versionCache = [];

  /**
   * Memoised "file_key:node_id" → token summary map (per request).
   *
   * @var array<string, array>
   */
  protected array $summaryCache = [];

  /**
   * Memoised component-name → intel (props, slots, story, doc) per request.
   *
   * @var array<string, array>
   */
  protected array $intelCache = [];

  /**
   * Memoised bare-name → SDC plugin definition per request.
   *
   * ComponentDef() is on the hot path (declaredProps / declaredSlots /
   * requiredDefaults / composeProps all hit it, several times per component
   * instance), so cache the resolved definition rather than re-running
   * getDefinition() + try/catch for every prop lookup in a multi-card build.
   *
   * @var array<string, array>
   */
  protected array $defCache = [];

  /**
   * Returns the module settings config.
   */
  protected function settings() {
    return $this->configFactory->get('ai_figma.settings');
  }

  /**
   * Returns the component id prefix for the target theme (e.g. "sdc.<theme>").
   */
  protected function prefix(): string {
    $configured = (string) $this->settings()->get('component_prefix');
    // Fall back to the site's default theme - no hard-coded theme name.
    return $configured !== '' ? $configured : 'sdc.' . $this->defaultTheme();
  }

  /**
   * The raw config definition for a layout role (component, slot, props, …).
   *
   * @return array
   *   The role definition exactly as authored in `layouts` config.
   */
  protected function layoutRole(string $layout, string $role): array {
    $layouts = $this->settings()->get('layouts') ?: [];
    return (array) ($layouts[$layout][$role] ?? []);
  }

  /**
   * Resolves a layout role from config into component id, slot and props.
   *
   * The whole layout definition - which component, which section slot, and the
   * static props - lives in `layouts` config, so nothing is hard-coded. The
   * builder only supplies dynamic values (text, columns).
   *
   * @return array
   *   The role definition: 'component' (id), 'slot' and 'props'.
   */
  protected function roleDef(string $layout, string $role): array {
    $def = $this->layoutRole($layout, $role);
    $name = (string) ($def['component'] ?? $role);
    return [
      'component' => $this->prefix() . '.' . $name,
      'slot' => (string) ($def['slot'] ?? ''),
      'props' => (array) ($def['props'] ?? []),
    ];
  }

  /**
   * Resolves the dynamic content props for a layout role.
   *
   * Config-driven: a role's `content` map binds component props to the
   * semantic content roles (title, body, cta, …) read from the Figma node.
   * When a role declares no `content` map, the binding is derived from the
   * component's own declared props - read live from the theme component folder
   * (`.component.yml` + stories) or the cached Canvas component config - via
   * composeProps(). Either way no prop name is hard-coded in PHP.
   *
   * @param string $layout
   *   The layout key.
   * @param string $role
   *   The role within the layout.
   * @param string $component_bare
   *   Bare component name placed for this role (for the derived fallback).
   * @param array $values
   *   Content-role values, e.g. ['title' => '…', 'body' => '…', 'cta' => '…'].
   *
   * @return array
   *   prop name => content value, ready to merge over the role's static props.
   */
  protected function contentProps(string $layout, string $role, string $component_bare, array $values): array {
    $binding = (array) ($this->layoutRole($layout, $role)['content'] ?? []);
    if ($binding) {
      $out = [];
      foreach ($binding as $prop => $role_name) {
        $value = (string) ($values[$role_name] ?? '');
        if ($value !== '') {
          $out[(string) $prop] = $value;
        }
      }
      return $out;
    }
    // No authored binding: let the component's own props decide, read from the
    // theme component folder / cached Canvas config.
    return $this->composeProps($component_bare, [
      'title' => (string) ($values['title'] ?? ''),
      'body' => (string) ($values['body'] ?? ''),
      'button' => (string) ($values['cta'] ?? $values['button'] ?? ''),
    ]);
  }

  /**
   * Builds a section component from a config role, merging dynamic props.
   */
  protected function sectionFromRole(string $uuid, string $layout, string $role, array $dynamic, string $label): array {
    $def = $this->roleDef($layout, $role);
    return [
      'parent_uuid' => NULL,
      'slot' => NULL,
      'uuid' => $uuid,
      'component_id' => $def['component'],
      'component_version' => $this->activeVersion($def['component']),
      'inputs' => json_encode($this->filterProps($this->bareName($def['component']), array_merge($def['props'], $dynamic))),
      'label' => $label,
    ];
  }

  /**
   * Builds a leaf component from a config role, merging dynamic props.
   *
   * @param string $parent_uuid
   *   UUID of the parent section component.
   * @param string $layout
   *   The layout key (e.g. 'cards', 'cta').
   * @param string $role
   *   The role within the layout (e.g. 'card', 'hero').
   * @param array $dynamic
   *   Dynamic props (text, labels) merged over the config props.
   * @param string $label
   *   The component instance label.
   * @param int|null $slot_index
   *   When the role slot contains "%i", the 1-based column index to substitute.
   *
   * @return array
   *   A single component instance row for the components field.
   */
  protected function leafFromRole(string $parent_uuid, string $layout, string $role, array $dynamic, string $label, ?int $slot_index = NULL): array {
    $def = $this->roleDef($layout, $role);
    $slot = $slot_index !== NULL ? str_replace('%i', (string) $slot_index, $def['slot']) : $def['slot'];
    return [
      'parent_uuid' => $parent_uuid,
      'slot' => $slot,
      'uuid' => $this->uuid->generate(),
      'component_id' => $def['component'],
      'component_version' => $this->activeVersion($def['component']),
      'inputs' => json_encode($this->filterProps($this->bareName($def['component']), array_merge($def['props'], $dynamic))),
      'label' => $label,
    ];
  }

  /**
   * Resolves Figma image fills to local media entity ids.
   *
   * @param string $file_key
   *   Figma file key (empty uses the configured default).
   * @param string $node_id
   *   Optional node id to scope the design context.
   *
   * @return int[]
   *   The media entity ids for the imported Figma images.
   */
  protected function nodeMediaIds(string $file_key, string $node_id): array {
    if (!$this->fileSystem || !$this->httpClient
      || !method_exists($this->figmaClient, 'collectImageRefs')
      || !$this->entityTypeManager->getStorage('media_type')->load('image')) {
      return [];
    }
    try {
      $refs = $this->figmaClient->collectImageRefs($this->figmaClient->fetchNodes($file_key, $node_id));
      if (!$refs) {
        return [];
      }
      $fills = $this->figmaClient->fetchImageFills($file_key);
    }
    catch (\Throwable $e) {
      return [];
    }
    $dir = 'public://figma';
    $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $fileStorage = $this->entityTypeManager->getStorage('file');
    $mediaStorage = $this->entityTypeManager->getStorage('media');
    $ids = [];
    foreach ($refs as $item) {
      $ref = $item['ref'];
      $name = 'figma-asset-' . substr(preg_replace('/[^a-z0-9]/i', '', $ref), 0, 24) . '.png';
      $uri = $dir . '/' . $name;
      $files = $fileStorage->loadByProperties(['uri' => $uri]);
      if ($files) {
        $file = reset($files);
        $mids = $mediaStorage->getQuery()->accessCheck(FALSE)
          ->condition('field_media_image.target_id', $file->id())->range(0, 1)->execute();
        if ($mids) {
          $ids[] = (int) reset($mids);
          continue;
        }
      }
      else {
        $remote = $fills[$ref] ?? '';
        if ($remote === '') {
          continue;
        }
        try {
          $data = (string) $this->httpClient->request('GET', $remote, ['timeout' => 60])->getBody();
          $this->fileSystem->saveData($data, $uri, FileExists::Replace);
        }
        catch (\Throwable $e) {
          continue;
        }
        $file = $fileStorage->create(['uri' => $uri, 'filename' => $name, 'status' => 1]);
        $file->save();
      }
      $media = $mediaStorage->create([
        'bundle' => 'image',
        'name' => $item['name'] ?: 'Figma image',
        'field_media_image' => ['target_id' => $file->id(), 'alt' => $item['name'] ?: 'Figma image'],
        'status' => 1,
      ]);
      $media->save();
      $ids[] = (int) $media->id();
    }
    return $ids;
  }

  /**
   * Builds (or rebuilds) a canvas_page from Figma context.
   */
  public function build(string $file_key, string $node_id, string $title, ?int $page_id = NULL, string $layout = ''): array {
    if ($file_key === '') {
      $file_key = $this->figmaClient->getDefaultFileKey();
    }
    if ($file_key === '') {
      throw new \InvalidArgumentException('No Figma file key provided and no default configured.');
    }

    $cache_key = $file_key . ':' . $node_id;
    if (!isset($this->summaryCache[$cache_key])) {
      $data = $this->figmaClient->fetchNodes($file_key, $node_id);
      $summary = $this->figmaClient->summarizeTokens($data);
      // The Figma API occasionally answers 200 with an incomplete payload
      // (rate-limiting / transient). If we got nothing usable, retry once
      // before giving up - a real empty node will stay empty, a blip recovers.
      if (empty($summary['texts']) && empty($summary['colors'])) {
        usleep(400000);
        $data = $this->figmaClient->fetchNodes($file_key, $node_id);
        $summary = $this->figmaClient->summarizeTokens($data);
      }
      $this->summaryCache[$cache_key] = $summary;
    }
    $summary = $this->summaryCache[$cache_key];
    if (empty($summary['texts']) && empty($summary['colors'])) {
      // Distinguish "node not in the response" (wrong id / not shared / API
      // hiccup) from "node has no fills or text" so the message is actionable.
      $node_present = ($summary['root_name'] ?? '') !== '';
      $this->loggerFactory->get('ai_figma')->warning('Figma build: no usable context for @key node @node (node @present in response).', [
        '@key' => $file_key,
        '@node' => $node_id !== '' ? $node_id : '(file top level)',
        '@present' => $node_present ? 'present' : 'absent',
      ]);
      throw new \RuntimeException($node_present
        ? sprintf('That Figma node ("%s") has no colours or text to build from - pick a frame/section with visible content.', $summary['root_name'])
        : 'Figma returned no data for that node - the link may point at a node that no longer exists, the file may not be shared with this token, or the Figma API hiccupped. Double-check the link and try again.');
    }

    $primary_hex = $this->pickPrimaryColor($summary['colors']);

    $storage = $this->entityTypeManager->getStorage('canvas_page');
    $page = $page_id ? $storage->load($page_id) : NULL;
    if (!$page) {
      $page = $storage->create(['title' => $title, 'status' => 1]);
    }
    else {
      $page->set('title', $title);
    }

    $content = $this->deriveContent($title, $summary);
    if ($layout === '') {
      $layout = (string) ($this->settings()->get('default_layout') ?: 'auto');
    }
    $resolved_layout = $layout === 'auto' ? $this->inferLayout($content) : $layout;
    if (!in_array($resolved_layout, self::LAYOUTS, TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        'Unknown layout "%s". Use one of: %s (or "auto").',
        $resolved_layout,
        implode(', ', self::LAYOUTS),
      ));
    }
    // Populate the canvas with the design's real foundation images.
    $this->mediaPool = $this->nodeMediaIds($file_key, $node_id);
    $components = $this->buildComponents($content, $resolved_layout);
    $page->set('components', $components);
    $page->save();

    return [
      'id' => (int) $page->id(),
      'url' => '/page/' . $page->id(),
      'primary' => $primary_hex,
      'components' => count($components),
      'layout' => $resolved_layout,
    ];
  }

  /**
   * Picks a section type from the derived content when the caller said 'auto'.
   *
   * Falls back to 'cards' - the first/most general section - when the content
   * does not clearly point at another type.
   */
  protected function inferLayout(array $content): string {
    $cards = count($content['cards'] ?? []);
    $features = count($content['features'] ?? []);
    // A 4+ short-paragraph node reads as a feature grid.
    if ($features >= 4 && $cards <= 1) {
      return 'feature';
    }
    // A single heading + body with nothing to expand reads as a CTA.
    if ($cards <= 1 && $features <= 1) {
      return 'cta';
    }
    // Anything else (two or more heading→body pairs) reads as cards.
    return 'cards';
  }

  /**
   * Dispatches to the assembler for the resolved section type.
   */
  protected function buildComponents(array $content, string $layout): array {
    // Dynamic dispatch: a layout "cards" resolves to assembleCards(), "cta" to
    // assembleCta(), etc. Add a layout by adding it to self::LAYOUTS + an
    // assemble<Layout>() method - no central switch to edit. An unknown layout
    // falls back to the general "cards" section.
    $method = 'assemble' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $layout)));
    if (is_callable([$this, $method])) {
      return $this->{$method}($content);
    }
    return $this->assembleCards($content);
  }

  /**
   * The configured structural nesting order for card layouts.
   *
   * For example ['section', 'group', 'card']. Reorderable via the layout UI.
   *
   * @return string[]
   *   Ordered structural level names.
   */
  protected function structureOrder(): array {
    $order = (array) ($this->settings()->get('builder_order') ?: []);
    $order = array_values(array_filter(array_map('strval', $order)));
    return $order ?: ['section', 'card'];
  }

  /**
   * The static props configured for a layout role (background etc.).
   */
  protected function roleProps(string $layout, string $role): array {
    return $this->roleDef($layout, $role)['props'];
  }

  /**
   * Builds one component instance row (prop-safe via filterProps).
   *
   * @param string|null $parent
   *   Parent component UUID, or NULL for a top-level section.
   * @param string|null $slot
   *   Slot name in the parent, or NULL for a top-level section.
   * @param string $component_id
   *   Full component id (prefix.name).
   * @param array $dynamic
   *   Candidate props (filtered to what the component declares).
   * @param string $uuid
   *   The instance UUID.
   * @param string $label
   *   The instance label.
   */
  protected function instance(?string $parent, ?string $slot, string $component_id, array $dynamic, string $uuid, string $label): array {
    return [
      'parent_uuid' => $parent,
      'slot' => $slot,
      'uuid' => $uuid,
      'component_id' => $component_id,
      'component_version' => $this->activeVersion($component_id),
      'inputs' => json_encode($this->filterProps($this->bareName($component_id), $dynamic)),
      'label' => $label,
    ];
  }

  /**
   * Maps a column count to a section "columns" value.
   */
  protected function columnsFor(int $n): string {
    return [
      1 => '100',
      2 => '50-50',
      3 => '33-33-33',
      4 => '25-25-25-25',
      5 => '20-20-20-20-20',
      6 => '16-16-16-16-16-16',
    ][$n] ?? '33-33-33';
  }

  /**
   * Layout: a single centered call-to-action on a brand-dark section.
   */
  protected function assembleCta(array $content): array {
    $sec = $this->uuid->generate();
    $cta_id = $this->componentChoice('cta', 'cta');
    return [
      $this->sectionFromRole($sec, 'cta', 'section', [], 'CTA section'),
      $this->instance($sec, $this->roleDef('cta', 'cta')['slot'] ?: 'col_1', $cta_id, array_merge(
        $this->roleProps('cta', 'cta'),
        $this->contentProps('cta', 'cta', $this->bareName($cta_id), $content['roles']),
      ), $this->uuid->generate(), 'Hero CTA'),
    ];
  }

  /**
   * Layout: side-by-side cards, one per heading→body pair from the node.
   */
  protected function assembleCards(array $content): array {
    $cards = array_slice($content['cards'] ?: $content['pairs'], 0, 4);
    if (!$cards) {
      return $this->assembleCta($content);
    }

    // Structure order (section › group › card) + chosen component per family,
    // all from config. A 'group' level wraps the cards in one column instead
    // of spreading them across section columns.
    $order = $this->structureOrder();
    $use_group = in_array('group', $order, TRUE);
    $section_id = $this->componentChoice('section', 'section');
    $card_id = $this->componentChoice('card', 'card');

    $sec = $this->uuid->generate();
    $components = [
      $this->instance(NULL, NULL, $section_id, array_merge(
        $this->roleProps('cards', 'section'),
        ['columns' => $this->columnsFor($use_group ? 1 : count($cards))],
      ), $sec, 'Cards section'),
    ];

    // Optional group wrapper inside the first column.
    $card_parent = $sec;
    $card_slot = 'col_%i';
    if ($use_group) {
      $group_id = $this->componentChoice('group', 'group');
      $grp = $this->uuid->generate();
      $components[] = $this->instance($sec, 'col_1', $group_id, $this->roleProps('cards', 'group'), $grp, 'Cards group');
      $card_parent = $grp;
      $card_slot = $this->declaredSlots($this->bareName($group_id))[0] ?? 'content';
    }

    foreach (array_values($cards) as $i => $card) {
      $slot = $use_group ? $card_slot : str_replace('%i', (string) ($i + 1), $card_slot);
      $media_id = $this->mediaPool ? $this->mediaPool[$i % count($this->mediaPool)] : NULL;
      $components = array_merge($components, $this->cardInstance(
        $card_parent,
        $slot,
        $card_id,
        $card,
        'Card ' . ($i + 1),
        $media_id,
      ));
    }
    return $components;
  }

  /**
   * Builds a card of any type, prop-safe.
   *
   * Fills the props the card declares, and when the card has a content slot,
   * nests the body text inside it - so card types that take a slot rather than
   * text props still show their copy.
   *
   * @return array
   *   One or two component rows (the card, plus an optional content leaf).
   */
  protected function cardInstance(string $parent, string $slot, string $card_id, array $card, string $label, ?int $media_id = NULL): array {
    $bare = $this->bareName($card_id);
    $uuid = $this->uuid->generate();
    // Dynamic: props are composed from the card's own .component.yml + stories,
    // mapping the Figma title/body/button onto whatever props it declares.
    $props = $this->composeProps($bare, [
      'title' => $card['title'] ?? '',
      'body' => $card['body'] ?? '',
      'button' => $card['button'] ?? 'Learn more',
    ]);
    // Bind the design's real image when the card declares a media prop
    // (filterProps drops it harmlessly for cards that take no media).
    if ($media_id) {
      $props['media'] = ['target_id' => (string) $media_id];
    }
    $rows = [
      $this->instance($parent, $slot, $card_id, $props, $uuid, $label),
    ];
    // If the chosen card has a content slot and took none of the text props,
    // drop the copy into the slot as a plain-text leaf.
    $slots = $this->declaredSlots($bare);
    $text_props = ['heading_text', 'text', 'description', 'title', 'name', 'summary', 'content'];
    $took_text = array_intersect_key($props, array_flip($text_props));
    if ($slots && !$took_text) {
      $text_id = $this->componentChoice('text', 'plain-text');
      $body = trim(($card['title'] ? $card['title'] . ': ' : '') . $card['body']);
      $rows[] = $this->instance($uuid, $slots[0], $text_id, [
        'text' => $body,
        'content' => $body,
      ], $this->uuid->generate(), $label . ' text');
    }
    return $rows;
  }

  /**
   * Layout: a centered heading over a grid of icon feature cards.
   */
  protected function assembleFeature(array $content): array {
    // Prefer titled feature cards (heading → body pairs, e.g. "AI Integration"
    // + its description) so the grid reads like the Figma node; fall back to
    // body-only paragraphs when the node's features have no per-card heading.
    $titled = array_slice($content['cards'], 0, 6);
    $bodies = array_slice($content['features'], 0, 6);
    $items = count($titled) >= 2 ? $titled : array_map(static fn($b) => ['title' => '', 'body' => $b], $bodies);
    if (!$items) {
      return $this->assembleCta($content);
    }
    $hsec = $this->uuid->generate();
    $gsec = $this->uuid->generate();
    $heading_id = $this->componentChoice('heading', 'heading');
    $card_bare = $this->bareName($this->roleDef('feature', 'card')['component']);
    $components = [
      $this->sectionFromRole($hsec, 'feature', 'heading_section', [], 'Feature heading'),
      $this->instance($hsec, $this->roleDef('feature', 'heading')['slot'] ?: 'col_1', $heading_id, array_merge(
        $this->roleProps('feature', 'heading'),
        $this->contentProps('feature', 'heading', $this->bareName($heading_id), $content['roles']),
      ), $this->uuid->generate(), 'Section heading'),
      $this->sectionFromRole($gsec, 'feature', 'section', ['columns' => $this->columnsFor(count($items))], 'Feature grid'),
    ];
    foreach (array_values($items) as $i => $item) {
      $components[] = $this->leafFromRole($gsec, 'feature', 'card', $this->contentProps('feature', 'card', $card_bare, [
        'title' => $item['title'],
        'body' => $item['body'],
      ]), 'Feature ' . ($i + 1), $i + 1);
    }
    return $components;
  }

  /**
   * Layout: a split brand-dark banner - text + buttons beside a media stand-in.
   */
  protected function assembleMedia(array $content): array {
    $sec = $this->uuid->generate();
    // The banner's lead component honours the configured hero choice (falls
    // back to the cta component), so a theme hero can drive the media layout.
    $hero_id = $this->componentChoice('hero', $this->bareName($this->roleDef('media', 'text')['component']));
    return [
      $this->sectionFromRole($sec, 'media', 'section', [], 'Media banner'),
      $this->instance($sec, $this->roleDef('media', 'text')['slot'] ?: 'col_1', $hero_id, array_merge(
        $this->roleProps('media', 'text'),
        $this->contentProps('media', 'text', $this->bareName($hero_id), $content['roles']),
      ), $this->uuid->generate(), 'Banner text'),
      $this->leafFromRole($sec, 'media', 'media', [], 'Banner media'),
    ];
  }

  /**
   * Derives realistic page content from the real text found in the Figma node.
   *
   * @return array
   *   Keys: 'roles' (config-named content roles => text, e.g. title/body/cta),
   *   'pairs', 'cards', 'features'.
   */
  protected function deriveContent(string $fallback_title, array $summary): array {
    $texts = $summary['texts'] ?? [];

    // Clean + de-duplicate, drop placeholder-ish and very short fragments.
    $clean = [];
    $seen = [];
    foreach ($texts as $t) {
      $s = trim(preg_replace('/\s+/', ' ', (string) $t['text']));
      if ($s === '' || mb_strlen($s) < 3) {
        continue;
      }
      $key = mb_strtolower($s);
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      $clean[] = ['text' => $s, 'size' => (float) ($t['size'] ?? 0), 'name' => (string) ($t['name'] ?? '')];
    }

    // A usable heading/label must contain a real word, not just a number or
    // symbol (Figma layers named "123456", "01", "#0d6efd" must not surface
    // as page titles).
    $is_wordish = static fn(string $s) => preg_match('/\p{L}{2,}/u', $s) === 1;

    // Keep a document-ordered copy for sequential heading→body pairing
    // (the size sort below destroys reading order).
    $ordered = $clean;

    // Headings = larger text; body = shorter/medium; long = paragraphs.
    usort($clean, static fn($a, $b) => $b['size'] <=> $a['size']);
    $headings = array_values(array_filter($clean, static fn($t) => $t['size'] >= 24 && mb_strlen($t['text']) <= 80 && $is_wordish($t['text'])));
    $paragraphs = array_values(array_filter($clean, static fn($t) => mb_strlen($t['text']) >= 40));

    $root = (string) ($summary['root_name'] ?? '');
    $title_candidate = $headings[0]['text'] ?? ($root !== '' ? $root : $fallback_title);
    // Full first paragraph, kept untrimmed for the heading->body de-dup below.
    $primary_body = $paragraphs[0]['text'] ?? '';

    // Pick a short string that looks like a button label, if any. Reject
    // anything carrying digits (dates like "Apr 28, 2024", versions, prices)
    // and anything that isn't a real word.
    $label = '';
    foreach ($clean as $t) {
      $w = str_word_count($t['text']);
      if ($w >= 1 && $w <= 3 && mb_strlen($t['text']) <= 24
        && $is_wordish($t['text'])
        && !preg_match('/\d/', $t['text'])
        && $t['text'] !== $title_candidate) {
        $label = $t['text'];
        break;
      }
    }

    // Build the semantic content roles from `content_roles` config: each role
    // names which bucket of node text it reads (heading / paragraph / label /
    // root), which item, and how much to keep. The role NAMES live in config,
    // so the title/body/cta vocabulary is not hard-coded here.
    $buckets = [
      'heading' => array_column($headings, 'text'),
      'paragraph' => array_column($paragraphs, 'text'),
      'label' => $label !== '' ? [$label] : [],
      'root' => $root !== '' ? [$root] : [],
    ];
    $role_defs = (array) ($this->settings()->get('content_roles') ?: []);
    $roles = [];
    foreach ($role_defs as $role => $def) {
      $src = (string) ($def['source'] ?? 'heading');
      $idx = (int) ($def['index'] ?? 0);
      $roles[(string) $role] = (string) ($buckets[$src][$idx] ?? '');
    }
    // Sensible fallbacks for the common roles, so a section always has content
    // even when the node text was sparse.
    $roles += ['title' => '', 'body' => '', 'cta' => ''];
    if ($roles['title'] === '') {
      $roles['title'] = $title_candidate;
    }
    if ($roles['body'] === '') {
      $roles['body'] = $primary_body !== '' ? $primary_body : ('Brought in from the Figma node "' . ($root ?: $fallback_title) . '".');
    }
    if ($roles['cta'] === '') {
      $roles['cta'] = 'Learn more';
    }
    // Trim each role to its configured max_length (applies to fallbacks too).
    foreach ($role_defs as $role => $def) {
      $max = (int) ($def['max_length'] ?? 0);
      if ($max > 0 && ($roles[(string) $role] ?? '') !== '') {
        $roles[(string) $role] = mb_substr($roles[(string) $role], 0, $max);
      }
    }

    // Heading → paragraph pairs (a generic content fallback for sections).
    $pairs = [];
    $used_body = [];
    foreach (array_slice($headings, 0, 6) as $h) {
      // Find a paragraph different from the hero body.
      $body = '';
      foreach ($paragraphs as $p) {
        if ($p['text'] !== $primary_body && !isset($used_body[$p['text']])) {
          $body = $p['text'];
          $used_body[$p['text']] = TRUE;
          break;
        }
      }
      if ($body === '') {
        $body = 'From the Figma node section "' . ($h['name'] ?: $h['text']) . '".';
      }
      $pairs[] = ['title' => mb_substr($h['text'], 0, 70), 'body' => $body];
    }

    // Cards pair each heading with the paragraph that follows it in reading
    // order, so each heading sits next to its own copy, not the next card's.
    // A short label sitting between a card's body and the next heading is taken
    // as that card's button ("Learn More About Varbase", "Read More").
    $cards = [];
    $pending = NULL;
    $awaiting_button = NULL;
    foreach ($ordered as $t) {
      $len = mb_strlen($t['text']);
      $is_heading = $t['size'] >= 24 && $len <= 80 && $is_wordish($t['text']);
      $is_paragraph = $len >= 40;
      $is_label = !$is_heading && !$is_paragraph && $len <= 30
        && $is_wordish($t['text'])
        && str_word_count($t['text']) <= 5;
      if ($is_heading) {
        $pending = $t['text'];
        $awaiting_button = NULL;
      }
      elseif ($pending !== NULL && $is_paragraph) {
        $cards[] = ['title' => mb_substr($pending, 0, 70), 'body' => $t['text'], 'button' => 'Learn more'];
        $awaiting_button = array_key_last($cards);
        $pending = NULL;
      }
      elseif ($awaiting_button !== NULL && $is_label) {
        $cards[$awaiting_button]['button'] = mb_substr($t['text'], 0, 40);
        $awaiting_button = NULL;
      }
    }

    // The node's paragraphs are the feature-grid bodies.
    $features = array_values(array_map(
      static fn($p) => $p['text'],
      array_slice($paragraphs, 0, 6),
    ));

    // Guarantee at least three items.
    if (count($pairs) < 3) {
      $color_list = implode(', ', array_slice(array_keys($summary['colors'] ?? []), 0, 6));
      $type_list = implode(', ', array_keys($summary['typography'] ?? [])) ?: 'theme defaults';
      $fallback = [
        ['title' => 'Brand colors', 'body' => 'Palette pulled from Figma: ' . ($color_list ?: 'none detected') . '.'],
        ['title' => 'Typography', 'body' => 'Type styles found in the node: ' . $type_list . '.'],
        ['title' => 'Source', 'body' => 'Built from the linked Figma design.'],
      ];
      while (count($pairs) < 3 && $fallback) {
        $pairs[] = array_shift($fallback);
      }
    }

    return [
      'roles' => $roles,
      'pairs' => $pairs,
      'cards' => $cards,
      'features' => $features,
    ];
  }

  /**
   * Returns the active version of a component, or empty string.
   */
  protected function activeVersion(string $component_id): string {
    if (!array_key_exists($component_id, $this->versionCache)) {
      $component = $this->entityTypeManager->getStorage('component')->load($component_id);
      $this->versionCache[$component_id] = $component ? (string) $component->getActiveVersion() : '';
    }
    return $this->versionCache[$component_id];
  }

  /**
   * Picks a "primary" brand color from the detected palette.
   *
   * Prefers a blue-ish brand color, else the first non-neutral, else first.
   */
  protected function pickPrimaryColor(array $colors): string {
    $hexes = array_keys($colors);
    if (!$hexes) {
      return '';
    }
    // Prefer a saturated, non-neutral color.
    foreach ($hexes as $hex) {
      if ($this->isVivid($hex)) {
        return $hex;
      }
    }
    return $hexes[0];
  }

  /**
   * Heuristic: is the hex a vivid (non-grey, non-near-white/black) color.
   */
  protected function isVivid(string $hex): bool {
    $h = ltrim(explode(' ', $hex)[0], '#');
    if (strlen($h) !== 6) {
      return FALSE;
    }
    $r = hexdec(substr($h, 0, 2));
    $g = hexdec(substr($h, 2, 2));
    $b = hexdec(substr($h, 4, 2));
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    // Saturation-ish spread and not near white/black.
    return ($max - $min) > 40 && $max > 60 && $min < 230;
  }

  /**
   * Returns the site's default theme machine name.
   */
  protected function defaultTheme(): string {
    return (string) $this->configFactory->get('system.theme')->get('default');
  }

  /**
   * The bare component names the target theme actually ships.
   *
   * Read from the SDC plugin manager (e.g. 'section', 'card-text').
   *
   * @return string[]
   *   Sorted list of component machine names (no prefix).
   */
  public function registryComponents(): array {
    // The full set of Single Directory Components the theme ships, read from
    // the SDC plugin manager (the real component list - broader than Canvas's
    // curated canvas.component.* subset). Plugin ids are "<theme>:<name>".
    $theme = $this->themeFromPrefix();
    $names = [];
    if ($this->componentManager) {
      foreach (array_keys($this->componentManager->getDefinitions()) as $id) {
        if (str_starts_with($id, $theme . ':')) {
          $names[] = substr($id, strlen($theme) + 1);
        }
      }
    }
    // Fallback to Canvas's registry if the SDC manager is unavailable.
    if (!$names) {
      $needle = 'canvas.component.' . $this->prefix() . '.';
      foreach ($this->configFactory->listAll($needle) as $config_name) {
        $names[] = substr($config_name, strlen($needle));
      }
    }
    sort($names);
    return array_values(array_unique($names));
  }

  /**
   * The theme machine name implied by the component prefix ("sdc.<theme>").
   */
  protected function themeFromPrefix(): string {
    $prefix = $this->prefix();
    return str_starts_with($prefix, 'sdc.') ? substr($prefix, 4) : $this->defaultTheme();
  }

  /**
   * Buckets the theme's live components by family, for the UI selects.
   *
   * Nothing is hard-coded: the lists come from whatever the theme registers.
   * Families are inferred from the name (card*, hero*, structural wrappers),
   * so any theme's own card/hero/section components appear automatically.
   *
   * @return array<string, string[]>
   *   ['structure','cards','heroes','content','all'] => component names.
   */
  public function componentsByFamily(): array {
    $all = $this->registryComponents();
    // Container/wrapper components: the named wrappers plus anything the theme
    // names "*-container" (section, group, container, row, column, fieldset,
    // details, *-slider-container, …).
    $wrappers = ['section', 'group', 'container', 'row', 'column', 'fieldset', 'details'];
    $fam = ['structure' => [], 'cards' => [], 'heroes' => [], 'content' => [], 'all' => $all];
    foreach ($all as $name) {
      if (in_array($name, $wrappers, TRUE) || str_ends_with($name, '-container')) {
        $fam['structure'][] = $name;
      }
      elseif (str_starts_with($name, 'card')) {
        // Any card or card-* type.
        $fam['cards'][] = $name;
      }
      elseif (str_starts_with($name, 'hero')) {
        // Any hero or hero-* type.
        $fam['heroes'][] = $name;
      }
      else {
        $fam['content'][] = $name;
      }
    }
    // Order structure so plain layout wrappers come first and specialised
    // containers (any "*-container" type) sink to the bottom - so a plain
    // section/group is always the default structural choice.
    $fam['structure'] = $this->preferStructure($fam['structure']);
    return $fam;
  }

  /**
   * Sorts structure components, plain wrappers first.
   *
   * Specialised containers (any "*-container" type, fieldset, details) sink to
   * the bottom.
   *
   * @param string[] $names
   *   Structure component names.
   *
   * @return string[]
   *   Re-ordered names.
   */
  protected function preferStructure(array $names): array {
    $preferred = ['section', 'group', 'container', 'row', 'column'];
    $rank = static function (string $n) use ($preferred): array {
      $i = array_search($n, $preferred, TRUE);
      // Preferred wrappers keep their listed order (0..n); everything else
      // (specialised "*-container" types, fieldset, details) ranks after them,
      // alphabetically.
      return $i !== FALSE ? [0, $i, $n] : [1, 0, $n];
    };
    usort($names, static fn($a, $b) => $rank($a) <=> $rank($b));
    return $names;
  }

  /**
   * Resolves a configured component choice for a structural family.
   *
   * Returns a full component id (prefix.name), falling back to the default.
   *
   * @param string $family
   *   One of: section, group, card, hero, cta, heading, text.
   * @param string $fallback
   *   The bare component name to use when nothing is configured.
   *
   * @return string
   *   The full component id, e.g. "sdc.vartheme_bs5.card-text".
   */
  public function componentChoice(string $family, string $fallback): string {
    $choices = (array) ($this->settings()->get('component_choices') ?: []);
    $name = (string) ($choices[$family] ?? '');
    return $this->prefix() . '.' . ($name !== '' ? $name : $fallback);
  }

  /**
   * The SDC plugin definition for a bare component name, or [].
   */
  protected function componentDef(string $name): array {
    if (array_key_exists($name, $this->defCache)) {
      return $this->defCache[$name];
    }
    if (!$this->componentManager) {
      return $this->defCache[$name] = [];
    }
    try {
      $def = (array) $this->componentManager->getDefinition($this->themeFromPrefix() . ':' . $name);
    }
    catch (\Throwable) {
      $def = [];
    }
    return $this->defCache[$name] = $def;
  }

  /**
   * The prop names a component declares (empty if unknown).
   *
   * @return string[]
   *   Declared prop machine names.
   */
  protected function declaredProps(string $name): array {
    return array_keys($this->componentDef($name)['props']['properties'] ?? []);
  }

  /**
   * The slot names a component declares (empty if none / unknown).
   *
   * @return string[]
   *   Declared slot machine names.
   */
  protected function declaredSlots(string $name): array {
    return array_keys($this->componentDef($name)['slots'] ?? []);
  }

  /**
   * Keeps only the props a component actually declares.
   *
   * Feeding a superset (heading_text, text, description, button_label, …) is
   * safe for ANY chosen component - each card/hero type takes the props it
   * knows and ignores the rest, instead of SDC rejecting unknown props.
   *
   * @param string $name
   *   Bare component name (e.g. 'card-icon').
   * @param array $dynamic
   *   Candidate prop values.
   *
   * @return array
   *   The subset of $dynamic the component declares (or all of it when the
   *   component definition is unknown - don't over-filter).
   */
  protected function filterProps(string $name, array $dynamic): array {
    $declared = $this->declaredProps($name);
    if (!$declared) {
      return $dynamic;
    }
    $kept = array_intersect_key($dynamic, array_flip($declared));
    // Satisfy required props the caller didn't supply, from the SDC schema's
    // own default / first enum value - so any chosen component validates.
    return $kept + $this->requiredDefaults($name, $kept);
  }

  /**
   * Default values for a component's required props that are not already set.
   *
   * Taken from the SDC prop schema (default, else first enum value).
   *
   * @return array
   *   prop => default value, for each missing required prop.
   */
  protected function requiredDefaults(string $name, array $present): array {
    $props = $this->componentDef($name)['props'] ?? [];
    $out = [];
    foreach (($props['required'] ?? []) as $req) {
      if (array_key_exists($req, $present)) {
        continue;
      }
      $schema = $props['properties'][$req] ?? [];
      if (array_key_exists('default', $schema)) {
        $out[$req] = $schema['default'];
      }
      elseif (!empty($schema['enum'])) {
        $out[$req] = $schema['enum'][0];
      }
    }
    return $out;
  }

  /**
   * Bare component name from a full component id ("prefix.name" → "name").
   */
  protected function bareName(string $component_id): string {
    $pos = strrpos($component_id, '.');
    return $pos === FALSE ? $component_id : substr($component_id, $pos + 1);
  }

  /**
   * Gathers a component's "intel" from its own files, memoised per request.
   *
   * Reads the SDC definition (its `.component.yml` props + slots), the
   * component's stories (`<name>.stories.json` - realistic example args for
   * every prop, including which props carry text), and its README/MDX
   * documentation. This is what makes assembly dynamic: instead of a fixed
   * prop list, the builder learns each component's real props and a working
   * baseline of values from the component's own stories.
   *
   * @param string $name
   *   Bare component name (e.g. 'card-text').
   *
   * @return array
   *   Keys: 'props', 'required', 'slots', 'story', 'doc'.
   */
  protected function componentIntel(string $name): array {
    if (isset($this->intelCache[$name])) {
      return $this->intelCache[$name];
    }
    $def = $this->componentDef($name);
    $intel = [
      'props' => $def['props']['properties'] ?? [],
      'required' => $def['props']['required'] ?? [],
      'slots' => array_keys($def['slots'] ?? []),
      'story' => [],
      'doc' => '',
    ];
    $path = (string) ($def['path'] ?? '');
    if ($path !== '' && is_dir($path)) {
      // Stories: take the first story's args as a working baseline.
      foreach ([$name . '.stories.json', $name . '.stories.yml'] as $sf) {
        $file = $path . '/' . $sf;
        if (is_file($file)) {
          $data = str_ends_with($sf, '.json')
            ? json_decode((string) file_get_contents($file), TRUE)
            : Yaml::decode((string) file_get_contents($file));
          $first = $data['stories'][0]['args'] ?? ($data['stories'][0]['props'] ?? []);
          if (is_array($first)) {
            $intel['story'] = $first;
          }
          break;
        }
      }
      // Documentation: README.md or the component's .mdx, trimmed.
      foreach (['README.md', $name . '.mdx', $name . '.md'] as $df) {
        $file = $path . '/' . $df;
        if (is_file($file)) {
          $intel['doc'] = mb_substr(trim((string) file_get_contents($file)), 0, 2000);
          break;
        }
      }
    }
    $this->intelCache[$name] = $intel;
    return $intel;
  }

  /**
   * Composes the prop values for a component instance dynamically.
   *
   * Layered, so any component type renders with sensible content:
   *   1. the component's own first-story args (valid baseline for required /
   *      enum props), minus placeholder text the stories ship;
   *   2. the Figma content mapped onto the props whose name / title / meaning
   *      matches (title → a heading prop, body → a text/description prop,
   *      button → a button-label prop, links → the link placeholder);
   *   3. any still-missing required props filled from the schema.
   * Finally filtered to the props the component declares.
   *
   * @param string $name
   *   Bare component name.
   * @param array $content
   *   Content keys 'title', 'body', 'button' - any may be empty.
   *
   * @return array
   *   The composed, declared-only prop values.
   */
  protected function composeProps(string $name, array $content): array {
    $intel = $this->componentIntel($name);
    $declared = array_keys($intel['props']);
    if (!$declared) {
      // Unknown component: pass the common prop names and let SDC sort it out.
      return array_filter([
        'heading_text' => $content['title'] ?? '',
        'text' => $content['body'] ?? '',
        'button_label' => $content['button'] ?? '',
      ], static fn($v) => $v !== '');
    }

    // 1. Story baseline, but drop the stories' placeholder copy from the
    //    text-ish props (we fill those from Figma), keep structural values.
    $values = [];
    foreach ((array) $intel['story'] as $prop => $val) {
      if (in_array($prop, $declared, TRUE) && is_scalar($val)) {
        $values[$prop] = $val;
      }
    }

    // 2. Map Figma content onto the matching props by meaning.
    $title = (string) ($content['title'] ?? '');
    $body = (string) ($content['body'] ?? '');
    $button = (string) ($content['button'] ?? '');
    $combined = trim(($title !== '' ? $title . ($body !== '' ? ': ' : '') : '') . $body);
    foreach ($declared as $prop) {
      $hay = strtolower($prop . ' ' . (string) ($intel['props'][$prop]['title'] ?? ''));
      $type = $intel['props'][$prop]['type'] ?? 'string';
      // Only fill free-text props (skip enums / booleans / objects).
      $is_text = ($type === 'string') && empty($intel['props'][$prop]['enum']);
      if (!$is_text) {
        continue;
      }
      if (preg_match('/url|link|href/', $hay) && !preg_match('/label|text|title/', $hay)) {
        $values[$prop] = self::LINK_PLACEHOLDER;
      }
      elseif (preg_match('/button|cta|link/', $hay) && preg_match('/label|text|title/', $hay)) {
        if ($button !== '') {
          $values[$prop] = $button;
        }
      }
      elseif (preg_match('/head|title|name|label/', $hay)) {
        $values[$prop] = $title !== '' ? $title : $body;
      }
      elseif (preg_match('/desc|text|body|summary|content|sub/', $hay)) {
        $values[$prop] = $body !== '' ? $body : $combined;
      }
    }

    // 3. Fill any still-missing required props from the schema.
    $values += $this->requiredDefaults($name, $values);

    // Declared-only safety net.
    return array_intersect_key($values, array_flip($declared));
  }

}
