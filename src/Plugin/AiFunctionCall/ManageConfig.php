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
use Drupal\Component\Serialization\Yaml as DrupalYaml;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * AI Agent tool: list, read and change Drupal configuration.
 *
 * Gives the Canvas AI agent direct, permission-gated access to simple
 * configuration: list config names, read a config object, or set one or more
 * keys and save. For larger structured changes prefer the Apply Temp Recipe
 * tool (config actions); this tool is the scalpel.
 */
#[FunctionCall(
  id: 'varbase_ai_figma:config',
  function_name: 'varbase_figma_config',
  name: 'Change a site setting',
  description: 'Lists, reads and changes Drupal configuration. action=list returns config object names (optional prefix filter, e.g. "system."). action=get returns one config object as YAML. action=set updates one or more keys in a config object and saves - pass values as YAML mapping of key paths to values (e.g. "page.front: /page/1"). Use for quick site settings; for module installs or multi-object changes use the Apply Temp Recipe tool instead.',
  group: 'modification_tools',
  module_dependencies: ['ai_figma'],
  context_definitions: [
    'action' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Action'),
      description: new TranslatableMarkup('One of: list, get, set.'),
      required: TRUE,
    ),
    'name' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Config name'),
      description: new TranslatableMarkup('The config object name, e.g. "system.site". For list: an optional name prefix filter.'),
      required: FALSE,
    ),
    'values' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Values'),
      description: new TranslatableMarkup('For set: a YAML mapping of key paths to new values, e.g. "page.front: /page/1" or multiline for several keys.'),
      required: FALSE,
    ),
  ],
)]
class ManageConfig extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

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
    $instance->configFactory = $container->get('config.factory');
    $instance->loggerFactory = $container->get('logger.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    if (
      !$this->currentUser->hasPermission('administer site configuration')
      && !$this->currentUser->hasPermission('administer ai agents')
      && !$this->currentUser->hasPermission('use Drupal Canvas AI')
    ) {
      throw new \Exception('You do not have permission to manage configuration.');
    }

    $action = trim((string) $this->getContextValue('action'));
    $name = trim((string) $this->getContextValue('name'));

    switch ($action) {
      case 'list':
        $all = $this->configFactory->listAll($name);
        $this->result = sprintf("%d config objects%s:\n%s", count($all), $name !== '' ? " with prefix \"$name\"" : '', implode("\n", $all));
        break;

      case 'get':
        if ($name === '') {
          $this->result = 'A config name is required for get.';
          return;
        }
        if (!$this->allowedConfig($name)) {
          $this->result = sprintf('Refused: config "%s" is not in the set this tool may read. Allowed prefixes include ai_figma., ai_agents.ai_agent., canvas, system.site, node.type., field., core.entity_view_display., views.view., webform.webform., block.block., menu_link_content., pathauto., metatag.', $name);
          return;
        }
        $config = $this->configFactory->get($name);
        if ($config->isNew()) {
          $this->result = sprintf('Config "%s" does not exist.', $name);
          return;
        }
        $this->result = Yaml::dump($config->getRawData(), 6, 2);
        break;

      case 'set':
        if ($name === '') {
          $this->result = 'A config name is required for set.';
          return;
        }
        if (!$this->allowedConfig($name)) {
          $this->result = sprintf('Refused: config "%s" is not in the set this tool may write. Allowed prefixes include ai_figma., ai_agents.ai_agent., canvas, system.site, node.type., field., core.entity_view_display., views.view., webform.webform., block.block., menu_link_content., pathauto., metatag.', $name);
          return;
        }
        // Never write to obviously sensitive objects (keys, passwords, mail,
        // OAuth/consumer secrets) even when the prefix is otherwise allowed.
        if (preg_match('/(\.|^)(settings|key|password|secret|smtp|mail|oauth|consumer)/i', $name)) {
          $this->result = sprintf('Refused: config "%s" looks sensitive (settings/key/password/secret/smtp/mail/oauth/consumer); writing it is not permitted by this tool.', $name);
          return;
        }
        try {
          $values = DrupalYaml::decode((string) $this->getContextValue('values'));
        }
        catch (\Throwable $e) {
          $this->result = 'Invalid values YAML: ' . $e->getMessage();
          return;
        }
        if (!is_array($values) || !$values) {
          $this->result = 'Values must be a YAML mapping of key paths to new values.';
          return;
        }
        $editable = $this->configFactory->getEditable($name);
        foreach ($values as $key => $value) {
          $editable->set((string) $key, $value);
        }
        $editable->save();
        $this->result = sprintf("Done: saved %d key(s) in %s:\n%s", count($values), $name, Yaml::dump($values, 4, 2));
        $this->loggerFactory->get('ai_figma')->info('Config tool set @keys in @name.', [
          '@keys' => implode(', ', array_keys($values)),
          '@name' => $name,
        ]);
        break;

      default:
        $this->result = sprintf('Unknown action "%s". Use list, get or set.', $action);
    }
  }

  /**
   * Whether the tool may read/write a given config object.
   *
   * Restricts get/set to the configuration this tool is meant to touch (site
   * settings, content/field/display/view/webform/block/menu/SEO config and the
   * module's own + the AI agent config), so the agent can never read or change
   * arbitrary configuration through it. The list action is unrestricted (it
   * only returns names, no values).
   *
   * @param string $name
   *   The config object name.
   *
   * @return bool
   *   TRUE when the name is in the allow-list.
   */
  protected function allowedConfig(string $name): bool {
    $allowed_prefixes = [
      'ai_figma.',
      'ai_agents.ai_agent.',
      'canvas',
      'system.site',
      'node.type.',
      'field.',
      'core.entity_view_display.',
      'views.view.',
      'webform.webform.',
      'block.block.',
      'menu_link_content.',
      'pathauto.',
      'metatag.',
    ];
    foreach ($allowed_prefixes as $prefix) {
      if (str_starts_with($name, $prefix)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
