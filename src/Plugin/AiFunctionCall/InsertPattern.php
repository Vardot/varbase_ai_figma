<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma\Plugin\AiFunctionCall;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AI Agent tool: insert a ready-made Canvas Pattern onto a page.
 *
 * "Use them later": takes a saved pattern (a reusable section) and appends its
 * components onto a canvas_page, minting fresh UUIDs so the same pattern can be
 * placed many times. This is how a page is assembled fast from the saved
 * sections (listed by scan_inventory) instead of rebuilding each one.
 */
#[FunctionCall(
  id: 'varbase_ai_figma:insert_pattern',
  function_name: 'varbase_figma_insert_pattern',
  name: 'Add a saved section to a page',
  description: 'Puts a saved section (a Canvas Pattern) onto a page, so a section built once can be dropped onto any page instead of being rebuilt. This is the step that carries out a "reuse the saved section" decision: "See what we already have" lists the saved sections and "Decide what to reuse and what to build" says which to reuse, then this one places it. The section is appended with fresh UUIDs, so the same one can be placed more than once. Pass the pattern id and the target page id.',
  group: 'modification_tools',
  module_dependencies: ['varbase_ai_figma', 'canvas'],
  context_definitions: [
    'pattern_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Pattern id'),
      description: new TranslatableMarkup('The id of the canvas.pattern to insert (from scan_inventory kinds=pattern).'),
      required: TRUE,
    ),
    'page_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Target page id'),
      description: new TranslatableMarkup('The numeric id of the canvas_page to insert the pattern onto.'),
      required: TRUE,
    ),
  ],
)]
final class InsertPattern extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The UUID generator.
   */
  protected UuidInterface $uuid;

  /**
   * The tool's readable result.
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
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    if (
      !$this->currentUser->hasPermission('administer ai agents')
      && !$this->currentUser->hasPermission('use Drupal Canvas AI')
    ) {
      throw new \Exception('You do not have permission to insert Canvas patterns.');
    }

    $pattern_id = trim((string) $this->getContextValue('pattern_id'));
    $page_id = trim((string) $this->getContextValue('page_id'));
    if ($pattern_id === '' || $page_id === '') {
      $this->result = 'Both pattern_id and page_id are required.';
      return;
    }

    $pattern = $this->entityTypeManager->getStorage('pattern')->load($pattern_id);
    if (!$pattern) {
      $this->result = 'Pattern "' . $pattern_id . '" was not found. Use scan_inventory kinds=pattern to see the saved sections.';
      return;
    }
    $page = $this->entityTypeManager->getStorage('canvas_page')->load($page_id);
    if (!$page) {
      $this->result = 'Canvas page "' . $page_id . '" was not found.';
      return;
    }

    $tree = $pattern->get('component_tree');
    $rows = is_object($tree) && method_exists($tree, 'getValue') ? $tree->getValue() : (array) $tree;
    if ($rows === []) {
      $this->result = 'Pattern "' . $pattern_id . '" has no components to insert.';
      return;
    }

    // Fresh UUIDs so the pattern can be inserted repeatedly; remap parents.
    $map = [];
    foreach ($rows as $row) {
      if (isset($row['uuid'])) {
        $map[$row['uuid']] = $this->uuid->generate();
      }
    }
    $new_rows = [];
    foreach ($rows as $row) {
      // Skip malformed rows rather than duplicating stale references.
      if (!isset($row['uuid']) || !isset($map[$row['uuid']])) {
        continue;
      }
      $row['uuid'] = $map[$row['uuid']];
      if (!empty($row['parent_uuid']) && isset($map[$row['parent_uuid']])) {
        $row['parent_uuid'] = $map[$row['parent_uuid']];
      }
      $new_rows[] = $row;
    }

    $existing = (array) $page->get('components')->getValue();
    $page->set('components', array_values(array_merge($existing, $new_rows)));
    $page->save();

    $this->result = 'Inserted pattern "' . $pattern->label() . '" (' . count($new_rows)
      . ' components) onto page "' . $page->label() . '" (id ' . $page->id() . ').';
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
