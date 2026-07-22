<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma\Plugin\AiFunctionCall;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileExists;
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
 * AI Agent tool: export Figma node images into Drupal files (+ media).
 *
 * Replaces stock-photo placeholders with the design's real imagery: renders
 * the given nodes via the Figma Images API, saves them under
 * public://figma/ and (when the media module is available) creates reusable
 * Image media entities.
 */
#[FunctionCall(
  id: 'varbase_ai_figma:export_figma_images',
  function_name: 'varbase_figma_export_images',
  name: 'Bring the images across',
  description: 'Renders Figma nodes as images (png/jpg/svg) via the Figma Images API and saves them into Drupal under public://figma/, returning a permanent site URL per node (and a media id when the media module is installed). Use the REAL design imagery in components instead of stock-photo placeholders: pass the node ids of image/illustration layers (from the node outline), then reference the returned URLs in the component markup.',
  group: 'modification_tools',
  module_dependencies: ['ai_figma'],
  context_definitions: [
    'figma_url' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Figma URL'),
      description: new TranslatableMarkup('A Figma link; the file key is extracted from it. Leave empty to use the configured default file.'),
      required: FALSE,
    ),
    'node_ids' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Node ids'),
      description: new TranslatableMarkup('Comma-separated Figma node ids to render, e.g. "9652:1031,9652:1067". Dash form is accepted.'),
      required: TRUE,
    ),
    'format' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Format'),
      description: new TranslatableMarkup('png (default), jpg or svg.'),
      required: FALSE,
    ),
  ],
)]
class ExportFigmaImages extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

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
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

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
    $instance->fileUrlGenerator = $container->get('file_url_generator');
    $instance->moduleHandler = $container->get('module_handler');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    if (
      !$this->currentUser->hasPermission('use ai figma design context')
      && !$this->currentUser->hasPermission('administer ai agents')
      && !$this->currentUser->hasPermission('use Drupal Canvas AI')
    ) {
      throw new \Exception('You do not have permission to export Figma images.');
    }

    $url = trim((string) $this->getContextValue('figma_url'));
    $file_key = $url !== '' ? FigmaContextClient::parseFigmaUrl($url)['file_key'] : '';
    if ($file_key === '') {
      $file_key = $this->figmaClient->getDefaultFileKey();
    }
    $node_ids = array_filter(array_map('trim', explode(',', (string) $this->getContextValue('node_ids'))));
    $format = strtolower(trim((string) $this->getContextValue('format'))) ?: 'png';
    if (!in_array($format, ['png', 'jpg', 'svg'], TRUE)) {
      $format = 'png';
    }
    if ($file_key === '' || !$node_ids) {
      $this->result = 'A Figma file (link or configured default) and at least one node id are required.';
      return;
    }

    $images = $this->figmaClient->fetchImages($file_key, $node_ids, $format);
    $dir = 'public://figma';
    $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $out = [];
    foreach ($images as $node_id => $remote) {
      if ($remote === '') {
        $out[$node_id] = ['error' => 'Figma could not render this node.'];
        continue;
      }
      $name = 'figma-' . str_replace([':', ';'], '-', $node_id) . '.' . $format;
      $uri = $dir . '/' . $name;
      try {
        $data = (string) $this->httpClient->request('GET', $remote, ['timeout' => 60])->getBody();
        $this->fileSystem->saveData($data, $uri, FileExists::Replace);
      }
      catch (\Throwable $e) {
        $out[$node_id] = ['error' => 'Download failed: ' . $e->getMessage()];
        continue;
      }

      $file = $this->entityTypeManager->getStorage('file')->create([
        'uri' => $uri,
        'filename' => $name,
        'status' => 1,
      ]);
      $file->save();

      $row = [
        'url' => $this->fileUrlGenerator->generateString($uri),
        'file_id' => (int) $file->id(),
      ];

      // Create a reusable media entity when the Image media type exists.
      if ($this->moduleHandler->moduleExists('media') && $format !== 'svg') {
        $media_types = $this->entityTypeManager->getStorage('media_type')->loadMultiple();
        if (isset($media_types['image'])) {
          $media = $this->entityTypeManager->getStorage('media')->create([
            'bundle' => 'image',
            'name' => $name,
            'field_media_image' => [
              'target_id' => $file->id(),
              'alt' => 'Figma design image ' . $node_id,
            ],
            'status' => 1,
          ]);
          $media->save();
          $row['media_id'] = (int) $media->id();
        }
      }
      $out[$node_id] = $row;
    }

    $this->result = Yaml::dump([
      'exported' => $out,
      'hint' => 'Reference each url in component markup (src="...") instead of stock photos.',
    ], 4, 2);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
