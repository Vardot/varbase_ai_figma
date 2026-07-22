<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma\Plugin\AiFunctionCall;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\varbase_ai_figma\CanvasPageEditor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AI Agent tool: update the props of one placed component on a Canvas page.
 *
 * Fine-grained counterpart to the build tools: instead of rebuilding a section,
 * change just the props (inputs) of a single component instance, addressed by
 * its UUID. The provided inputs are MERGED with the existing ones, so you only
 * pass the props you want to change. This is what the build-then-verify loop
 * needs to refine a page after previewing it - fix one heading, swap one image,
 * correct one button label - without touching anything else.
 *
 * Adapted from ai_agents_experimental_collection's
 * ai_agents_canvas:update_component_inputs, reusing this module's
 * CanvasPageEditor so the tree/inputs encoding (JSON vs array) stays right.
 */
#[FunctionCall(
  id: 'varbase_ai_figma:update_component_inputs',
  function_name: 'varbase_figma_update_component_inputs',
  name: 'Fix the details of one thing on a page',
  description: 'Corrects the details of ONE thing already on a page - retitle a heading, swap an image, fix a button label - without rebuilding the section and losing the rest of the work. Only the details you send are changed; everything else on that component is left exactly as it was. Use it to correct a page after looking at it, rather than building it again. Address the component by its UUID. "inputs" is a JSON object of prop => value in Canvas input shape; pass only the props you want to change.',
  group: 'modification_tools',
  module_dependencies: ['varbase_ai_figma', 'canvas'],
  context_definitions: [
    'page_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Canvas page id'),
      description: new TranslatableMarkup('The numeric id of the canvas_page entity.'),
      required: TRUE,
    ),
    'component_uuid' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Component UUID'),
      description: new TranslatableMarkup('The UUID of the component instance to update (from the page component tree).'),
      required: TRUE,
    ),
    'inputs' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Inputs (JSON)'),
      description: new TranslatableMarkup('A JSON object of prop => value to merge into the component, e.g. {"heading_text":"New title"} or {"button":{"label":"Learn more"}}.'),
      required: TRUE,
    ),
  ],
)]
final class UpdateComponentInputs extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The Canvas page editor.
   */
  protected CanvasPageEditor $editor;

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
    $instance->editor = $container->get('varbase_ai_figma.page_editor');
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
      throw new \Exception('You do not have permission to edit Canvas components.');
    }

    $page_id = trim((string) $this->getContextValue('page_id'));
    $uuid = trim((string) $this->getContextValue('component_uuid'));
    $inputs_raw = trim((string) $this->getContextValue('inputs'));
    if ($page_id === '' || $uuid === '' || $inputs_raw === '') {
      $this->result = 'page_id, component_uuid and inputs are all required.';
      return;
    }

    $new_inputs = Json::decode($inputs_raw);
    if (!is_array($new_inputs) || $new_inputs === [] || array_is_list($new_inputs)) {
      $this->result = 'The "inputs" argument must be a non-empty JSON object of prop => value.';
      return;
    }

    $page = $this->entityTypeManager->getStorage('canvas_page')->load($page_id);
    if (!$page) {
      $this->result = 'Canvas page "' . $page_id . '" was not found.';
      return;
    }

    $rows = $this->editor->rows($page);
    $index = $this->editor->indexOf($rows, $uuid);
    if ($index === NULL) {
      $this->result = 'Component "' . $uuid . '" was not found on page ' . $page_id . '.';
      return;
    }

    // Merge each provided prop into the component (setProp preserves the row's
    // existing inputs encoding, array or JSON string).
    foreach ($new_inputs as $prop => $value) {
      $rows = $this->editor->setProp($rows, $index, (string) $prop, $value);
    }
    $this->editor->save($page, $rows);

    $this->result = 'Updated ' . count($new_inputs) . ' prop(s) on component ' . $uuid
      . ' in page "' . $page->label() . '" (id ' . $page->id() . '): '
      . implode(', ', array_keys($new_inputs)) . '.';
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
