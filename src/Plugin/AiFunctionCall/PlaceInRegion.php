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
use Drupal\canvas\Entity\PageRegion;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AI Agent tool: place components into a global theme region (header/footer).
 *
 * Builds the site header and footer the Drupal way: put the SYSTEM components
 * (blocks — Site branding, Main navigation, Footer menu, "Powered by", …) into
 * the theme's global header / footer page regions, falling back to a custom
 * code component only when no system block fits. Header and footer live in the
 * global regions once, so individual Canvas pages stay content-only.
 */
#[FunctionCall(
  id: 'varbase_ai_figma:place_in_region',
  function_name: 'varbase_figma_place_in_region',
  name: 'Put it in the header or footer',
  description: 'Builds the global header/footer of the default theme by placing one or more components into its "header" or "footer" page region (created/enabled when missing). PREFER existing SYSTEM blocks (pass their machine name, e.g. "system_branding_block", "system_menu_block.main", "system_menu_block.footer", "system_powered_by_block"); only fall back to a custom code component (pass its machine name, e.g. "header") when no system block fits. Pass several components at once as a comma-separated list in "components" (placed in order), e.g. components="system_branding_block, system_menu_block.main", region="header". For system blocks the required block inputs (label, label_display, level, depth, expand_all_items, …) are filled automatically from the block defaults. mode="replace" (default) replaces the region content; mode="append" adds to what is already there.',
  group: 'modification_tools',
  module_dependencies: ['ai_figma'],
  context_definitions: [
    'components' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Components'),
      description: new TranslatableMarkup('One or more component machine names, comma-separated, placed in order. System blocks (e.g. "system_branding_block", "system_menu_block.main") or a custom code component name (e.g. "header").'),
      required: TRUE,
    ),
    'region' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Region'),
      description: new TranslatableMarkup('The theme region: "header" or "footer".'),
      required: TRUE,
    ),
    'mode' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Mode'),
      description: new TranslatableMarkup('"replace" (default) replaces the region content; "append" adds to what is already there.'),
      required: FALSE,
    ),
  ],
)]
class PlaceInRegion extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

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
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The UUID generator.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuid;

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
    $instance->configFactory = $container->get('config.factory');
    $instance->uuid = $container->get('uuid');
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
      throw new \Exception('You do not have permission to edit page regions.');
    }

    $region = strtolower(trim((string) $this->getContextValue('region')));
    if (!in_array($region, ['header', 'footer'], TRUE)) {
      $this->result = sprintf('Unsupported region "%s". Use header or footer.', $region);
      return;
    }

    // Accept "components" as a comma-separated list.
    $raw = trim((string) $this->getContextValue('components'));
    $names = array_values(array_filter(array_map('trim', explode(',', $raw))));
    if (!$names) {
      $this->result = 'Provide one or more component machine names in "components".';
      return;
    }
    $mode = strtolower(trim((string) $this->getContextValue('mode'))) === 'append' ? 'append' : 'replace';

    $storage = $this->entityTypeManager->getStorage('component');
    $entries = [];
    $placed = [];
    $missing = [];
    foreach ($names as $name) {
      $component_id = $this->resolveComponentId($storage, $name);
      if ($component_id === NULL) {
        $missing[] = $name;
        continue;
      }
      /** @var \Drupal\canvas\Entity\Component $component */
      $component = $storage->load($component_id);
      $uuid = $this->uuid->generate();
      $entries[$uuid] = [
        'uuid' => $uuid,
        'component_id' => $component_id,
        'component_version' => $component->getActiveVersion(),
        'inputs' => $this->defaultInputs($component, $component_id),
        'label' => NULL,
        'parent_uuid' => NULL,
        'slot' => NULL,
      ];
      $placed[] = $component_id;
    }

    if (!$entries) {
      $this->result = sprintf('No matching components found for: %s. For system blocks pass the block machine name (e.g. system_branding_block, system_menu_block.main).', implode(', ', $missing));
      return;
    }

    $theme = (string) $this->configFactory->get('system.theme')->get('default');
    $region_id = $theme . '.' . $region;
    $page_region = PageRegion::load($region_id);
    if (!$page_region) {
      foreach (PageRegion::createFromBlockLayout($theme) as $created) {
        $created->enable();
        $created->save();
      }
      $page_region = PageRegion::load($region_id);
    }
    if (!$page_region) {
      $this->result = sprintf('The %s theme has no "%s" region available to Canvas.', $theme, $region);
      return;
    }

    $page_region->enable();
    $tree = $mode === 'append' ? ($page_region->get('component_tree') ?: []) : [];
    foreach ($entries as $uuid => $entry) {
      $tree[$uuid] = $entry;
    }
    $page_region->set('component_tree', $tree);
    $page_region->save();

    $this->result = sprintf(
      'Done: placed %s into the global %s region of %s (%s)%s.',
      implode(', ', $placed),
      $region,
      $theme,
      $mode === 'append' ? 'appended' : 'replaced',
      $missing ? '; not found: ' . implode(', ', $missing) : ''
    );
  }

  /**
   * Resolves a user-supplied name to an existing Canvas component entity id.
   *
   * Tries a custom code component first (js.<name>), then a system block
   * component (block.<name>), tolerating the "system_menu_block:main" /
   * "system_menu_block.main" derivative spellings and a "block." prefix.
   */
  protected function resolveComponentId($storage, string $name): ?string {
    $candidates = [];
    if (str_starts_with($name, 'js.') || str_starts_with($name, 'block.')) {
      $candidates[] = $name;
    }
    else {
      $candidates[] = 'js.' . $name;
      $candidates[] = 'block.' . $name;
      // Block derivative plugins use ":"; component ids use ".".
      $candidates[] = 'block.' . str_replace(':', '.', $name);
    }
    foreach ($candidates as $candidate) {
      if ($storage->load($candidate)) {
        return $candidate;
      }
    }
    return NULL;
  }

  /**
   * Builds the component instance inputs.
   *
   * Code components take no inputs here; system blocks reuse the block's own
   * default settings (so required keys like label_display, level, depth and
   * expand_all_items are always present and valid).
   */
  protected function defaultInputs($component, string $component_id): array {
    if (!str_starts_with($component_id, 'block.')) {
      return [];
    }
    $versioned = $component->get('versioned_properties');
    $settings = $versioned['active']['settings']['default_settings'] ?? [];
    // "id" and "provider" identify the block plugin, they are not inputs.
    unset($settings['id'], $settings['provider']);
    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
