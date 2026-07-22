<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma\Plugin\AiFunctionCall;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * AI Agent tool: save and reuse named improvement-prompt templates.
 *
 * Implements PRD story 2.21. Lets an author capture a reusable set of
 * improvement instructions (e.g. a quality checklist such as "fix headings,
 * generate missing alt text, tighten CTAs, add a meta description") under a
 * name, list saved templates, apply one (the tool returns its instructions so
 * the assistant runs them against the current page) or delete one. Templates
 * are stored in a single config object so the same standards can be reused
 * across pages without retyping.
 */
#[FunctionCall(
  id: 'varbase_ai_figma:prompt_template',
  function_name: 'varbase_figma_prompt_template',
  name: 'Suggest what to ask for next',
  description: 'Saves, lists, applies or deletes a named improvement-prompt template - a reusable set of instructions (e.g. a quality checklist: "fix headings, generate missing alt text, tighten CTAs, add a meta description") that can be applied to any page so the same standards are reused without retyping. action=save stores instructions under a name (overwriting any existing template of that name). action=list returns the saved templates with a short preview of each. action=apply returns the stored instructions for the assistant to execute against the current page. action=delete removes a template. apply returns the stored instructions for the assistant to execute against the current page.',
  group: 'modification_tools',
  module_dependencies: ['varbase_ai_figma', 'ai_figma'],
  context_definitions: [
    'action' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Action'),
      description: new TranslatableMarkup('One of: save, list, apply, delete.'),
      required: TRUE,
    ),
    'name' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Template name'),
      description: new TranslatableMarkup('The template name. Required for save, apply and delete; ignored for list.'),
      required: FALSE,
    ),
    'instructions' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Instructions'),
      description: new TranslatableMarkup('For save: the reusable improvement-prompt text to store (e.g. a quality checklist).'),
      required: FALSE,
    ),
    'description' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Description'),
      description: new TranslatableMarkup('For save (optional): a short human label for the template. Defaults to the name.'),
      required: FALSE,
    ),
  ],
)]
class PromptTemplate extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The config object name that stores the templates.
   */
  protected const CONFIG_NAME = 'varbase_ai_figma.prompt_templates';

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

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
    $instance->configFactory = $container->get('config.factory');
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
      throw new \Exception('You do not have permission to manage improvement-prompt templates.');
    }

    $action = trim((string) $this->getContextValue('action'));
    $name = trim((string) $this->getContextValue('name'));

    $config = $this->configFactory->getEditable(static::CONFIG_NAME);
    $templates = $config->get('templates') ?? [];

    switch ($action) {
      case 'save':
        if ($name === '') {
          $this->result = 'A template name is required for save.';
          return;
        }
        $instructions = trim((string) $this->getContextValue('instructions'));
        if ($instructions === '') {
          $this->result = 'Instructions are required for save.';
          return;
        }
        $description = trim((string) $this->getContextValue('description'));
        $machine = $this->machineName($name);
        if ($machine === '') {
          $this->result = sprintf('Could not derive a machine name from "%s"; use a name with letters or digits.', $name);
          return;
        }
        $templates[$machine] = [
          'label' => $description !== '' ? $description : $name,
          'instructions' => $instructions,
        ];
        $config->set('templates', $templates)->save();
        $this->result = sprintf('Saved improvement-prompt template "%s" (machine name: %s). Apply it later with action=apply, name="%s".', $templates[$machine]['label'], $machine, $name);
        break;

      case 'list':
        if (!$templates) {
          $this->result = 'No improvement-prompt templates saved yet. Save one with action=save, providing a name and the instructions to reuse.';
          return;
        }
        $rows = [];
        foreach ($templates as $machine => $template) {
          $instructions = (string) ($template['instructions'] ?? '');
          $rows[$machine] = [
            'label' => (string) ($template['label'] ?? $machine),
            'instructions_preview' => $this->preview($instructions),
          ];
        }
        $this->result = sprintf("%d improvement-prompt template(s):\n%s", count($rows), Yaml::dump($rows, 4, 2));
        break;

      case 'apply':
        if ($name === '') {
          $this->result = 'A template name is required for apply.';
          return;
        }
        $machine = $this->machineName($name);
        if (!isset($templates[$machine])) {
          $this->result = $this->notFound($name, $templates);
          return;
        }
        $template = $templates[$machine];
        $this->result = sprintf(
          "Apply these improvement instructions to the current page:\n\n%s\n\n(From template \"%s\".)",
          (string) ($template['instructions'] ?? ''),
          (string) ($template['label'] ?? $machine),
        );
        break;

      case 'delete':
        if ($name === '') {
          $this->result = 'A template name is required for delete.';
          return;
        }
        $machine = $this->machineName($name);
        if (!isset($templates[$machine])) {
          $this->result = $this->notFound($name, $templates);
          return;
        }
        $label = (string) ($templates[$machine]['label'] ?? $machine);
        unset($templates[$machine]);
        $config->set('templates', $templates)->save();
        $this->result = sprintf('Deleted improvement-prompt template "%s" (machine name: %s).', $label, $machine);
        break;

      default:
        $this->result = sprintf('Unknown action "%s". Use save, list, apply or delete.', $action);
        return;
    }

    $this->loggerFactory->get('ai_figma')->info('prompt_template ran: @s', ['@s' => $action]);
  }

  /**
   * Derives a machine name from a human template name.
   *
   * Lowercases the name and replaces every run of non-alphanumeric characters
   * with a single underscore, trimming leading/trailing underscores.
   *
   * @param string $name
   *   The human-readable template name.
   *
   * @return string
   *   The derived machine name, or an empty string when nothing usable remains.
   */
  protected function machineName(string $name): string {
    $machine = strtolower($name);
    $machine = preg_replace('/[^a-z0-9]+/', '_', $machine) ?? '';
    return trim($machine, '_');
  }

  /**
   * Builds a short single-line preview of instruction text.
   *
   * @param string $instructions
   *   The full instruction text.
   *
   * @return string
   *   A trimmed, single-line preview (truncated with an ellipsis when long).
   */
  protected function preview(string $instructions): string {
    $single = trim(preg_replace('/\s+/', ' ', $instructions) ?? '');
    if (mb_strlen($single) > 120) {
      return mb_substr($single, 0, 117) . '...';
    }
    return $single;
  }

  /**
   * Builds a "not found" message that lists the available template names.
   *
   * @param string $name
   *   The name the caller asked for.
   * @param array $templates
   *   The currently stored templates, keyed by machine name.
   *
   * @return string
   *   A message naming the requested template and listing what does exist.
   */
  protected function notFound(string $name, array $templates): string {
    if (!$templates) {
      return sprintf('No template named "%s" found. There are no saved templates yet; save one with action=save.', $name);
    }
    $available = [];
    foreach ($templates as $machine => $template) {
      $available[] = sprintf('%s ("%s")', $machine, (string) ($template['label'] ?? $machine));
    }
    return sprintf('No template named "%s" found. Available templates: %s.', $name, implode(', ', $available));
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
