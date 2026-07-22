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
use Drupal\canvas\Entity\Pattern;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AI Agent tool: save a section (or a whole page) as a reusable Canvas Pattern.
 *
 * Turns work already built on a page into a reusable Pattern so the team can
 * insert it elsewhere later. Give a page id and a name; optionally a component
 * UUID to save just that section's subtree (that component and its descendants)
 * rather than the whole page. Patterns use static inputs only, so the saved
 * copy carries the current text/props and can be edited after it is inserted.
 */
#[FunctionCall(
  id: 'varbase_ai_figma:save_as_pattern',
  function_name: 'varbase_figma_save_as_pattern',
  name: 'Save a section already on a page for reuse',
  description: 'Save a section you already built on a Canvas page as a reusable Pattern, so it can be inserted on other pages later. Pass the page id and a human name for the pattern; optionally a section_uuid to save just that section (a component and its descendants) instead of the whole page. Returns the new pattern id.',
  group: 'modification_tools',
  module_dependencies: ['varbase_ai_figma', 'canvas'],
  context_definitions: [
    'page_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Source page id'),
      description: new TranslatableMarkup('The numeric id of the canvas_page whose components to save.'),
      required: TRUE,
    ),
    'label' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Pattern name'),
      description: new TranslatableMarkup('A human name for the new pattern, e.g. "Homepage CTA".'),
      required: TRUE,
    ),
    'section_uuid' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Section UUID'),
      description: new TranslatableMarkup('Optional: the UUID of a top-level component to save just that section (it and its descendants). Leave empty to save the whole page.'),
      required: FALSE,
    ),
  ],
)]
final class SaveAsPattern extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

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
      throw new \Exception('You do not have permission to save Canvas patterns.');
    }

    $page_id = trim((string) $this->getContextValue('page_id'));
    $label = trim((string) $this->getContextValue('label'));
    $section_uuid = trim((string) $this->getContextValue('section_uuid'));
    if ($page_id === '' || $label === '') {
      $this->result = 'Both page_id and a pattern name (label) are required.';
      return;
    }

    $page = $this->entityTypeManager->getStorage('canvas_page')->load($page_id);
    if (!$page) {
      $this->result = 'Canvas page "' . $page_id . '" was not found.';
      return;
    }
    $rows = (array) $page->get('components')->getValue();
    if ($rows === []) {
      $this->result = 'Page "' . $page_id . '" has no components to save.';
      return;
    }

    // Optionally reduce to one section's subtree.
    if ($section_uuid !== '') {
      $rows = $this->subtree($rows, $section_uuid);
      if ($rows === []) {
        $this->result = 'Section "' . $section_uuid . '" was not found on page ' . $page_id . '.';
        return;
      }
    }

    // Fresh UUIDs; the root of a pattern tree carries no parent_uuid/slot.
    $roots = [];
    foreach ($rows as $row) {
      if (empty($row['parent_uuid'])) {
        $roots[$row['uuid']] = TRUE;
      }
    }
    $map = [];
    foreach ($rows as $row) {
      $map[$row['uuid']] = $this->uuid->generate();
    }
    $tree = [];
    foreach ($rows as $row) {
      $isRoot = !empty($roots[$row['uuid']]);
      $new = [
        'uuid' => $map[$row['uuid']],
        'component_id' => $row['component_id'] ?? '',
        'component_version' => $row['component_version'] ?? '',
        'inputs' => $this->normalizeInputs($row['inputs'] ?? []),
      ];
      if (!$isRoot) {
        $new['parent_uuid'] = $map[$row['parent_uuid']] ?? ($section_uuid !== '' ? $map[array_key_first($roots)] : '');
        $new['slot'] = $row['slot'] ?? '';
      }
      $tree[] = $new;
    }

    $pid = 'vaf_' . substr(preg_replace('/[^a-z0-9_]+/', '_', strtolower($label)), 0, 56);
    $pid = trim($pid, '_');
    $storage = $this->entityTypeManager->getStorage('pattern');
    // Loop until the id is free (a single suffix can still collide).
    $base_id = $pid;
    while ($storage->load($pid)) {
      $pid = substr($base_id, 0, 50) . '_' . substr(hash('crc32b', $this->uuid->generate()), 0, 6);
    }

    try {
      Pattern::create([
        'id' => $pid,
        'label' => $label,
        'status' => TRUE,
        'component_tree' => array_values($tree),
      ])->save();
    }
    catch (\Throwable $e) {
      $this->result = 'Could not save the pattern: ' . $e->getMessage();
      return;
    }

    $this->result = 'Saved "' . $label . '" as pattern id "' . $pid . '" ('
      . count($tree) . ' components). Add it to any page later with "Add a saved section to a page" (insert_pattern).';
  }

  /**
   * Normalises a component row's inputs to an array for the pattern config.
   *
   * Page component rows may store inputs as an array or a JSON string; a
   * canvas.pattern config entity needs a decoded array, so normalise here.
   *
   * @param mixed $inputs
   *   The row inputs (array or JSON string).
   *
   * @return array
   *   The inputs as an array.
   */
  protected function normalizeInputs($inputs): array {
    if (is_string($inputs)) {
      $decoded = json_decode($inputs, TRUE);
      return is_array($decoded) ? $decoded : [];
    }
    return is_array($inputs) ? $inputs : [];
  }

  /**
   * Returns the subtree (a component and its descendants) from the rows.
   *
   * @param array[] $rows
   *   The full component rows.
   * @param string $root_uuid
   *   The UUID of the section root to keep.
   *
   * @return array[]
   *   The root and all its descendants, or an empty array if not found.
   */
  protected function subtree(array $rows, string $root_uuid): array {
    $by_parent = [];
    $by_uuid = [];
    foreach ($rows as $row) {
      $by_uuid[$row['uuid']] = $row;
      $by_parent[$row['parent_uuid'] ?? ''][] = $row['uuid'];
    }
    if (!isset($by_uuid[$root_uuid])) {
      return [];
    }
    $keep = [];
    $stack = [$root_uuid];
    while ($stack) {
      $uuid = array_pop($stack);
      $keep[] = $by_uuid[$uuid];
      foreach ($by_parent[$uuid] ?? [] as $child) {
        $stack[] = $child;
      }
    }
    // Detach the root so it becomes the pattern's top-level component.
    foreach ($keep as &$row) {
      if ($row['uuid'] === $root_uuid) {
        unset($row['parent_uuid'], $row['slot']);
      }
    }
    return $keep;
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
