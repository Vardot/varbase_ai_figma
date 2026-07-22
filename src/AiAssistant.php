<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Thin wrapper over the site's default Drupal AI chat provider.
 *
 * The content-improvement tools (rewrite, translate, alt text, meta, A/B
 * variants) need a model. Rather than each tool re-resolving a provider, they
 * call ask()/askJson() here, which uses whatever chat provider the site has set
 * as its default (ai.settings:default_providers). Returns an empty string /
 * empty array when no provider is configured, so a tool degrades gracefully.
 */
class AiAssistant {

  /**
   * Constructs the assistant.
   */
  public function __construct(
    protected AiProviderPluginManager $providerManager,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Whether a default chat provider is configured.
   */
  public function isAvailable(): bool {
    return $this->resolveDefault() !== NULL;
  }

  /**
   * Asks the model and returns its text answer.
   *
   * @param string $system
   *   The system instruction (role/voice/constraints).
   * @param string $user
   *   The user prompt.
   *
   * @return string
   *   The model's text, or '' when no provider / on error.
   */
  public function ask(string $system, string $user): string {
    $default = $this->resolveDefault();
    if ($default === NULL) {
      return '';
    }
    try {
      $provider = $this->providerManager->createInstance($default['provider_id']);
      $input = new ChatInput([
        new ChatMessage('system', $system),
        new ChatMessage('user', $user),
      ]);
      $response = $provider->chat($input, $default['model_id']);
      return trim((string) $response->getNormalized()->getText());
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('ai_figma')->warning('AI assistant call failed: @msg', ['@msg' => $e->getMessage()]);
      return '';
    }
  }

  /**
   * Asks the model for JSON and returns the decoded array.
   *
   * Appends a strict "JSON only" directive and tolerates a fenced code block.
   *
   * @param string $system
   *   The system instruction.
   * @param string $user
   *   The user prompt (should describe the wanted JSON shape).
   *
   * @return array
   *   The decoded JSON, or [] when no provider / unparseable.
   */
  public function askJson(string $system, string $user): array {
    $text = $this->ask($system . ' Respond with valid JSON only - no prose, no markdown fences.', $user);
    if ($text === '') {
      return [];
    }
    // Strip a ```json … ``` fence if the model added one anyway.
    if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $text, $m)) {
      $text = $m[1];
    }
    $decoded = json_decode($text, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Resolves the site's default chat provider + model.
   *
   * @return array{provider_id:string,model_id:string}|null
   *   The provider/model, or NULL when none configured.
   */
  protected function resolveDefault(): ?array {
    $defaults = (array) $this->configFactory->get('ai.settings')->get('default_providers');
    foreach (['chat_with_tools', 'chat', 'chat_with_complex_json'] as $type) {
      $cfg = $defaults[$type] ?? NULL;
      if (is_array($cfg) && !empty($cfg['provider_id']) && !empty($cfg['model_id'])) {
        return ['provider_id' => (string) $cfg['provider_id'], 'model_id' => (string) $cfg['model_id']];
      }
    }
    return NULL;
  }

}
