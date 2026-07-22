<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma\Plugin\AiFunctionCall;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AI Agent tool: cheaply check that a built Canvas page renders (HTTP probe).
 *
 * A fast first-tier verification: a plain HTTP GET of a page path that returns
 * the status code, and on a 4xx/5xx the error text - much cheaper than a full
 * headless-browser screenshot. Use it right after building a page to catch a
 * render/exception (a bad component, a validation error) before spending a
 * browser preview + vision pass; only escalate to browser_preview when this
 * comes back clean but you still want to SEE the result.
 *
 * Adapted from ai_agents_experimental_collection's
 * ai_agents_sdc:check_component_render (the cheap HTTP-probe verify tier).
 */
#[FunctionCall(
  id: 'varbase_ai_figma:check_page_render',
  function_name: 'varbase_figma_check_page_render',
  name: 'Check the page loads (quick)',
  description: 'Quickly check that a page on THIS site renders without a server error, using a plain HTTP request (no browser). Returns the HTTP status and, on a 4xx/5xx, the error output. Much faster than browser_preview - use it as a first pass right after building a page to catch render/exception errors, then use browser_preview only to SEE a page that already returns 200. Pass a path like "/page/12".',
  group: 'information_tools',
  module_dependencies: ['varbase_ai_figma'],
  context_definitions: [
    'path' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Page path'),
      description: new TranslatableMarkup('A path on this site to check, e.g. "/page/12" or "/". Leave empty for the front page.'),
      required: FALSE,
    ),
  ],
)]
final class CheckPageRender extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The HTTP client.
   */
  protected ClientInterface $httpClient;

  /**
   * The tool's readable result.
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
    $instance->httpClient = $container->get('http_client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    if (
      !$this->currentUser->hasPermission('administer ai agents')
      && !$this->currentUser->hasPermission('use Drupal Canvas AI')
    ) {
      throw new \Exception('You do not have permission to check page rendering.');
    }

    $path = trim((string) $this->getContextValue('path'));
    $path = $path === '' ? '/' : '/' . ltrim($path, '/');

    // Resolve against this site's own base URL; only in-site paths are checked.
    $base = Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();
    $url = rtrim($base, '/') . $path;

    try {
      $response = $this->httpClient->request('GET', $url, [
        'http_errors' => FALSE,
        'timeout' => 15,
        'allow_redirects' => TRUE,
      ]);
    }
    catch (\Throwable $e) {
      $this->result = 'HTTP request failed for ' . $path . ': ' . $e->getMessage();
      return;
    }

    $status = $response->getStatusCode();
    if (in_array($status, [401, 403], TRUE)) {
      $this->result = 'HTTP ' . $status . ' for ' . $path
        . ' from an anonymous probe. This is an access/publish issue (the probe '
        . 'is unauthenticated), not necessarily a render error - verify in the '
        . 'browser as an authorized user.';
      return;
    }
    if ($status === 404) {
      $this->result = 'HTTP 404 for ' . $path
        . ' - the path does not exist (check the page id / alias).';
      return;
    }
    if ($status >= 400) {
      $body = (string) $response->getBody();
      $text = trim(preg_replace('/\s+/', ' ', strip_tags($body)));
      $this->result = 'HTTP ' . $status . ' rendering ' . $path
        . '. The page has a render error to fix.' . "\n\nError output:\n"
        . mb_substr($text, 0, 800);
      return;
    }

    $this->result = 'Page ' . $path . ' renders OK (HTTP ' . $status . ').';
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
