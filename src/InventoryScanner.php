<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Plugin\PluginManagerInterface;

/**
 * Indexes everything the site can already reuse, with its REAL capabilities.
 *
 * The point of this service is to stop guessing. The previous matcher inferred
 * what a component could hold from its NAME; this one reads the actual Single
 * Directory Component schema (props with types and enums, slots), the actual
 * component tree of every saved Pattern, the registered Block plugins, and the
 * Views (including their block displays). Every candidate comes back in one
 * normalised shape so a design region can be scored against all four kinds at
 * once:
 *
 *   component | pattern | block | view
 *
 * Each candidate carries a capability profile:
 *   - roles:      the content roles it can actually hold (heading, body, image,
 *                 button, badge, list, icon, quote, stat, video, form), derived
 *                 from real prop names + types and slot names, not from the id.
 *   - props:      name => [type, enum, required]
 *   - slots:      slot names.
 *   - structure:  for patterns, the shape of the saved tree (how many of
 *                 what, nesting), so a "three cards in a row" design can
 *                 match a saved three-card pattern directly.
 *   - dynamic:    TRUE when the thing renders site content that changes (a
 *                 View, a listing block), so a repeating content region can
 *                 be matched to a real listing, not faked with static cards.
 *
 * Nothing here is theme-specific or hard-coded: the palette is whatever the
 * site currently ships.
 */
class InventoryScanner {

  /**
   * The content roles a design region can ask for.
   */
  /**
   * Per-request memo of scans, keyed by the kinds requested.
   *
   * @var array<string, array[]>
   */
  private array $memo = [];

  /**
   * What is never a candidate, read from varbase_ai_figma.settings.
   */
  private array $exclude;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PluginManagerInterface $sdcManager,
    private readonly BlockManagerInterface $blockManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly RoleVocabulary $vocabulary,
  ) {
    $this->exclude = (array) ($configFactory
      ->get('varbase_ai_figma.settings')
      ->get('resolver.exclude') ?: []);
  }

  /**
   * Scans everything reusable and returns one normalised candidate list.
   *
   * @param array $kinds
   *   Limit to these kinds: component, pattern, block, view. Empty = all.
   *
   * @return array[]
   *   Candidates keyed by "<kind>:<id>".
   */
  public function scan(array $kinds = []): array {
    // Resolving a design scores every region against the whole palette, and a
    // page has many regions, so the scan must not be paid for twice.
    sort($kinds);
    $memoKey = implode(',', $kinds);
    if (isset($this->memo[$memoKey])) {
      return $this->memo[$memoKey];
    }
    $want = fn(string $k): bool => $kinds === [] || in_array($k, $kinds, TRUE);
    $out = [];
    if ($want('component')) {
      $out += $this->scanComponents();
    }
    if ($want('pattern')) {
      $out += $this->scanPatterns();
    }
    if ($want('block')) {
      $out += $this->scanBlocks();
    }
    if ($want('view')) {
      $out += $this->scanViews();
    }
    $this->memo[$memoKey] = $out;
    return $out;
  }

  /**
   * Canvas components, with their real SDC prop/slot schema.
   */
  private function scanComponents(): array {
    $out = [];
    $never = array_map('mb_strtolower', (array) ($this->exclude['components'] ?? []));
    foreach ($this->entityTypeManager->getStorage('component')->loadMultiple() as $component) {
      $id = (string) $component->id();
      // Theme plumbing - the page shell, the styleguide pages - exists to
      // render the site, not to be placed in a design. Canvas registers
      // some of them anyway, so they have to be turned away here or they
      // get offered as an
      // answer to a design.
      if (in_array(mb_strtolower($this->leafName($id)), $never, TRUE)) {
        continue;
      }
      $source = (string) $component->get('source');
      $props = [];
      $slots = [];
      if ($source === 'sdc') {
        $local = (string) $component->get('source_local_id');
        if (!$this->sdcManager->hasDefinition($local)) {
          continue;
        }
        $def = $this->sdcManager->getDefinition($local);
        $required = (array) ($def['props']['required'] ?? []);
        foreach ((array) ($def['props']['properties'] ?? []) as $name => $spec) {
          if ($name === 'attributes') {
            continue;
          }
          $props[$name] = [
            'type' => is_array($spec['type'] ?? NULL) ? implode('|', $spec['type']) : (string) ($spec['type'] ?? 'string'),
            'enum' => array_values((array) ($spec['enum'] ?? [])),
            'required' => in_array($name, $required, TRUE),
          ];
        }
        $slots = array_keys((array) ($def['slots'] ?? []));
      }
      $out['component:' . $id] = [
        'kind' => 'component',
        'id' => $id,
        'label' => (string) $component->label(),
        'source' => $source,
        'version' => (string) $component->getActiveVersion(),
        'props' => $props,
        'slots' => $slots,
        'roles' => $this->rolesFrom(array_keys($props), $slots, $id),
        'structure' => [],
        'dynamic' => $source !== 'sdc',
      ];
    }
    return $out;
  }

  /**
   * Saved Canvas patterns, with the shape of their component tree.
   *
   * A pattern is a whole SECTION, so its structural signature (which
   * components, how many, how deep) is what a multi-part design region
   * should match against.
   */
  private function scanPatterns(): array {
    $out = [];
    foreach ($this->entityTypeManager->getStorage('pattern')->loadMultiple() as $pattern) {
      $tree = $pattern->get('component_tree');
      $rows = is_object($tree) && method_exists($tree, 'getValue') ? $tree->getValue() : (array) $tree;
      $counts = [];
      $roles = [];
      $depth = 0;
      $byUuid = [];
      $dynamic = FALSE;
      foreach ($rows as $row) {
        $cid = (string) ($row['component_id'] ?? '');
        if ($cid === '') {
          continue;
        }
        // Anything that is not a plain theme component pulls in live content:
        // a view, a listing block, a menu.
        if (!str_starts_with($cid, 'sdc.') && !str_starts_with($cid, 'js.')) {
          $dynamic = TRUE;
        }
        $leaf = $this->leafName($cid);
        $counts[$leaf] = ($counts[$leaf] ?? 0) + 1;
        $roles = array_merge($roles, $this->rolesFrom(array_keys((array) ($row['inputs'] ?? [])), [], $cid));
        if (!empty($row['uuid'])) {
          $byUuid[$row['uuid']] = $row['parent_uuid'] ?? NULL;
        }
      }
      foreach ($byUuid as $uuid => $_) {
        $d = 0;
        $cur = $uuid;
        while (!empty($byUuid[$cur])) {
          $cur = $byUuid[$cur];
          $d++;
          if ($d > 20) {
            break;
          }
        }
        $depth = max($depth, $d);
      }
      // How many times the pattern repeats its content. Count only the parts
      // that CARRY something: a saved section is padded with spacers and
      // wrappers, and counting those makes a single call-to-action banner look
      // like a four-item grid, because it happens to contain four spacers.
      $repeat = 0;
      foreach ($counts as $componentId => $n) {
        if ($this->isLayoutOnly((string) $componentId)) {
          continue;
        }
        $repeat = max($repeat, (int) $n);
      }
      $out['pattern:' . $pattern->id()] = [
        'kind' => 'pattern',
        'id' => (string) $pattern->id(),
        'label' => (string) $pattern->label(),
        'source' => 'pattern',
        'version' => '',
        'props' => [],
        'slots' => [],
        'roles' => array_values(array_unique($roles)),
        'structure' => [
          'components' => $counts,
          'total' => count($rows),
          'depth' => $depth,
          'repeat' => $repeat,
        ],
        // A saved section that embeds a view or a block shows REAL, changing
        // site content. "Latest Blog Posts" is exactly that - it carries a
        // views block - and calling it static made the engine penalise it for
        // not being live, then recommend building a new one instead. The
        // section it was rejecting was the answer.
        'dynamic' => $dynamic,
      ];
    }
    return $out;
  }

  /**
   * Registered Block plugins.
   *
   * Reuse an existing block; never author a custom one.
   */
  private function scanBlocks(): array {
    $out = [];
    foreach ($this->blockManager->getDefinitions() as $id => $def) {
      $id = (string) $id;
      if ($this->isNoiseBlock($id, (array) $def)) {
        continue;
      }
      $label = (string) ($def['admin_label'] ?? $id);
      $category = (string) ($def['category'] ?? '');
      $isViewsBlock = str_starts_with($id, 'views_block:');
      $out['block:' . $id] = [
        'kind' => 'block',
        'id' => $id,
        'label' => $label,
        'source' => 'block',
        'version' => '',
        'props' => [],
        'slots' => [],
        'roles' => $this->rolesFrom([], [], $id . ' ' . $label),
        'structure' => ['category' => $category],
        // A block renders live site content, so it can satisfy a "dynamic"
        // region (a menu, a listing, a search form) that static markup cannot.
        'dynamic' => TRUE,
        'views_block' => $isViewsBlock,
      ];
    }
    return $out;
  }

  /**
   * Views, and which of them already expose a block display.
   *
   * A design region that repeats real content (latest news, related posts, a
   * team grid) should reuse an existing View instead of freezing static cards.
   */
  private function scanViews(): array {
    $out = [];
    foreach ($this->entityTypeManager->getStorage('view')->loadMultiple() as $view) {
      // A disabled or administrative view is never page content. Offering an
      // accessibility report or a moderation queue as the "team grid" is worse
      // than offering nothing, so they are not candidates at all.
      if (!$view->status() || $this->isAdministrative($view)) {
        continue;
      }
      $displays = (array) $view->get('display');
      $blockDisplays = [];
      $base = '';
      foreach ($displays as $did => $d) {
        if (($d['display_plugin'] ?? '') === 'block') {
          $blockDisplays[] = (string) $did;
        }
      }
      $base = (string) ($view->get('base_table') ?? '');
      $out['view:' . $view->id()] = [
        'kind' => 'view',
        'id' => (string) $view->id(),
        'label' => (string) $view->label(),
        'source' => 'view',
        'version' => '',
        'props' => [],
        'slots' => [],
        'roles' => $this->viewRoles($view, $displays),
        'structure' => [
          'base_table' => $base,
          'block_displays' => $blockDisplays,
          'status' => $view->status(),
          // What it lists, so a "team grid" can tell Team from Blog.
          'subjects' => $this->viewSubjects($view),
        ],
        'dynamic' => TRUE,
      ];
    }
    return $out;
  }

  /**
   * Is this block plugin noise that no design would ever place?
   *
   * Most of the block registry is machinery, not page content. The
   * single-field blocks alone (one per field, per bundle) outnumber
   * everything else four to one, and a field block called "Image" will
   * happily win an "image" region and render nothing useful. Excluding
   * them makes the palette both smaller and more honest.
   *
   * Whether a component only shapes the layout and carries no content.
   *
   * A saved section is padded with spacers, wrappers and dividers. They are not
   * what the section repeats, so counting them makes a single call-to-action
   * banner look like a four-item grid because it contains four spacers.
   *
   * @param string $componentId
   *   The component id inside a pattern's tree.
   *
   * @return bool
   *   TRUE when the component is layout only.
   */
  private function isLayoutOnly(string $componentId): bool {
    $configured = $this->configFactory
      ->get('varbase_ai_figma.settings')
      ->get('resolver.exclude.layout_components');
    $layout = is_array($configured) && $configured
      ? $configured
      : ['spacer', 'section', 'divider', 'container', 'column', 'html-code'];

    $dot = strrpos($componentId, '.');
    $leaf = mb_strtolower($dot === FALSE ? $componentId : substr($componentId, $dot + 1));
    return in_array($leaf, array_map('mb_strtolower', $layout), TRUE);
  }

  /**
   * Whether a block is noise rather than something a design would ever want.
   *
   * @param string $id
   *   The block plugin id.
   * @param array $def
   *   The block plugin definition.
   *
   * @return bool
   *   TRUE when the block should not be offered to a design.
   */
  private function isNoiseBlock(string $id, array $def): bool {
    if ($id === 'broken') {
      return TRUE;
    }
    $noisyPrefixes = (array) ($this->exclude['block_prefixes'] ?? []);
    foreach ($noisyPrefixes as $prefix) {
      if (str_starts_with($id, $prefix) || $id === $prefix) {
        return TRUE;
      }
    }
    // Blocks the admin theme owns are not page content either.
    $category = mb_strtolower((string) ($def['category'] ?? ''));
    $adminCategories = array_map('mb_strtolower', (array) ($this->exclude['block_categories'] ?? []));
    if (!in_array($category, $adminCategories, TRUE)) {
      return FALSE;
    }
    // A design genuinely does place a menu, a breadcrumb or the site branding,
    // even though they live in an administrative category.
    foreach ((array) ($this->exclude['block_category_keep'] ?? []) as $keep) {
      if (str_contains($id, (string) $keep)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Is this view an administrative or internal listing, not page content?
   *
   * Admin views (moderation queues, accessibility reports, log tables, media
   * library pickers) look superficially like any other listing - they have a
   * title, an image, a date - so on roles alone they compete with the Blog view
   * for a "latest posts" region. They are never the right answer for a design,
   * so they are excluded from the palette entirely.
   *
   * @param object $view
   *   The view config entity.
   *
   * @return bool
   *   TRUE when the view is administrative or internal.
   */
  private function isAdministrative(object $view): bool {
    $id = (string) $view->id();
    $tag = (string) ($view->get('tag') ?? '');
    if (str_contains(mb_strtolower($tag), 'default')) {
      // Core's own default views are the admin listings (content, files,
      // users). They stay eligible only when they are genuinely
      // front-end listings, so
      // fall through to the path check rather than excluding on the tag alone.
      $tag = '';
    }
    foreach ((array) $view->get('display') as $display) {
      $opts = (array) ($display['display_options'] ?? []);
      $path = (string) ($opts['path'] ?? '');
      if ($path !== '' && str_starts_with($path, 'admin/')) {
        return TRUE;
      }
    }
    // Views whose base table is a log/report/internal table are never content.
    $base = (string) ($view->get('base_table') ?? '');
    // Match on WORD boundaries, never on substrings: "log" must not condemn
    // the "blog" view, and "audit" must not condemn "audition".
    $internals = (array) ($this->exclude['view_words'] ?? []);
    $words = preg_split('/[^a-z0-9]+/', mb_strtolower($id . ' ' . $base)) ?: [];
    if (array_intersect($words, $internals)) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * What this view actually lists: its entity type and bundles.
   *
   * Two views that both render teasers look identical on roles. What tells them
   * apart is WHAT they list - articles, team members, media. Without this, a
   * "team grid" region cannot tell the Blog view from the Team view.
   *
   * @param object $view
   *   The view config entity.
   *
   * @return string[]
   *   Lowercase subject words: the entity type plus every bundle it filters to.
   */
  private function viewSubjects(object $view): array {
    $subjects = [];
    $base = (string) ($view->get('base_table') ?? '');
    // node_field_data -> node, media_field_data -> media,
    // users_field_data -> user.
    $entity = preg_replace('/_field_data$|_field_revision$/', '', $base);
    if ($entity) {
      $subjects[] = mb_strtolower((string) $entity);
    }
    foreach ((array) $view->get('display') as $display) {
      $opts = (array) ($display['display_options'] ?? []);
      foreach ((array) ($opts['filters'] ?? []) as $name => $filter) {
        // A bundle filter is what makes a view "the article view" or "the team
        // view" rather than just "a node view".
        if (!in_array($name, ['type', 'bundle', 'vid'], TRUE)) {
          continue;
        }
        foreach ((array) ($filter['value'] ?? []) as $k => $v) {
          $bundle = is_string($v) && $v !== '' ? $v : (string) $k;
          if ($bundle !== '') {
            $subjects[] = mb_strtolower($bundle);
          }
        }
      }
    }
    // The label carries the human intent ("Team", "Blog", "Latest news").
    foreach (preg_split('/[^a-z0-9]+/', mb_strtolower((string) $view->label())) ?: [] as $word) {
      if (mb_strlen($word) >= 3) {
        $subjects[] = $word;
      }
    }
    return array_values(array_unique(array_filter($subjects)));
  }

  /**
   * The roles a View can genuinely render, read from its actual fields.
   *
   * A listing is only a real alternative to static cards if it can actually
   * show what the design asks for. So read the view's configured FIELDS (title,
   * body, image, created, …). When the view renders whole entities instead of
   * fields (a teaser row), it shows what that teaser shows, so credit it with
   * the roles a teaser carries.
   *
   * @param object $view
   *   The view config entity.
   * @param array $displays
   *   The view's displays.
   *
   * @return string[]
   *   The roles this view can render.
   */
  private function viewRoles(object $view, array $displays): array {
    $names = [];
    $rendersEntities = FALSE;
    foreach ($displays as $display) {
      $opts = (array) ($display['display_options'] ?? []);
      foreach (array_keys((array) ($opts['fields'] ?? [])) as $field) {
        $names[] = (string) $field;
      }
      $rowPlugin = (string) ($opts['row']['type'] ?? '');
      if ($rowPlugin !== '' && str_starts_with($rowPlugin, 'entity:')) {
        $rendersEntities = TRUE;
      }
    }
    $roles = $this->rolesFrom($names, [], (string) $view->id() . ' ' . (string) $view->label());
    // A view is a list by definition.
    $roles[] = 'list';
    if ($rendersEntities) {
      // A rendered teaser carries a title, its text, its image and its date.
      $roles = array_merge($roles, ['heading', 'body', 'image', 'date', 'link']);
    }
    return array_values(array_unique($roles));
  }

  /**
   * Derives the content roles a thing can hold, from REAL prop/slot names.
   *
   * The id/label is only a weak fallback signal; the prop and slot names are
   * what actually determine what content can be bound.
   *
   * @param string[] $propNames
   *   The real prop names.
   * @param string[] $slots
   *   The real slot names.
   * @param string $idHint
   *   The id/label, used only as a weak extra signal.
   *
   * @return string[]
   *   The roles this thing can hold.
   */
  public function rolesFrom(array $propNames, array $slots, string $idHint = ''): array {
    return $this->vocabulary->rolesOf($propNames, $slots, $idHint);
  }

  /**
   * The last dot-delimited segment of a component id.
   */
  public function leafName(string $componentId): string {
    $parts = explode('.', $componentId);
    return (string) end($parts);
  }

}
