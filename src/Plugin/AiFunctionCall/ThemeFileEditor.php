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
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AI Agent tool: read/list/write files of the site's active (default) theme.
 *
 * Scoped STRICTLY to the active default theme directory and to safe text
 * file types, so the Canvas AI agents can adjust theme CSS (e.g. Bootstrap
 * variable overrides in style.css) when a Figma build needs it. Reading and
 * listing cover css, js, yml, twig, md and svg; writing is deliberately
 * narrower (no twig) to avoid template injection.
 */
#[FunctionCall(
  id: 'varbase_ai_figma:theme_file',
  function_name: 'varbase_figma_theme_file',
  name: 'Edit the theme files',
  description: 'Reads, lists or writes text files inside the site\'s active (default) theme only. action=list lists files (optionally under a sub path), action=read returns a file\'s content (css, js, yml, twig, md or svg), action=write replaces/creates a file with the given content (css, js, yml, md or svg only - writing twig templates is refused to avoid template injection). Use to adjust theme CSS such as Bootstrap variable overrides in css/style.css when a design build requires it. After writing, caches are rebuilt automatically.',
  group: 'modification_tools',
  module_dependencies: ['ai_figma'],
  context_definitions: [
    'action' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Action'),
      description: new TranslatableMarkup('One of: list, read, write.'),
      required: TRUE,
    ),
    'path' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('File path'),
      description: new TranslatableMarkup('Path relative to the active theme directory, e.g. "css/style.css". For list, an optional sub directory.'),
      required: FALSE,
    ),
    'content' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Content'),
      description: new TranslatableMarkup('For write: the full new file content.'),
      required: FALSE,
    ),
  ],
)]
class ThemeFileEditor extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * Allowed file extensions for read/list.
   */
  protected const ALLOWED_EXTENSIONS = ['css', 'js', 'yml', 'twig', 'md', 'svg'];

  /**
   * Allowed file extensions for write (no twig - template injection risk).
   */
  protected const WRITABLE_EXTENSIONS = ['css', 'js', 'yml', 'md', 'svg'];

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The theme extension list.
   *
   * @var \Drupal\Core\Extension\ThemeExtensionList
   */
  protected $themeList;

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
    $instance->themeList = $container->get('extension.list.theme');
    $instance->loggerFactory = $container->get('logger.factory');
    $instance->configFactory = $container->get('config.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    if (
      !$this->currentUser->hasPermission('administer themes')
      && !$this->currentUser->hasPermission('administer ai agents')
      && !$this->currentUser->hasPermission('use Drupal Canvas AI')
    ) {
      throw new \Exception('You do not have permission to edit theme files.');
    }

    $action = trim((string) $this->getContextValue('action'));
    $rel = trim((string) $this->getContextValue('path'));

    // Operate on the site's active (default) theme, read from config, rather
    // than a hard-coded theme name.
    $theme = (string) $this->configFactory->get('system.theme')->get('default');
    if ($theme === '') {
      $this->result = 'No default theme is configured.';
      return;
    }
    $theme_dir = DRUPAL_ROOT . '/' . $this->themeList->getPath($theme);
    $base = realpath($theme_dir);
    if (!$base) {
      $this->result = sprintf('The %s theme directory was not found.', $theme);
      return;
    }

    // Resolve and jail the target path inside the theme directory.
    $target = $rel === '' ? $base : $base . '/' . ltrim($rel, '/');
    $resolved = $action === 'write' ? $this->resolveForWrite($base, $target) : realpath($target);
    if ($resolved === FALSE || !str_starts_with($resolved, $base)) {
      $this->result = sprintf('Path "%s" is outside the %s theme. Refused.', $rel, $theme);
      return;
    }

    switch ($action) {
      case 'list':
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(is_dir($resolved) ? $resolved : $base, \FilesystemIterator::SKIP_DOTS));
        $files = [];
        foreach ($rii as $file) {
          $files[] = substr($file->getPathname(), strlen($base) + 1);
        }
        sort($files);
        $this->result = sprintf("Files in %s:\n", $theme) . implode("\n", $files);
        break;

      case 'read':
        if (!is_file($resolved) || !$this->extensionAllowed($resolved)) {
          $this->result = sprintf('Cannot read "%s": not a readable theme text file (%s only).', $rel, implode(', ', self::ALLOWED_EXTENSIONS));
          return;
        }
        $this->result = (string) file_get_contents($resolved);
        break;

      case 'write':
        // Writing a Twig template into a live theme is template injection;
        // refuse it explicitly even though twig is readable.
        if (strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) === 'twig') {
          $this->result = sprintf('Refused to write "%s": writing Twig templates is not allowed (template injection risk). Only %s files may be written.', $rel, implode(', ', self::WRITABLE_EXTENSIONS));
          return;
        }
        if (!$this->writeExtensionAllowed($resolved)) {
          $this->result = sprintf('Refused to write "%s": only %s files may be written.', $rel, implode(', ', self::WRITABLE_EXTENSIONS));
          return;
        }
        $content = (string) $this->getContextValue('content');
        $dir = dirname($resolved);
        if (!is_dir($dir)) {
          mkdir($dir, 0775, TRUE);
        }
        file_put_contents($resolved, $content);
        drupal_flush_all_caches();
        $this->result = sprintf('Wrote %d bytes to %s/%s and rebuilt caches.', strlen($content), $theme, $rel);
        break;

      default:
        $this->result = sprintf('Unknown action "%s". Use list, read or write.', $action);
    }

    $this->loggerFactory->get('ai_figma')->info('Theme file tool: @action @path', [
      '@action' => $action,
      '@path' => $rel,
    ]);
  }

  /**
   * Resolves a write target (the file may not exist yet) inside the jail.
   */
  protected function resolveForWrite(string $base, string $target): string|false {
    $dir = realpath(dirname($target));
    if ($dir === FALSE || !str_starts_with($dir, $base)) {
      // Allow one level of not-yet-existing directories under the base.
      $parent = realpath(dirname(dirname($target)));
      if ($parent === FALSE || !str_starts_with($parent, $base)) {
        return FALSE;
      }
      return $parent . '/' . basename(dirname($target)) . '/' . basename($target);
    }
    return $dir . '/' . basename($target);
  }

  /**
   * Checks the read/list file extension allow-list.
   */
  protected function extensionAllowed(string $path): bool {
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::ALLOWED_EXTENSIONS, TRUE);
  }

  /**
   * Checks the (narrower) write file extension allow-list.
   */
  protected function writeExtensionAllowed(string $path): bool {
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::WRITABLE_EXTENSIONS, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
