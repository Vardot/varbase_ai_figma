<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma\Plugin\AiFunctionCall;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\varbase_ai_figma\InventoryScanner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AI Agent tool: show everything this site can already reuse.
 *
 * The full palette in one call: Canvas components (with their real props, enums
 * and slots), saved Patterns (with the shape of their component tree), Block
 * plugins, and Views (with their block displays). This is what makes the
 * reuse-before-create rule real rather than aspirational: the agent can see
 * what exists, and what each thing can hold, before deciding anything.
 */
#[FunctionCall(
  id: 'varbase_ai_figma:scan_inventory',
  function_name: 'varbase_figma_scan_inventory',
  name: 'See what we already have',
  description: 'Shows what this site can already reuse, so nothing is rebuilt that already exists: the components (with the settings and choices each one accepts), the saved sections, the blocks, and the lists (views). Use it before building anything from a design. Optional filters: "kinds" (component, pattern, block, view) and "roles" (heading, body, image, button, badge, list, icon, quote, stat, form).',
  group: 'information_tools',
  module_dependencies: ['varbase_ai_figma', 'canvas'],
  context_definitions: [
    'kinds' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Kinds'),
      description: new TranslatableMarkup('Comma separated: component, pattern, block, view. Empty = all four.'),
      required: FALSE,
    ),
    'roles' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Roles filter'),
      description: new TranslatableMarkup('Comma separated content roles, e.g. "heading,image,button". Only things that can hold ALL of them are listed.'),
      required: FALSE,
    ),
    'limit' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Limit'),
      description: new TranslatableMarkup('Max entries to return per kind (default 40).'),
      required: FALSE,
    ),
  ],
)]
final class ScanInventory extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The inventory scanner.
   */
  protected InventoryScanner $inventory;

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
    $instance->inventory = $container->get('varbase_ai_figma.inventory');
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
      throw new \Exception('You do not have permission to scan the component inventory.');
    }

    $kinds = array_values(array_filter(array_map('trim', explode(',', (string) $this->getContextValue('kinds')))));
    $roles = array_values(array_filter(array_map('trim', explode(',', (string) $this->getContextValue('roles')))));
    $limit = (int) $this->getContextValue('limit') ?: 40;

    $all = $this->inventory->scan($kinds);
    $byKind = [];
    foreach ($all as $c) {
      if ($roles && array_diff($roles, (array) $c['roles'])) {
        continue;
      }
      $byKind[$c['kind']][] = $c;
    }

    if (!$byKind) {
      $this->result = 'Nothing in the library matches that filter.';
      return;
    }

    $out = [];
    foreach ($byKind as $kind => $items) {
      $out[$kind . ' (' . count($items) . ')'] = array_map(
        fn(array $c) => $this->summarize($kind, $c),
        array_slice($items, 0, $limit)
      );
    }
    $this->result = Yaml::encode($out);
  }

  /**
   * One compact entry per candidate: what it is and what it can hold.
   */
  private function summarize(string $kind, array $c): array {
    $entry = [
      'id' => $c['id'],
      'label' => $c['label'],
      'holds' => $c['roles'] ? implode(', ', $c['roles']) : '(nothing declared)',
    ];
    if ($kind === 'component') {
      $props = [];
      foreach ($c['props'] as $name => $spec) {
        $props[$name] = $spec['enum']
          ? $spec['type'] . ' [' . implode('|', array_slice($spec['enum'], 0, 8)) . (count($spec['enum']) > 8 ? '|…' : '') . ']'
          : $spec['type'];
      }
      if ($props) {
        $entry['props'] = $props;
      }
      if ($c['slots']) {
        $entry['slots'] = implode(', ', $c['slots']);
      }
    }
    if ($kind === 'pattern') {
      $entry['section'] = sprintf(
        '%d components, depth %d, repeats %d',
        (int) ($c['structure']['total'] ?? 0),
        (int) ($c['structure']['depth'] ?? 0),
        (int) ($c['structure']['repeat'] ?? 0)
      );
      $comp = (array) ($c['structure']['components'] ?? []);
      arsort($comp);
      $entry['made_of'] = implode(', ', array_map(
        fn($n, $k) => $k . ' x' . $n,
        $comp,
        array_keys($comp)
      ));
    }
    if ($kind === 'view') {
      $entry['block_displays'] = implode(', ', (array) ($c['structure']['block_displays'] ?? [])) ?: '(none)';
      $entry['lists'] = (string) ($c['structure']['base_table'] ?? '');
    }
    if ($kind === 'block') {
      $entry['category'] = (string) ($c['structure']['category'] ?? '');
    }
    return $entry;
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
