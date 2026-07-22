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
 * AI Agent tool: manage Drupal menu links (list / add / remove).
 *
 * Lets the Canvas AI agent keep navigation in sync with a Figma design: when a
 * Canvas page belongs in the main / secondary / footer menu, add its link;
 * when a design drops a page from a menu, remove its link.
 */
#[FunctionCall(
  id: 'varbase_ai_figma:menu_link',
  function_name: 'varbase_figma_menu_link',
  name: 'Manage the menus',
  description: 'Lists, adds and removes Drupal menu links so navigation matches the design. action=list returns the links of a menu (main, secondary or footer). action=add adds a link to a menu pointing at a Canvas page (by numeric id or exact title) or any internal path ("/...") - optional title and weight. action=remove deletes a link from a menu, matched by its target (Canvas page id/title or internal path) or by exact link title. Use when a Canvas page created from a Figma frame belongs in - or is dropped from - the main/secondary/footer navigation.',
  group: 'modification_tools',
  module_dependencies: ['ai_figma'],
  context_definitions: [
    'action' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Action'),
      description: new TranslatableMarkup('One of: list, add, remove.'),
      required: TRUE,
    ),
    'menu' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Menu'),
      description: new TranslatableMarkup('Target menu machine name: "main", "secondary" or "footer". Defaults to "main".'),
      required: FALSE,
    ),
    'page' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Page or path'),
      description: new TranslatableMarkup('For add/remove: a Canvas page numeric id, its exact title, or an internal path starting with "/". For remove you may instead pass the exact link title in "link_title".'),
      required: FALSE,
    ),
    'link_title' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Link title'),
      description: new TranslatableMarkup('add: the menu link label (defaults to the page title). remove: match the link by this exact title when no page/path is given.'),
      required: FALSE,
    ),
    'weight' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Weight'),
      description: new TranslatableMarkup('add: optional integer weight controlling order (lower = earlier).'),
      required: FALSE,
    ),
  ],
)]
class ManageMenu extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

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
    $instance->loggerFactory = $container->get('logger.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    if (
      !$this->currentUser->hasPermission('administer menu')
      && !$this->currentUser->hasPermission('administer ai agents')
      && !$this->currentUser->hasPermission('use Drupal Canvas AI')
    ) {
      throw new \Exception('You do not have permission to manage menu links.');
    }

    $action = trim((string) $this->getContextValue('action'));
    $menus = ['main' => 'main', 'secondary' => 'secondary', 'footer' => 'footer'];
    $menu = $menus[strtolower(trim((string) $this->getContextValue('menu')))] ?? 'main';
    $storage = $this->entityTypeManager->getStorage('menu_link_content');

    if ($action === 'list') {
      $links = $storage->loadByProperties(['menu_name' => $menu]);
      if (!$links) {
        $this->result = sprintf('The %s menu has no content links.', $menu);
        return;
      }
      $rows = [];
      foreach ($links as $link) {
        $rows[] = sprintf('- "%s" → %s (weight %d)', $link->getTitle(), $link->getUrlObject()->toString(), (int) $link->getWeight());
      }
      $this->result = sprintf("%s menu links:\n%s", $menu, implode("\n", $rows));
      return;
    }

    // Add / remove both resolve a target path.
    $page_ref = trim((string) $this->getContextValue('page'));
    $link_title = trim((string) $this->getContextValue('link_title'));
    $path = '';
    $label = '';
    if ($page_ref !== '') {
      $page = $this->loadCanvasPage($page_ref);
      $path = $page ? '/page/' . $page->id() : $page_ref;
      $label = $page ? (string) $page->label() : $page_ref;
      if (!$page && !str_starts_with($path, '/')) {
        $this->result = sprintf('No Canvas page found for "%s". Pass a numeric id, exact title, or an internal path starting with "/".', $page_ref);
        return;
      }
    }

    switch ($action) {
      case 'add':
        if ($path === '') {
          $this->result = 'add requires a "page" (Canvas page id/title or internal path).';
          return;
        }
        $title = $link_title !== '' ? $link_title : $label;
        $existing = $storage->loadByProperties(['menu_name' => $menu, 'link__uri' => 'internal:' . $path]);
        if ($existing) {
          $this->result = sprintf('"%s" is already in the %s menu.', $title, $menu);
          return;
        }
        $link = $storage->create([
          'title' => $title,
          'menu_name' => $menu,
          'link' => ['uri' => 'internal:' . $path],
          'weight' => (int) trim((string) $this->getContextValue('weight')),
          'enabled' => TRUE,
        ]);
        $link->save();
        $this->result = sprintf('Done: added "%s" to the %s menu → %s.', $title, $menu, $path);
        break;

      case 'remove':
        $matches = [];
        if ($path !== '') {
          $matches = $storage->loadByProperties(['menu_name' => $menu, 'link__uri' => 'internal:' . $path]);
        }
        elseif ($link_title !== '') {
          $matches = $storage->loadByProperties(['menu_name' => $menu, 'title' => $link_title]);
        }
        else {
          $this->result = 'remove requires a "page"/path or a "link_title" to match.';
          return;
        }
        if (!$matches) {
          $this->result = sprintf('No matching link found in the %s menu to remove.', $menu);
          return;
        }
        $removed = [];
        foreach ($matches as $link) {
          $removed[] = $link->getTitle();
          $link->delete();
        }
        $this->result = sprintf('Done: removed %d link(s) from the %s menu: %s.', count($removed), $menu, implode(', ', $removed));
        break;

      default:
        $this->result = sprintf('Unknown action "%s". Use list, add or remove.', $action);
    }

    if ($action !== 'list') {
      $this->loggerFactory->get('ai_figma')->info('Menu tool: @result', ['@result' => $this->result]);
    }
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
