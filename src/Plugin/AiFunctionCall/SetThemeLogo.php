<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma\Plugin\AiFunctionCall;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\ai_figma\FigmaContextClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * AI Agent tool: set the site logo from a Figma logo node.
 *
 * Solves the common "change the logo" request: a logo in Figma is usually a
 * vector group (no downloadable image fill), so this RENDERS the node via the
 * Figma Images API - SVG by default, so the logo stays crisp - saves it into
 * Drupal, and points the default theme's logo setting at it. The author no
 * longer has to export and upload the logo by hand.
 */
#[FunctionCall(
  id: 'varbase_ai_figma:set_theme_logo',
  function_name: 'varbase_figma_set_theme_logo',
  name: 'Set the site logo',
  description: 'Changes the site logo from a Figma logo. Renders the given Figma node as an image (SVG by default - logos are vectors, not image fills, so they must be rendered, not downloaded as a fill), saves it, and sets it as the default theme\'s logo. Use this for "change the logo", "replace the header logo", or "use this Figma logo for the site". Pass the Figma link/node of the logo. By default it updates the site-wide default theme logo; set apply_to_theme to a specific theme to target another.',
  group: 'modification_tools',
  module_dependencies: ['varbase_ai_figma'],
  context_definitions: [
    'figma_url' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Figma URL'),
      description: new TranslatableMarkup('A Figma link to the logo node; the file key and node id are extracted from it.'),
      required: FALSE,
    ),
    'file_key' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Figma file key'),
      description: new TranslatableMarkup('The Figma file key. Leave empty to use the link or the configured default file.'),
      required: FALSE,
    ),
    'node_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Logo node id'),
      description: new TranslatableMarkup('The node id of the logo, e.g. "7989-1361".'),
      required: FALSE,
    ),
    'format' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Format'),
      description: new TranslatableMarkup('svg (default, recommended for logos) or png.'),
      required: FALSE,
    ),
    'apply_to_theme' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Theme'),
      description: new TranslatableMarkup('Theme machine name to update. Leave empty for the site default theme.'),
      required: FALSE,
    ),
  ],
)]
class SetThemeLogo extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The Figma context client.
   */
  protected FigmaContextClient $figmaClient;

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
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

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
    $instance->figmaClient = $container->get('ai_figma.client');
    $instance->currentUser = $container->get('current_user');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->fileSystem = $container->get('file_system');
    $instance->httpClient = $container->get('http_client');
    $instance->configFactory = $container->get('config.factory');
    $instance->fileUrlGenerator = $container->get('file_url_generator');
    $instance->loggerFactory = $container->get('logger.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    if (
      !$this->currentUser->hasPermission('administer themes')
      && !$this->currentUser->hasPermission('administer ai agents')
    ) {
      throw new \Exception('You do not have permission to change the site logo (administer themes is required).');
    }

    // Resolve the file key + logo node id from the link/fields.
    $url = trim((string) $this->getContextValue('figma_url'));
    $file_key = trim((string) $this->getContextValue('file_key'));
    $node_id = trim((string) $this->getContextValue('node_id'));
    if ($url !== '') {
      $parsed = FigmaContextClient::parseFigmaUrl($url);
      $file_key = $parsed['file_key'] !== '' ? $parsed['file_key'] : $file_key;
      $node_id = $parsed['node_id'] !== '' ? $parsed['node_id'] : $node_id;
    }
    if ($file_key === '') {
      $file_key = $this->figmaClient->getDefaultFileKey();
    }
    if ($file_key === '' || $node_id === '') {
      $this->result = 'A Figma logo node is required (a link with a node id, or file_key + node_id).';
      return;
    }
    $format = strtolower(trim((string) $this->getContextValue('format'))) ?: 'svg';
    if (!in_array($format, ['svg', 'png'], TRUE)) {
      $format = 'svg';
    }

    // Render the logo node as an image (vector logos have no image fill).
    try {
      $images = $this->figmaClient->fetchImages($file_key, [$node_id], $format);
    }
    catch (\Throwable $e) {
      $this->result = 'Could not render the logo from Figma: ' . $e->getMessage();
      return;
    }
    $remote = '';
    foreach ($images as $u) {
      if ($u !== '') {
        $remote = $u;
        break;
      }
    }
    if ($remote === '') {
      $this->result = 'Figma did not return a rendered image for that node. Pick the logo group/frame node.';
      return;
    }

    // Save it into Drupal.
    $dir = 'public://figma';
    $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $name = 'logo-' . str_replace([':', ';'], '-', $node_id) . '.' . $format;
    $uri = $dir . '/' . $name;
    try {
      $data = (string) $this->httpClient->request('GET', $remote, ['timeout' => 60])->getBody();
      $this->fileSystem->saveData($data, $uri, FileExists::Replace);
    }
    catch (\Throwable $e) {
      $this->result = 'Downloading the rendered logo failed: ' . $e->getMessage();
      return;
    }
    $file = $this->entityTypeManager->getStorage('file')->create([
      'uri' => $uri,
      'filename' => $name,
      'status' => 1,
    ]);
    $file->save();

    // Point the theme's logo setting at the saved file.
    $theme = trim((string) $this->getContextValue('apply_to_theme'));
    if ($theme === '') {
      $theme = (string) $this->configFactory->get('system.theme')->get('default');
    }
    $settings = $this->configFactory->getEditable($theme . '.settings');
    $settings->set('logo.use_default', FALSE)
      ->set('logo.path', $uri)
      ->save();

    $this->loggerFactory->get('varbase_ai_figma')->info('set_theme_logo: @theme logo set from node @node (@fmt).', [
      '@theme' => $theme,
      '@node' => $node_id,
      '@fmt' => $format,
    ]);

    $this->result = Yaml::dump([
      'logo_set' => TRUE,
      'theme' => $theme,
      'node_id' => $node_id,
      'format' => $format,
      'file' => $name,
      'url' => $this->fileUrlGenerator->generateString($uri),
      'note' => 'The default theme now uses this logo. Reload the site to see it; clear caches if it does not appear.',
    ], 6, 2);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
