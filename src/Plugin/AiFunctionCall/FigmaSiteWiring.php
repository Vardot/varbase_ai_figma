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
 * AI Agent tool: wire a Canvas page into the site (front page, menus).
 *
 * Lets the Drupal Canvas AI Orchestrator finish a Figma-to-Canvas build the
 * Drupal way: set the created Homepage as the Drupal front page, and add
 * created pages to the main / secondary / footer menu.
 */
#[FunctionCall(
  id: 'varbase_ai_figma:site_wiring',
  function_name: 'varbase_figma_site_wiring',
  name: 'Connect the design to the real site',
  description: 'Wires a page into the site. action=set_front_page makes the given page the Drupal front page (use after creating the Homepage) - pass a Canvas page numeric id, its exact title, OR any internal path/link starting with "/" (e.g. "/node/5", "/blog"). action=add_to_menu adds a menu link for the given Canvas page or internal path to a Drupal menu (main, secondary or footer; use when the design shows the page in a navigation menu). action=set_path_alias gives the Canvas page a clean URL alias (e.g. /about-varbase) - set this on every page you create so its URL and menu link are human-readable; pass the wanted alias in "alias" or leave empty to auto-generate one from the page title.',
  group: 'modification_tools',
  module_dependencies: ['ai_figma'],
  context_definitions: [
    'action' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Action'),
      description: new TranslatableMarkup('One of: set_front_page, add_to_menu, set_path_alias.'),
      required: TRUE,
    ),
    'alias' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('URL alias'),
      description: new TranslatableMarkup('For set_path_alias: the URL alias starting with "/" (e.g. "/about-varbase"). Leave empty to auto-generate from the page title.'),
      required: FALSE,
    ),
    'page' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Page'),
      description: new TranslatableMarkup('The Canvas page numeric id (e.g. "1"), its exact title (e.g. "Homepage"), or any internal path starting with "/" (e.g. "/node/5", "/blog").'),
      required: TRUE,
    ),
    'menu' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Menu'),
      description: new TranslatableMarkup('For add_to_menu: the target menu - "main", "secondary" or "footer". Defaults to "main".'),
      required: FALSE,
    ),
    'link_title' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Link title'),
      description: new TranslatableMarkup('For add_to_menu: the menu link label. Defaults to the page title.'),
      required: FALSE,
    ),
    'weight' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Weight'),
      description: new TranslatableMarkup('For add_to_menu: optional integer weight controlling menu order (lower = earlier).'),
      required: FALSE,
    ),
  ],
)]
class FigmaSiteWiring extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

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
    $instance->entityTypeManager = $container->get('entity_type.manager');
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
      throw new \Exception('You do not have permission to wire pages into the site.');
    }

    $action = trim((string) $this->getContextValue('action'));
    $page_ref = trim((string) $this->getContextValue('page'));

    // Any internal path may be wired directly; Canvas pages may also be
    // referenced by id or exact title.
    $page = $this->loadCanvasPage($page_ref);
    $path = $page ? '/page/' . $page->id() : $page_ref;
    if (!$page && !str_starts_with($path, '/')) {
      $this->result = sprintf('No Canvas page found for "%s". Pass a numeric id, the exact title, or an internal path starting with "/".', $page_ref);
      return;
    }
    $label = $page ? $page->label() : $path;

    switch ($action) {
      case 'set_front_page':
        $this->configFactory->getEditable('system.site')
          ->set('page.front', $path)
          ->save();
        $this->result = sprintf('Done: "%s" is now the Drupal front page (system.site:page.front = %s).', $label, $path);
        break;

      case 'add_to_menu':
        $menus = ['main' => 'main', 'secondary' => 'secondary', 'footer' => 'footer'];
        $menu_in = strtolower(trim((string) $this->getContextValue('menu'))) ?: 'main';
        $menu = $menus[$menu_in] ?? 'main';
        $link_title = trim((string) $this->getContextValue('link_title')) ?: $label;
        $weight = (int) trim((string) $this->getContextValue('weight'));
        $storage = $this->entityTypeManager->getStorage('menu_link_content');
        $existing = $storage->loadByProperties([
          'menu_name' => $menu,
          'link__uri' => 'internal:' . $path,
        ]);
        if ($existing) {
          $this->result = sprintf('"%s" is already in the %s menu.', $label, $menu);
          return;
        }
        $link = $storage->create([
          'title' => $link_title,
          'menu_name' => $menu,
          'link' => ['uri' => 'internal:' . $path],
          'weight' => $weight,
          'enabled' => TRUE,
        ]);
        $link->save();
        $this->result = sprintf('Done: added "%s" to the %s menu, linking to %s.', $link_title, $menu, $path);
        break;

      case 'set_path_alias':
        if (!$page && !str_starts_with($path, '/page/')) {
          $this->result = 'set_path_alias needs a Canvas page (id or title).';
          return;
        }
        $alias = trim((string) $this->getContextValue('alias'));
        if ($alias === '') {
          // Auto-generate from the page title.
          $slug = strtolower((string) $label);
          $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
          $slug = trim((string) $slug, '-');
          $alias = '/' . ($slug !== '' ? $slug : 'page-' . $page->id());
        }
        elseif ($alias[0] !== '/') {
          $alias = '/' . $alias;
        }
        $alias_storage = $this->entityTypeManager->getStorage('path_alias');
        // Replace any existing alias for this page path.
        foreach ($alias_storage->loadByProperties(['path' => $path]) as $old) {
          $old->delete();
        }
        $alias_storage->create([
          'path' => $path,
          'alias' => $alias,
          'langcode' => 'und',
        ])->save();
        $this->result = sprintf('Done: Canvas page "%s" now has the URL alias %s (was %s).', $label, $alias, $path);
        break;

      default:
        $this->result = sprintf('Unknown action "%s". Use set_front_page, add_to_menu or set_path_alias.', $action);
    }

    $this->loggerFactory->get('ai_figma')->info('Site wiring: @result', ['@result' => $this->result]);
  }

  /**
   * Loads a Canvas page by numeric id or exact title.
   */
  protected function loadCanvasPage(string $ref): ?object {
    $storage = $this->entityTypeManager->getStorage('canvas_page');
    if (ctype_digit($ref)) {
      $page = $storage->load((int) $ref);
      if ($page) {
        return $page;
      }
    }
    $byTitle = $storage->loadByProperties(['title' => $ref]);
    return $byTitle ? reset($byTitle) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
