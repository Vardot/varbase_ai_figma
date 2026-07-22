<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\varbase_ai_figma\VarbaseAiFigmaInstaller;

/**
 * Hook implementations for the Varbase AI Figma module.
 *
 * Varbase AI Figma carries NO prompt/rule text in code. Its Bootstrap 5.3 /
 * vartheme_bs5 build rules, accessibility rules, component-mapping governance
 * and the AI Context scope all live in config (varbase_ai_figma.profile.yml,
 * applied onto ai_figma.settings on install). The editable AI Context items are
 * created/updated by the general ai_figma seeder, which reads that config.
 */
final class VarbaseAiFigmaHooks {

  public function __construct(
    protected readonly VarbaseAiFigmaInstaller $installer,
    protected readonly ComponentSourceManager $componentSourceManager,
    protected readonly LoggerChannelFactoryInterface $logger,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_presave() for js_component.
   *
   * Code components are compiled in the browser editor; when created or
   * edited programmatically (e.g. by the Canvas AI tools) compiledJs is
   * left empty, so the component renders as "[object Object]". Compile
   * the JSX server-side with esbuild on every save so any save path
   * produces a renderable component.
   * Belt-and-braces safety net for canvas issue #3591751.
   */
  #[Hook('js_component_presave')]
  public function jsComponentPresave(EntityInterface $entity): void {
    $js = $entity->get('js');
    if (is_array($js) && !empty($js['original'])) {
      $changed = FALSE;

      // Generated source sometimes carries stray control characters. Canvas
      // rejects the save outright ("Text is not allowed to contain control
      // characters, only visible characters"), so the component never persists
      // and the assistant simply appears to hang - it retries and fails again,
      // with nothing surfaced to the person waiting.
      $original = $this->stripControlCharacters((string) $js['original']);

      // A generated component often names its React function after the human
      // label - "export default function Vdt Featured Card(" - which is not a
      // valid JavaScript identifier. esbuild rejects the whole file, the
      // compiled output is stored empty, and the component then renders as
      // "[object Object]". Repair the name before anything tries to compile it,
      // and keep the repaired source, so the code editor opens something valid
      // too.
      $original = $this->sanitizeDefaultExportName($original);

      if ($original !== (string) $js['original']) {
        $js['original'] = $original;
        $changed = TRUE;
      }

      $compiled = (string) ($js['compiled'] ?? '');
      if (trim($compiled) === '') {
        $compiled = $this->compileJsx($original);
      }
      // The compiled half is validated too, so clean it whether it came from
      // esbuild here or from the browser editor.
      $compiled = $this->stripControlCharacters($compiled);
      if ($compiled !== (string) ($js['compiled'] ?? '')) {
        $js['compiled'] = $compiled;
        $changed = TRUE;
      }

      if ($changed) {
        $entity->set('js', $js);
      }
    }
    // The browser also stores a compiled CSS; pass the source through
    // when empty so Bootstrap-based components keep their styles.
    $css = $entity->get('css');
    if (is_array($css) && !empty($css['original'])) {
      $css_original = $this->stripControlCharacters((string) $css['original']);
      $css_compiled = trim((string) ($css['compiled'] ?? '')) === ''
        ? $css_original
        : $this->stripControlCharacters((string) $css['compiled']);
      if ($css_original !== (string) $css['original'] || $css_compiled !== (string) ($css['compiled'] ?? '')) {
        $css['original'] = $css_original;
        $css['compiled'] = $css_compiled;
        $entity->set('css', $css);
      }
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for js_component.
   *
   * A code component created in the browser editor is added to the
   * component library by the author clicking "Add to components". Created
   * programmatically (e.g. by the Canvas AI tools) nobody clicks it, so
   * the component stays an
   * unpublished draft with no canvas.component.js.<name> entry — and the very
   * next request to place it fails with "The requested component <name> is not
   * available". The assistant then has to tell the author to go and register it
   * by hand, which defeats the point of asking it to build one.
   *
   * Enable the component and generate its Component config entity, which is the
   * same path the "Add to components" UI action takes. CreateCanvasPage already
   * does this for the components it is handed; doing it on insert covers the
   * components this module never sees, including those made by canvas_ai's own
   * create tool.
   *
   * Failure here must never lose the component the author just asked for,
   * so every step is guarded: the component is already saved by the time
   * this runs.
   */
  #[Hook('js_component_insert')]
  public function jsComponentInsert(EntityInterface $entity): void {
    $js = $entity->get('js');
    // A component with no source is a placeholder, not something to publish.
    if (!is_array($js) || empty($js['original'])) {
      return;
    }
    try {
      if (!$entity->status()) {
        $entity->enable()->save();
      }
      $this->componentSourceManager->generateComponents('js', [$entity->id()]);
    }
    catch (\Throwable $e) {
      $this->logger->get('varbase_ai_figma')->warning(
        'Could not add the code component @id to the component library: @message',
        ['@id' => $entity->id(), '@message' => $e->getMessage()],
      );
    }
  }

  /**
   * Implements hook_modules_installed().
   *
   * The AI Context items can only be created once ai_context's entity type
   * exists; cover the case where ai_context is enabled after this module.
   */
  #[Hook('modules_installed')]
  public function modulesInstalled(array $modules): void {
    if (in_array('ai_context', $modules, TRUE)) {
      $this->installer->seedContext();
    }
    // Re-wire the advanced Epic 2 tools when the Canvas AI agent arrives later.
    if (in_array('canvas_ai', $modules, TRUE)) {
      $this->installer->setOrchestratorTools(TRUE);
    }
  }

  /**
   * Removes control characters that Canvas refuses to store.
   *
   * Canvas validates both halves of the js/css field with "only visible
   * characters", so a single stray control character loses the whole component.
   * Tab, newline and carriage return are legitimate in source and are kept;
   * every other C0 control and DEL is dropped.
   *
   * @param string $text
   *   The source text.
   *
   * @return string
   *   The text with disallowed control characters removed.
   */
  protected function stripControlCharacters(string $text): string {
    return (string) preg_replace('/[^\P{Cc}\t\n\r]/u', '', $text);
  }

  /**
   * Makes the default-exported function name a valid JavaScript identifier.
   *
   * "export default function My Card(" cannot be parsed at all, so nothing
   * downstream can recover it. Collapsing the spaces keeps the intended name
   * ("My Card" becomes "MyCard") and leaves source that is already valid alone.
   *
   * @param string $source
   *   The JSX source code.
   *
   * @return string
   *   The source, with a parseable default-export function name.
   */
  protected function sanitizeDefaultExportName(string $source): string {
    $pattern = '/\bexport\s+default\s+function\s+([A-Za-z_$][\w$]*(?:[ \t]+[A-Za-z_$][\w$]*)+)\s*\(/';
    $fixed = preg_replace_callback(
      $pattern,
      static function (array $matches): string {
        return 'export default function ' . preg_replace('/[ \t]+/', '', $matches[1]) . '(';
      },
      $source
    );
    return $fixed ?? $source;
  }

  /**
   * Compiles JSX source to JavaScript with esbuild (matches the browser build).
   *
   * @param string $source
   *   The JSX source code.
   *
   * @return string
   *   The compiled JavaScript, or an empty string if esbuild is unavailable.
   */
  protected function compileJsx(string $source): string {
    if (trim($source) === '') {
      return '';
    }
    // Prefer the standalone esbuild binary (ELF, no Node needed) over the
    // node_modules/.bin/esbuild wrapper, whose "#!/usr/bin/env node" shebang
    // fails under php-fpm where Node is usually not on PATH.
    $candidates = [];
    foreach (['/../node_modules', '/node_modules', '/core/node_modules'] as $base) {
      $candidates = array_merge($candidates, glob(DRUPAL_ROOT . $base . '/@esbuild/*/bin/esbuild') ?: []);
      $candidates[] = DRUPAL_ROOT . $base . '/.bin/esbuild';
    }
    $bin = '';
    foreach ($candidates as $candidate) {
      if (@is_executable($candidate)) {
        $bin = $candidate;
        break;
      }
    }
    if ($bin === '') {
      return '';
    }
    $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
    $command = escapeshellarg($bin) . ' --loader=jsx --jsx=automatic --format=esm';
    $process = @proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
      return '';
    }
    fwrite($pipes[0], $source);
    fclose($pipes[0]);
    $compiled = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return ($code === 0 && is_string($compiled)) ? $compiled : '';
  }

}
