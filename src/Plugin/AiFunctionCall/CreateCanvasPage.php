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
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AI Agent tool: create (or update) a Canvas page from existing components.
 *
 * The multi-page primitive of a full-site build: makes a canvas_page entity
 * and places existing code components in its content, in order.
 */
#[FunctionCall(
  id: 'varbase_ai_figma:create_canvas_page',
  function_name: 'varbase_figma_create_canvas_page',
  name: 'Build the page',
  description: 'Builds the page and publishes it, placing each part into it in order. Prefer real, working building blocks (a live list, a real form) over copied markup, so the page keeps working after it is built. Pass "components" as a comma-separated list that may MIX: (a) custom code component machine names you created (e.g. "hero,stats"); (b) ready Drupal blocks / Views listing blocks by block id, e.g. "views_block.blog-all" or "views_block.blog-latest" for a blog/news/events/programs listing, or any "system_*"/"block.*" block; (c) a Webform via "webform:<id>" (e.g. "webform:contact", "webform:newsletter_subscribe") which places the Webform block bound to that form. Prefer these FUNCTIONAL, data-driven building blocks over hand-coded markup. Required block inputs (Views items_per_page, webform_id, label_display, …) are filled automatically. If a page with that title already exists its components are replaced. Chain with Figma: Site Wiring to set the front page or add menu links.',
  group: 'modification_tools',
  module_dependencies: ['ai_figma'],
  context_definitions: [
    'title' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Page title'),
      description: new TranslatableMarkup('The Canvas page title, e.g. "About Varbase".'),
      required: TRUE,
    ),
    'components' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Components'),
      description: new TranslatableMarkup('Comma-separated machine names of existing code components to place, in order, e.g. "hero,stats,cta" or a single "about_varbase".'),
      required: TRUE,
    ),
  ],
)]
class CreateCanvasPage extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

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
   * The UUID generator.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuid;

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
    $instance->uuid = $container->get('uuid');
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
      throw new \Exception('You do not have permission to create Canvas pages.');
    }

    $title = trim((string) $this->getContextValue('title'));
    $names = array_filter(array_map('trim', explode(',', (string) $this->getContextValue('components'))));
    if ($title === '' || !$names) {
      $this->result = 'Both a title and at least one component machine name are required.';
      return;
    }

    // Classify each requested name into a code component (js), a ready Drupal
    // block / Views block, or a Webform. This lets a page mix custom section
    // components with FUNCTIONAL, data-driven Drupal building blocks
    // (a Webform, a Views listing for blog/news/events/programs, a system
    // block) instead of hand-coded mockup markup.
    $component_storage = $this->entityTypeManager->getStorage('component');
    $js_storage = $this->entityTypeManager->getStorage('js_component');
    $resolved = [];
    $js_names = [];
    foreach ($names as $name) {
      $resolved[] = $spec = $this->classify($name, $component_storage, $js_storage);
      if ($spec['type'] === 'js') {
        $js_names[] = $spec['ref'];
      }
    }

    // Code components (js_component) created by the AI are unpublished drafts
    // (status FALSE) until they are "added to Canvas's component library". Only
    // library components are discoverable and placeable. Flag each requested
    // code component as added (status TRUE) and expose it as a
    // canvas.component.js.<name> Component config entity — the same path the
    // "Add to components" UI action takes — so the agent never has to do it by
    // hand in the editor. Ready blocks/Views/Webform components already exist.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponentDiscovery::discover()
    if ($js_names) {
      try {
        foreach ($js_names as $ref) {
          $js_component = $js_storage->load($ref);
          if ($js_component && !$js_component->status()) {
            $js_component->enable()->save();
          }
        }
        $this->componentSourceManager->generateComponents('js', $js_names);
      }
      catch (\Throwable $e) {
        $this->loggerFactory->get('ai_figma')->warning('Exposing code components failed: @m', ['@m' => $e->getMessage()]);
      }
    }

    // Resolve each spec to a placeable Component entity + its instance inputs.
    $items = [];
    $missing = [];
    $placed = [];
    foreach ($resolved as $spec) {
      $component = $component_storage->load($spec['component_id']);
      if (!$component) {
        $missing[] = $spec['label'];
        continue;
      }
      $items[] = [
        'parent_uuid' => NULL,
        'slot' => NULL,
        'uuid' => $this->uuid->generate(),
        'component_id' => $spec['component_id'],
        'component_version' => $component->getActiveVersion(),
        'inputs' => $this->buildInputs($spec, $component, $js_storage),
        'label' => NULL,
      ];
      $placed[] = $spec['label'];
    }
    if ($missing) {
      $existing_js = array_map(static fn(string $id): string => substr($id, 3), array_keys($component_storage->loadByProperties(['source' => 'js'])));
      $this->result = sprintf('Unknown component(s): %s. Existing code components: %s. For a ready block/Views listing pass its block id (e.g. "views_block.blog-all"); for a form pass "webform:<id>" (e.g. "webform:contact").', implode(', ', $missing), implode(', ', $existing_js) ?: '(none)');
      return;
    }

    $page_storage = $this->entityTypeManager->getStorage('canvas_page');
    $existing = $page_storage->loadByProperties(['title' => $title]);
    $page = $existing ? reset($existing) : $page_storage->create(['title' => $title, 'status' => TRUE]);
    $page->set('components', $items);
    $page->set('status', TRUE);
    $page->save();

    $this->result = sprintf('%s Canvas page %d ("%s") at /page/%d with component(s): %s.', $existing ? 'Updated' : 'Created', $page->id(), $title, $page->id(), implode(', ', $placed));
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
   * @return array
   *   Keyed: type, component_id, ref, label, webform_id.
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
    // or by the leaf name the design resolver speaks in ("card-hero"). Without
    // this the resolver's own recommendations were unbuildable: it would
    // correctly answer "reuse the Hero Slider", and the build step had no way
    // to place it, so it stopped and asked the person to pick something else.
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
   * id is "sdc.<theme>.<leaf>". Matching on the leaf keeps the build step
   * working whatever theme the site runs, without hard-coding a theme name.
   *
   * @param string $leaf
   *   The component's leaf machine name.
   * @param object $component_storage
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
   */
  protected function buildInputs(array $spec, $component, $js_storage): array {
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
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
