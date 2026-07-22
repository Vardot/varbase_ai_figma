<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma\Plugin\AiFunctionCall;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AI Agent tool: mint a short-lived, token-gated preview URL for a Canvas page.
 *
 * Returns an absolute URL to the varbase_ai_figma.token_preview route carrying
 * a single-use token (expirable key-value store, 300s TTL) bound to the given
 * page id. The controller consumes the token on first open, so the URL works
 * exactly ONCE (within 300s); mint a fresh one for each look. An automated,
 * UNAUTHENTICATED browser (browser_preview / browser_navigate) can open that
 * URL to render an UNPUBLISHED canvas_page draft without a login link, so the
 * agent can visually inspect / screenshot a page right after building it.
 */
#[FunctionCall(
  id: 'varbase_ai_figma:preview_token_url',
  function_name: 'varbase_figma_preview_token_url',
  name: 'Look at the page in a real browser',
  description: 'Lets the agent actually look at a page it just built, instead of trusting it worked. Give the numeric page_id; returns one absolute preview URL a headless, NOT-logged-in browser (browser_preview / browser_navigate) can open to render the page WITHOUT a login link - including UNPUBLISHED drafts. IMPORTANT: the URL is SINGLE USE - it works exactly once, within 300 seconds. Open it once (one navigate) to inspect or screenshot; if you need to look again, call this tool again for a fresh URL.',
  group: 'information_tools',
  module_dependencies: ['varbase_ai_figma', 'canvas'],
  context_definitions: [
    'page_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Page ID'),
      description: new TranslatableMarkup('The numeric canvas_page id to build a preview URL for.'),
      required: TRUE,
    ),
  ],
)]
class PreviewTokenUrl extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

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
   * The expirable token store (per-key atomic + auto-expiring).
   */
  protected KeyValueStoreExpirableInterface $tokenStore;

  /**
   * The URL generator.
   *
   * @var \Drupal\Core\Routing\UrlGeneratorInterface
   */
  protected $urlGenerator;

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
    $instance->tokenStore = $container->get('keyvalue.expirable')->get('varbase_ai_figma.preview_tokens');
    $instance->urlGenerator = $container->get('url_generator');
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
      throw new \Exception('You do not have permission to generate Canvas page preview URLs.');
    }

    $page_id = trim((string) $this->getContextValue('page_id'));
    if ($page_id === '') {
      $this->result = 'A numeric page_id is required.';
      return;
    }

    /** @var \Drupal\canvas\Entity\Page|null $page */
    $page = $this->entityTypeManager->getStorage('canvas_page')->load($page_id);
    if (!$page) {
      $this->result = sprintf('Canvas page "%s" not found.', $page_id);
      return;
    }

    // Mint a single-use, short-lived (300s) token bound to this page id. One
    // atomic, auto-expiring write - no whole-array read-modify-write.
    $token = Crypt::randomBytesBase64(32);
    $this->tokenStore->setWithExpire($token, (string) $page->id(), 300);

    $url = $this->urlGenerator->generateFromRoute(
      'varbase_ai_figma.token_preview',
      ['canvas_page' => $page->id()],
      ['absolute' => TRUE, 'query' => ['token' => $token]],
    );

    $this->result = sprintf(
      "Preview URL for canvas_page %s - SINGLE USE, works once within 300s. Open it with browser_preview / browser_navigate (no login required, renders unpublished drafts); call this tool again for a fresh URL if you need another look:\n%s",
      $page->id(),
      $url,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
