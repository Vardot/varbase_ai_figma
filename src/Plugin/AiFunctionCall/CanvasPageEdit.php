<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\varbase_ai_figma\CanvasPageAnalyzer;
use Drupal\varbase_ai_figma\CanvasPageEditor;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AI Agent tool: targeted edits to an existing Canvas page's components.
 *
 * Where CreateCanvasPage builds (or rebuilds) a whole page, this tool performs
 * surgical, one-instance-at-a-time edits on an EXISTING canvas_page: move a
 * component, add/remove/swap a component, or change a section's column layout
 * or a single prop. The component to act on is identified in natural language -
 * by its label, its component machine name, or its uuid - and every edit
 * reports the resulting component order so the agent can confirm the change.
 *
 * Covers PRD stories 2.2 (move a component), 2.3 (add/remove/swap a component)
 * and 2.4 (adjust column layout).
 */
#[FunctionCall(
  id: 'varbase_ai_figma:page_edit',
  function_name: 'varbase_figma_page_edit',
  name: 'Change a page that already exists',
  description: 'Changes one thing on a page that already exists - move it, add it, remove it, swap it, or change how many columns a section has - instead of rebuilding the whole page and losing the rest of the work. The page is identified by its canvas_page id or exact title, and the component to act on is identified in natural language by its label, its component machine name, or its uuid. "action" is one of: move | add | remove | swap | set_columns | set_prop. For move/add use "position" (before | after | start | end, default end) and "anchor" (the reference component for before/after). For add/swap pass "component" (a code component machine name, "webform:<id>", "views_block.<id>" or "block.<id>"). For set_columns pass a Canvas columns value in "columns" (e.g. "50-50", "33-33-33", "100"). For set_prop pass "prop" and "value". When a target is ambiguous or not found the tool lists the page\'s components so you can disambiguate. Always reports the resulting component order so you can confirm.',
  group: 'modification_tools',
  module_dependencies: ['varbase_ai_figma', 'ai_figma'],
  context_definitions: [
    'page' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Page'),
      description: new TranslatableMarkup('The Canvas page to edit: its numeric canvas_page id, or its exact title.'),
      required: TRUE,
    ),
    'action' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Action'),
      description: new TranslatableMarkup('The edit to perform: one of move | add | remove | swap | set_columns | set_prop.'),
      required: TRUE,
    ),
    'target' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Target component'),
      description: new TranslatableMarkup('The component to act on, by uuid, exact label, or component machine name. Required for move, remove, swap, set_columns and set_prop.'),
      required: FALSE,
    ),
    'position' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Position'),
      description: new TranslatableMarkup('For move/add: before | after | start | end. Defaults to end.'),
      required: FALSE,
    ),
    'anchor' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Anchor component'),
      description: new TranslatableMarkup('For a before/after position: the reference component (uuid, label, or machine name) to place relative to.'),
      required: FALSE,
    ),
    'component' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Component to add/swap in'),
      description: new TranslatableMarkup('For add/swap: the component to place - a custom code component machine name (e.g. "hero"), a Webform via "webform:<id>", a Views listing block via "views_block.<id>", or any "block.<id>".'),
      required: FALSE,
    ),
    'columns' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Columns'),
      description: new TranslatableMarkup('For set_columns: a Canvas columns value such as "50-50", "33-33-33", or "100".'),
      required: FALSE,
    ),
    'prop' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Prop name'),
      description: new TranslatableMarkup('For set_prop: the name of the instance prop to set on the target component.'),
      required: FALSE,
    ),
    'value' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Prop value'),
      description: new TranslatableMarkup('For set_prop: the value to set the named prop to.'),
      required: FALSE,
    ),
  ],
)]
class CanvasPageEdit extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The Canvas page analyzer (loads + normalises an existing page).
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
   * The Canvas component source manager (exposes code components).
   *
   * @var \Drupal\canvas\ComponentSource\ComponentSourceManager
   */
  protected $componentSourceManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

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
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->analyzer = $container->get('varbase_ai_figma.page_analyzer');
    $instance->editor = $container->get('varbase_ai_figma.page_editor');
    $instance->componentSourceManager = $container->get(ComponentSourceManager::class);
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
    $action = strtolower(trim((string) $this->getContextValue('action')));
    $target = trim((string) $this->getContextValue('target'));
    $position = strtolower(trim((string) $this->getContextValue('position'))) ?: 'end';
    $anchor = trim((string) $this->getContextValue('anchor'));
    $component = trim((string) $this->getContextValue('component'));
    $columns = trim((string) $this->getContextValue('columns'));
    $prop = trim((string) $this->getContextValue('prop'));
    // The value is intentionally NOT trimmed: a prop value may need its
    // surrounding whitespace. Guard against a NULL context value first.
    $value = (string) ($this->getContextValue('value') ?? '');

    if ($page_ref === '' || $action === '') {
      $this->result = 'Both a "page" (canvas_page id or exact title) and an "action" are required.';
      return;
    }

    $allowed_actions = ['move', 'add', 'remove', 'swap', 'set_columns', 'set_prop'];
    if (!in_array($action, $allowed_actions, TRUE)) {
      $this->result = sprintf('Unknown action "%s". Allowed actions: %s.', $action, implode(', ', $allowed_actions));
      return;
    }

    // 1. Load the page.
    $page = $this->analyzer->loadPage($page_ref);
    if (!$page) {
      $this->result = sprintf('No Canvas page found for "%s". Pass a numeric canvas_page id or the page\'s exact title.', $page_ref);
      return;
    }

    $rows = $this->editor->rows($page);

    // 2. Resolve the target component when the action needs one. "add" is the
    // only action that does not require an existing target (it places a new
    // component, optionally anchored).
    $needs_target = $action !== 'add';
    $index = NULL;
    if ($needs_target) {
      if ($target === '') {
        $this->result = sprintf('Action "%s" needs a "target" component (uuid, label, or machine name).%s', $action, $this->componentListSuffix($page));
        return;
      }
      $index = $this->editor->indexOf($rows, $target);
      if ($index === NULL) {
        $this->result = sprintf('Could not uniquely identify the target "%s" on this page (not found or ambiguous).%s', $target, $this->componentListSuffix($page));
        return;
      }
    }

    // 3. Dispatch on the action.
    switch ($action) {
      case 'move':
        $anchor_index = $this->resolveAnchor($rows, $position, $anchor);
        if ($anchor_index === FALSE) {
          $this->result = sprintf('Position "%s" needs an "anchor" component, but "%s" could not be uniquely identified on this page.%s', $position, $anchor, $this->componentListSuffix($page));
          return;
        }
        $rows = $this->editor->move($rows, $index, $position, $anchor_index);
        $did = sprintf('Moved "%s" %s%s', $target, $position, $this->anchorPhrase($position, $anchor));
        break;

      case 'remove':
        $removed_label = $this->rowLabel($rows[$index] ?? []);
        $rows = $this->editor->remove($rows, $index);
        $did = sprintf('Removed "%s"', $removed_label);
        break;

      case 'add':
        if ($component === '') {
          $this->result = 'Action "add" needs a "component" to place (a code component machine name, "webform:<id>", "views_block.<id>", or "block.<id>").';
          return;
        }
        $spec = $this->resolveComponent($component);
        if ($spec === NULL) {
          $this->result = $this->unknownComponentMessage($component);
          return;
        }
        $anchor_index = $this->resolveAnchor($rows, $position, $anchor);
        if ($anchor_index === FALSE) {
          $this->result = sprintf('Position "%s" needs an "anchor" component, but "%s" could not be uniquely identified on this page.%s', $position, $anchor, $this->componentListSuffix($page));
          return;
        }
        $rows = $this->editor->insert($rows, $spec['component_id'], $spec['version'], $spec['inputs'], $position, $anchor_index);
        $did = sprintf('Added "%s" %s%s', $spec['label'], $position, $this->anchorPhrase($position, $anchor));
        break;

      case 'swap':
        if ($component === '') {
          $this->result = 'Action "swap" needs a "component" to swap in (a code component machine name, "webform:<id>", "views_block.<id>", or "block.<id>").';
          return;
        }
        $spec = $this->resolveComponent($component);
        if ($spec === NULL) {
          $this->result = $this->unknownComponentMessage($component);
          return;
        }
        $old_label = $this->rowLabel($rows[$index] ?? []);
        $rows = $this->editor->swap($rows, $index, $spec['component_id'], $spec['version'], $spec['declared_props']);
        $did = sprintf('Swapped "%s" for "%s"', $old_label, $spec['label']);
        break;

      case 'set_columns':
        if ($columns === '') {
          $this->result = 'Action "set_columns" needs a "columns" value, e.g. "50-50", "33-33-33", or "100".';
          return;
        }
        $rows = $this->editor->setProp($rows, $index, 'columns', $columns);
        $did = sprintf('Set columns of "%s" to "%s"', $target, $columns);
        break;

      case 'set_prop':
        if ($prop === '') {
          $this->result = 'Action "set_prop" needs a "prop" name (and a "value").';
          return;
        }
        $rows = $this->editor->setProp($rows, $index, $prop, $value);
        $did = sprintf('Set "%s" of "%s" to "%s"', $prop, $target, $value);
        break;

      default:
        // Unreachable: $action is validated against $allowed_actions above.
        $this->result = sprintf('Unhandled action "%s".', $action);
        return;
    }

    // 4. Persist and report the resulting component order.
    $this->editor->save($page, $rows);
    $this->result = sprintf(
      '%s on Canvas page %d ("%s"). Page saved. Resulting component order: %s.',
      $did,
      (int) $page->id(),
      (string) $page->label(),
      $this->orderSummary($page),
    );
    $this->loggerFactory->get('ai_figma')->info('page_edit ran: @s', ['@s' => sprintf('%s on page %d', $action, (int) $page->id())]);
  }

  /**
   * Resolves the anchor index for a before/after position.
   *
   * @param array[] $rows
   *   The current component rows.
   * @param string $position
   *   The requested position (before | after | start | end).
   * @param string $anchor
   *   The anchor reference (uuid, label, or machine name); may be empty.
   *
   * @return int|null|false
   *   The resolved anchor index for before/after; NULL when no anchor is
   *   needed (start/end, or before/after with no anchor given); FALSE when an
   *   anchor was given for before/after but could not be uniquely resolved.
   */
  protected function resolveAnchor(array $rows, string $position, string $anchor) {
    if (!in_array($position, ['before', 'after'], TRUE)) {
      return NULL;
    }
    if ($anchor === '') {
      // before/after with no anchor: fall back to start/end semantics handled
      // by the editor (anchor NULL).
      return NULL;
    }
    $anchor_index = $this->editor->indexOf($rows, $anchor);
    return $anchor_index ?? FALSE;
  }

  /**
   * Resolves a requested component reference to a placeable spec.
   *
   * Reuses CreateCanvasPage's classify()/buildInputs() approach: a code
   * component (js), a ready Drupal block / Views listing block, or a Webform.
   * Code components are exposed to Canvas's library (enabled + generated) so
   * they are discoverable and placeable, exactly as on a full page build.
   *
   * @param string $name
   *   The component reference.
   *
   * @return array{component_id:string, version:string, inputs:array, label:string, declared_props:string[]}|null
   *   The resolved spec, or NULL when no matching Component entity exists.
   */
  protected function resolveComponent(string $name): ?array {
    $component_storage = $this->entityTypeManager->getStorage('component');
    $js_storage = $this->entityTypeManager->getStorage('js_component');

    $spec = $this->classify($name, $component_storage, $js_storage);

    // Expose a code component to Canvas's component library so it is placeable
    // (enable the draft js_component, then generate its canvas.component.js.*
    // Component config entity), exactly as CreateCanvasPage does.
    if ($spec['type'] === 'js') {
      try {
        $js_component = $js_storage->load($spec['ref']);
        if ($js_component && !$js_component->status()) {
          $js_component->enable()->save();
        }
        $this->componentSourceManager->generateComponents('js', [$spec['ref']]);
      }
      catch (\Throwable $e) {
        $this->loggerFactory->get('ai_figma')->warning('Exposing code component failed: @m', ['@m' => $e->getMessage()]);
      }
    }

    $component = $component_storage->load($spec['component_id']);
    if (!$component) {
      return NULL;
    }

    return [
      'component_id' => $spec['component_id'],
      'version' => $component->getActiveVersion(),
      'inputs' => $this->buildInputs($spec, $component, $js_storage),
      'label' => $spec['label'],
      'declared_props' => $this->declaredProps($spec, $component, $js_storage),
    ];
  }

  /**
   * Classifies a requested name into a placeable component spec.
   *
   * Recognises three forms:
   * - "webform:<id>" (or "webform.<id>") → the Webform block bound to that
   *   form.
   * - a ready block / Views block ("views_block.blog-all", "block.<id>",
   *   "system_branding_block") → that Drupal block component.
   * - anything else → a custom code component (js_component) by machine name.
   *
   * Copied from CreateCanvasPage so the add/swap actions resolve components
   * identically to a full page build.
   *
   * @param string $name
   *   The requested component reference.
   * @param \Drupal\Core\Entity\EntityStorageInterface $component_storage
   *   The component config-entity storage.
   * @param \Drupal\Core\Entity\EntityStorageInterface $js_storage
   *   The js_component config-entity storage.
   *
   * @return array{type:string, component_id:string, ref:string, label:string, webform_id:string}
   *   The classified spec.
   */
  protected function classify(string $name, $component_storage, $js_storage): array {
    $name = trim($name);
    // Webform shorthand.
    if (preg_match('/^webform[:.]\s*(.+)$/i', $name, $m)) {
      return [
        'type' => 'webform',
        'component_id' => 'block.webform_block',
        'ref' => 'webform_block',
        'label' => 'webform:' . trim($m[1]),
        'webform_id' => trim($m[1]),
      ];
    }
    // A theme component, either by its full id ("sdc.vartheme_bs5.card-hero")
    // or by the leaf name the design resolver speaks in ("card-hero"). Kept in
    // sync with CreateCanvasPage::classify() so add/swap can place exactly
    // what a full page build can place (see findSdcComponentId()).
    if (str_starts_with($name, 'sdc.')) {
      if ($component_storage->load($name)) {
        return [
          'type' => 'sdc',
          'component_id' => $name,
          'ref' => $name,
          'label' => $name,
          'webform_id' => '',
        ];
      }
    }
    elseif (!str_contains($name, '.') && !$js_storage->load($name)) {
      // A bare leaf name only becomes a theme component when nothing else
      // claims it, so an author's own code component always wins.
      $sdc_id = $this->findSdcComponentId($name, $component_storage);
      if ($sdc_id !== '') {
        return [
          'type' => 'sdc',
          'component_id' => $sdc_id,
          'ref' => $sdc_id,
          'label' => $name,
          'webform_id' => '',
        ];
      }
    }
    // Explicit block id, or a Views block referenced loosely.
    $block_candidates = [];
    if (str_starts_with($name, 'block.')) {
      $block_candidates[] = $name;
    }
    elseif (str_contains($name, 'views_block')) {
      $block_candidates[] = 'block.' . ltrim($name, '.');
      $block_candidates[] = 'block.' . str_replace(':', '.', $name);
    }
    else {
      // A custom code component takes priority; fall back to a system block of
      // the same machine name (e.g. "system_powered_by_block").
      if (!$js_storage->load($name)) {
        $block_candidates[] = 'block.' . $name;
        $block_candidates[] = 'block.' . str_replace(':', '.', $name);
      }
    }
    foreach ($block_candidates as $candidate) {
      if ($component_storage->load($candidate)) {
        return [
          'type' => 'block',
          'component_id' => $candidate,
          'ref' => substr($candidate, 6),
          'label' => $candidate,
          'webform_id' => '',
        ];
      }
    }
    // Default: a custom code component.
    return [
      'type' => 'js',
      'component_id' => 'js.' . $name,
      'ref' => $name,
      'label' => $name,
      'webform_id' => '',
    ];
  }

  /**
   * Finds a theme component by the leaf name a designer or the resolver uses.
   *
   * The resolver talks about "card-hero" or "accordion-container"; the stored
   * id is "sdc.<theme>.<leaf>". Matching on the leaf keeps add/swap working
   * whatever theme the site runs, without hard-coding a theme name. Kept in
   * sync with CreateCanvasPage::findSdcComponentId().
   *
   * @param string $leaf
   *   The component's leaf machine name.
   * @param \Drupal\Core\Entity\EntityStorageInterface $component_storage
   *   The Component config entity storage.
   *
   * @return string
   *   The full component id, or an empty string when nothing matches.
   */
  protected function findSdcComponentId(string $leaf, $component_storage): string {
    foreach (array_keys($component_storage->loadByProperties(['source' => 'sdc'])) as $id) {
      $dot = strrpos($id, '.');
      if ($dot !== FALSE && substr($id, $dot + 1) === $leaf) {
        return $id;
      }
    }
    return '';
  }

  /**
   * Builds the instance inputs for a resolved component spec.
   *
   * Code components are seeded from each prop's example value (so they render
   * their real content even when the JS has no default parameter values). Block
   * and Views components reuse the block's own default settings (so required
   * keys are always present and valid); the Webform block additionally gets its
   * webform_id so it renders the chosen form.
   *
   * Copied from CreateCanvasPage so an added component renders the same way it
   * would on a full page build.
   *
   * @param array $spec
   *   The classified spec.
   * @param \Drupal\canvas\Entity\ComponentInterface $component
   *   The placeable Component config entity.
   * @param \Drupal\Core\Entity\EntityStorageInterface $js_storage
   *   The js_component config-entity storage.
   *
   * @return array
   *   The instance inputs.
   */
  protected function buildInputs(array $spec, $component, $js_storage): array {
    if ($spec['type'] === 'js') {
      $inputs = [];
      $js_component = $js_storage->load($spec['ref']);
      if ($js_component) {
        foreach (($js_component->get('props') ?? []) as $prop_name => $definition) {
          if (isset($definition['examples'][0])) {
            $inputs[$prop_name] = $definition['examples'][0];
          }
        }
      }
      return $inputs;
    }
    if ($spec['type'] === 'sdc') {
      // A theme component stores each prop's default under
      // prop_field_definitions; a placed instance stores plain prop => value.
      $inputs = [];
      $versioned = $component->get('versioned_properties');
      $definitions = $versioned['active']['settings']['prop_field_definitions'] ?? [];
      foreach ($definitions as $prop_name => $definition) {
        if (isset($definition['default_value'][0]['value'])) {
          $inputs[$prop_name] = $definition['default_value'][0]['value'];
        }
      }
      return $inputs;
    }
    // Block / Views / Webform: start from the block's default settings.
    $versioned = $component->get('versioned_properties');
    $settings = $versioned['active']['settings']['default_settings'] ?? [];
    unset($settings['id'], $settings['provider']);
    if ($spec['type'] === 'webform' && $spec['webform_id'] !== '') {
      $settings['webform_id'] = $spec['webform_id'];
    }
    return $settings;
  }

  /**
   * Best-effort list of the prop/setting names the new component declares.
   *
   * Passed to the editor's swap() so the incoming component keeps only the
   * inputs it actually understands. When nothing can be discovered an empty
   * array is returned, which makes swap() keep ALL existing inputs verbatim
   * (the safe default for a like-for-like swap).
   *
   * @param array $spec
   *   The classified spec.
   * @param \Drupal\canvas\Entity\ComponentInterface $component
   *   The placeable Component config entity.
   * @param \Drupal\Core\Entity\EntityStorageInterface $js_storage
   *   The js_component config-entity storage.
   *
   * @return string[]
   *   The declared prop/setting names, or an empty array when undiscoverable.
   */
  protected function declaredProps(array $spec, $component, $js_storage): array {
    if ($spec['type'] === 'js') {
      $js_component = $js_storage->load($spec['ref']);
      if ($js_component) {
        $props = $js_component->get('props');
        if (is_array($props) && $props !== []) {
          return array_keys($props);
        }
      }
      return [];
    }
    if ($spec['type'] === 'sdc') {
      $versioned = $component->get('versioned_properties');
      $definitions = $versioned['active']['settings']['prop_field_definitions'] ?? [];
      return array_keys($definitions);
    }
    // Block / Views / Webform: the declared inputs are the block's settings
    // keys. Returning these keeps a swap between two blocks coherent.
    $versioned = $component->get('versioned_properties');
    $settings = $versioned['active']['settings']['default_settings'] ?? [];
    unset($settings['id'], $settings['provider']);
    return array_keys($settings);
  }

  /**
   * Human-readable message for an unknown component reference.
   */
  protected function unknownComponentMessage(string $component): string {
    $component_storage = $this->entityTypeManager->getStorage('component');
    $existing_js = array_map(
      static fn(string $id): string => substr($id, 3),
      array_keys($component_storage->loadByProperties(['source' => 'js'])),
    );
    return sprintf(
      'Unknown component "%s". Existing code components: %s. For a ready block/Views listing pass its block id (e.g. "views_block.blog-all"); for a form pass "webform:<id>" (e.g. "webform:contact").',
      $component,
      implode(', ', $existing_js) ?: '(none)',
    );
  }

  /**
   * Builds a ", before/after \"anchor\"" phrase for the readable output.
   */
  protected function anchorPhrase(string $position, string $anchor): string {
    if (in_array($position, ['before', 'after'], TRUE) && $anchor !== '') {
      return sprintf(' "%s"', $anchor);
    }
    return '';
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
   * Comma-separated ordered list of the page's component labels/names.
   *
   * Re-reads the page tree via the analyzer so the order reflects the just
   * saved state.
   */
  protected function orderSummary($page): string {
    $names = [];
    foreach ($this->analyzer->tree($page) as $i => $row) {
      $label = ($row['label'] ?? NULL) !== NULL && (string) $row['label'] !== ''
        ? (string) $row['label']
        : ($row['component_id'] ?? '(unknown)');
      $names[] = sprintf('%d. %s [%s]', $i + 1, $label, $row['uuid'] ?? '');
    }
    return $names ? implode('; ', $names) : '(no components)';
  }

  /**
   * A trailing " Page components: …" suffix listing the page's components.
   *
   * Used in disambiguation/clarification messages so the agent can pick a
   * valid target (satisfies the "ambiguous instruction → clarifying question"
   * acceptance criterion).
   */
  protected function componentListSuffix($page): string {
    $tree = $this->analyzer->tree($page);
    if (!$tree) {
      return ' This page has no components.';
    }
    $items = [];
    foreach ($tree as $row) {
      $label = ($row['label'] ?? NULL) !== NULL && (string) $row['label'] !== ''
        ? (string) $row['label']
        : ($row['bare'] ?? $row['component_id'] ?? '(unknown)');
      $items[] = sprintf('%s (component_id=%s, uuid=%s)', $label, $row['component_id'] ?? '', $row['uuid'] ?? '');
    }
    return ' Page components: ' . implode('; ', $items) . '.';
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
