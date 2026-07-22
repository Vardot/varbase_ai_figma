<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma\Plugin\AiFunctionCall;

use Drupal\varbase_recipes\Recipe\RecipeHelper;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
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
 * AI Agent tool: build a temporary Drupal recipe and apply it.
 *
 * The Drupal way to make site changes: the agent writes a small recipe
 * (install modules/themes + config actions), this tool materialises it in a
 * temp directory and runs it through the core RecipeRunner - the same
 * machinery `drush recipe` uses. Modeled on
 * \Drupal\varbase_recipes\Recipe\RecipeHelper.
 */
#[FunctionCall(
  id: 'varbase_ai_figma:apply_recipe',
  function_name: 'varbase_figma_apply_recipe',
  name: 'Install a set of features (recipe)',
  description: 'Builds a temporary Drupal recipe from the given YAML and applies it with the core RecipeRunner - the Drupal way to install modules/themes and run config actions (simpleConfigUpdate, createIfNotExists, set, ...). Pass complete recipe YAML with name, type, optional install list and optional config.actions. Use for site configuration changes a Figma build needs (enable a module, set a theme setting, update config) instead of ad-hoc edits. Changes are immediate and permanent - keep recipes small and targeted.',
  group: 'modification_tools',
  module_dependencies: ['ai_figma'],
  context_definitions: [
    'recipe_yaml' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Recipe YAML'),
      description: new TranslatableMarkup("Complete recipe.yml content, e.g.:\nname: 'Enable Blog Menu'\ntype: Site\ninstall:\n  - menu_ui\nconfig:\n  actions:\n    system.site:\n      simpleConfigUpdate:\n        page.front: '/page/1'"),
      required: TRUE,
    ),
  ],
)]
class ApplyTempRecipe extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

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
    $instance->fileSystem = $container->get('file_system');
    $instance->loggerFactory = $container->get('logger.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    // Recipes can install modules and themes, so this destructive tool requires
    // an admin-level permission - not the editor-level "use Drupal Canvas AI".
    if (
      !$this->currentUser->hasPermission('administer site configuration')
      && !$this->currentUser->hasPermission('administer ai agents')
    ) {
      throw new \Exception('You do not have permission to apply recipes.');
    }

    $yaml = trim((string) $this->getContextValue('recipe_yaml'));
    if ($yaml === '') {
      $this->result = 'Empty recipe YAML.';
      return;
    }

    // Validate the YAML and require the minimal recipe keys.
    try {
      $data = DrupalYaml::decode($yaml);
    }
    catch (\Throwable $e) {
      $this->result = 'Invalid YAML: ' . $e->getMessage();
      return;
    }
    if (!is_array($data) || empty($data['name'])) {
      $this->result = 'The recipe YAML must at least define a "name".';
      return;
    }
    $data += ['type' => 'Site', 'description' => 'Temporary recipe applied by the Canvas AI agent.'];

    // Prefer the varbase_recipes helper when available; identical fallback.
    try {
      if (class_exists('\Drupal\varbase_recipes\Recipe\RecipeHelper')) {
        $recipe = RecipeHelper::createRecipe($data);
      }
      else {
        $dir = uniqid($this->fileSystem->getTempDirectory() . '/recipes/');
        mkdir($dir, recursive: TRUE);
        file_put_contents($dir . '/recipe.yml', Yaml::dump($data, 6, 2));
        $recipe = Recipe::createFromDirectory($dir);
      }
      RecipeRunner::processRecipe($recipe);
    }
    catch (\Throwable $e) {
      $this->result = 'Recipe failed: ' . $e->getMessage();
      $this->loggerFactory->get('ai_figma')->error('Temp recipe failed: @msg', ['@msg' => $e->getMessage()]);
      return;
    }

    drupal_flush_all_caches();
    $this->result = sprintf('Done: recipe "%s" applied successfully (modules/config processed, caches rebuilt).', (string) $data['name']);
    $this->loggerFactory->get('ai_figma')->info('Temp recipe applied: @name', ['@name' => (string) $data['name']]);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
