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
 * AI Agent tool: fetch the design's foundation images into Drupal media.
 *
 * Walks a Figma design (or a node), finds the real uploaded images placed in
 * it (image fills: logos, photos, illustrations), downloads each one, and
 * creates a reusable Image media entity for it - de-duplicating against media
 * the site already has. Returns the list of fetched images and which ones
 * already existed, so the agent can reuse the design's own imagery instead of
 * stock-photo placeholders.
 */
#[FunctionCall(
  id: 'varbase_ai_figma:fetch_media_assets',
  function_name: 'varbase_figma_fetch_media_assets',
  name: 'Collect the design files (logos, icons, photos)',
  description: 'Finds the foundation images in a Figma design (the real uploaded image fills - logos, photos, illustrations), downloads them, and creates reusable Image media entities for them, de-duplicating against media the site already has. Use this before building so components use the design\'s own imagery. Pass a Figma link (or file key) to scan the whole file, or a node id to scope to one section. Returns the fetched images, each with its media id and whether it was newly created or already existed.',
  group: 'modification_tools',
  module_dependencies: ['varbase_ai_figma', 'ai_figma'],
  context_definitions: [
    'figma_url' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Figma URL'),
      description: new TranslatableMarkup('A Figma link; the file key (and node id) are extracted from it. Leave empty to use the configured default file.'),
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
      label: new TranslatableMarkup('Node id'),
      description: new TranslatableMarkup('Optional node id to scope to one section, e.g. "9740-5207". Leave empty to scan the whole file.'),
      required: FALSE,
    ),
  ],
)]
class FetchMediaAssets extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

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
    $instance->fileUrlGenerator = $container->get('file_url_generator');
    $instance->moduleHandler = $container->get('module_handler');
    $instance->loggerFactory = $container->get('logger.factory');
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
      throw new \Exception('You do not have permission to fetch Figma media assets.');
    }
    if (!$this->moduleHandler->moduleExists('media')) {
      $this->result = 'The Media module is not enabled, so media assets cannot be created.';
      return;
    }

    // Resolve the file key (and an optional node id) from the link/fields.
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
    if ($file_key === '') {
      $this->result = 'No Figma file was given (link, file key, or configured default).';
      return;
    }

    // 1) Walk the design (or the node) and collect the image fills it uses.
    try {
      $nodes = $this->figmaClient->fetchNodes($file_key, $node_id);
      $refs = $this->figmaClient->collectImageRefs($nodes);
      // 2) Resolve each imageRef to its real download URL.
      $fills = $this->figmaClient->fetchImageFills($file_key);
    }
    catch (\Throwable $e) {
      $this->result = 'Could not read the Figma design images: ' . $e->getMessage();
      return;
    }

    if (!$refs) {
      $this->result = Yaml::dump([
        'file_key' => $file_key,
        'node_id' => $node_id ?: '(whole file)',
        'fetched' => [],
        'note' => 'No foundation images (image fills) were found in this design or node.',
      ], 4, 2);
      return;
    }

    $dir = 'public://figma';
    $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $fileStorage = $this->entityTypeManager->getStorage('file');
    $mediaStorage = $this->entityTypeManager->getStorage('media');
    $hasImageType = (bool) $this->entityTypeManager->getStorage('media_type')->load('image');

    $fetched = [];
    $created = 0;
    $existing = 0;
    foreach ($refs as $item) {
      $ref = $item['ref'];
      $label = $item['name'] ?: 'Figma image';
      // Stable filename keyed by the imageRef → the dedup key across re-runs.
      $key = substr(preg_replace('/[^a-z0-9]/i', '', $ref), 0, 24);
      $name = 'figma-asset-' . $key . '.png';
      $uri = $dir . '/' . $name;

      $row = [
        'name' => $label,
        'ref' => substr($ref, 0, 12) . '…',
        'file' => $name,
      ];

      // De-dup: a file at this uri means we already imported this asset.
      $files = $fileStorage->loadByProperties(['uri' => $uri]);
      if ($files) {
        $file = reset($files);
        $row['status'] = 'existing';
        $mids = $mediaStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('field_media_image.target_id', $file->id())
          ->range(0, 1)
          ->execute();
        if ($mids) {
          $row['media_id'] = (int) reset($mids);
        }
        elseif ($hasImageType) {
          // File present but no media yet → create the media for it.
          $row['media_id'] = $this->createMedia($mediaStorage, (int) $file->id(), $label);
        }
        $row['url'] = $this->fileUrlGenerator->generateString($uri);
        $existing++;
        $fetched[] = $row;
        continue;
      }

      // New asset → download and import.
      $remote = $fills[$ref] ?? '';
      if ($remote === '') {
        $row['status'] = 'unavailable';
        $row['note'] = 'Figma did not return a download URL for this image.';
        $fetched[] = $row;
        continue;
      }
      try {
        $data = (string) $this->httpClient->request('GET', $remote, ['timeout' => 60])->getBody();
      }
      catch (\Throwable $e) {
        $row['status'] = 'error';
        $row['note'] = 'Download failed: ' . $e->getMessage();
        $fetched[] = $row;
        continue;
      }
      // Exact-file check: the same image bytes may already be in the library
      // under a different Figma ref/name. Reuse it instead of storing a copy.
      $existingFid = $this->findFileByContentHash($data, $fileStorage);
      if ($existingFid !== NULL) {
        $row['status'] = 'existing';
        $row['note'] = 'Identical image already in the media library.';
        $mids = $mediaStorage->getQuery()->accessCheck(FALSE)
          ->condition('field_media_image.target_id', $existingFid)->range(0, 1)->execute();
        $row['media_id'] = $mids ? (int) reset($mids)
          : ($hasImageType ? $this->createMedia($mediaStorage, $existingFid, $label) : NULL);
        $existingFile = $fileStorage->load($existingFid);
        $row['url'] = $existingFile ? $this->fileUrlGenerator->generateString($existingFile->getFileUri()) : '';
        $existing++;
        $fetched[] = $row;
        continue;
      }
      try {
        $this->fileSystem->saveData($data, $uri, FileExists::Replace);
      }
      catch (\Throwable $e) {
        $row['status'] = 'error';
        $row['note'] = 'Save failed: ' . $e->getMessage();
        $fetched[] = $row;
        continue;
      }
      $file = $fileStorage->create(['uri' => $uri, 'filename' => $name, 'status' => 1]);
      $file->save();
      if (is_array($this->fileHashIndex)) {
        $this->fileHashIndex[md5($data)] = (int) $file->id();
      }
      $row['status'] = 'created';
      if ($hasImageType) {
        $row['media_id'] = $this->createMedia($mediaStorage, (int) $file->id(), $label);
      }
      $row['url'] = $this->fileUrlGenerator->generateString($uri);
      $created++;
      $fetched[] = $row;
    }

    $this->loggerFactory->get('ai_figma')->info('fetch_media_assets ran: @c created, @e existing of @t', [
      '@c' => $created,
      '@e' => $existing,
      '@t' => count($fetched),
    ]);

    $this->result = Yaml::dump([
      'file_key' => $file_key,
      'node_id' => $node_id ?: '(whole file)',
      'summary' => [
        'total' => count($fetched),
        'created' => $created,
        'already_existed' => $existing,
      ],
      'fetched' => $fetched,
      'hint' => 'Reference these media ids / urls in components instead of stock photos.',
    ], 6, 2);
  }

  /**
   * Cache of existing managed-file content hashes (md5 => file id).
   *
   * @var array|null
   */
  protected $fileHashIndex = NULL;

  /**
   * Finds an existing managed file whose content exactly matches the bytes.
   *
   * Hashes every image already imported under public://figma once, then matches
   * the given bytes against that index, so the exact same image is never stored
   * twice even when Figma hands it a different ref.
   *
   * @param string $data
   *   The downloaded image bytes.
   * @param \Drupal\Core\Entity\EntityStorageInterface $fileStorage
   *   The file storage.
   *
   * @return int|null
   *   The id of an existing file with identical content, or NULL.
   */
  protected function findFileByContentHash(string $data, $fileStorage): ?int {
    if (!is_array($this->fileHashIndex)) {
      $this->fileHashIndex = [];
      $ids = $fileStorage->getQuery()->accessCheck(FALSE)
        ->condition('uri', 'public://figma/%', 'LIKE')->execute();
      foreach ($fileStorage->loadMultiple($ids) as $existing) {
        $path = $this->fileSystem->realpath($existing->getFileUri());
        if ($path && is_file($path)) {
          $this->fileHashIndex[md5_file($path)] = (int) $existing->id();
        }
      }
    }
    return $this->fileHashIndex[md5($data)] ?? NULL;
  }

  /**
   * Creates an Image media entity for a file and returns its id.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $mediaStorage
   *   The media storage.
   * @param int $fid
   *   The managed file id.
   * @param string $label
   *   A human label / alt text for the image.
   *
   * @return int
   *   The new media id.
   */
  protected function createMedia($mediaStorage, int $fid, string $label): int {
    $media = $mediaStorage->create([
      'bundle' => 'image',
      'name' => $label,
      'field_media_image' => [
        'target_id' => $fid,
        'alt' => $label,
      ],
      'status' => 1,
    ]);
    $media->save();
    return (int) $media->id();
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
