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
   * Tries every configured default provider in resolveDefault()'s priority
   * order, not just the first one: a site can have a healthy "chat" provider
   * while its "chat_with_tools" default is unreachable (an expired key,
   * a provider outage) - in that case failing outright instead of falling
   * through to the working provider would break every content tool for a
   * problem that has nothing to do with this module.
   *
   * @param string $system
   *   The system instruction (role/voice/constraints).
   * @param string $user
   *   The user prompt.
   *
   * @return string
   *   The model's text, or '' when no provider is configured or all of them
   *   failed.
   */
  public function ask(string $system, string $user): string {
    $candidates = $this->resolveCandidates();
    if (!$candidates) {
      return '';
    }
    $last_error = '';
    foreach ($candidates as $default) {
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
        $last_error = $e->getMessage();
        $this->loggerFactory->get('ai_figma')->warning('AI assistant call to @provider failed, trying the next configured default: @msg', [
          '@provider' => $default['provider_id'],
          '@msg' => $e->getMessage(),
        ]);
      }
    }
    $this->loggerFactory->get('ai_figma')->warning('AI assistant call failed on every configured default provider: @msg', ['@msg' => $last_error]);
    return '';
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
   * Resolves the site's preferred default chat provider + model.
   *
   * @return array{provider_id:string,model_id:string}|null
   *   The provider/model, or NULL when none configured.
   */
  protected function resolveDefault(): ?array {
    $candidates = $this->resolveCandidates();
    return $candidates[0] ?? NULL;
  }

  /**
   * Resolves every configured default chat provider, in priority order.
   *
   * De-duplicated by provider_id + model_id, so the same provider is never
   * tried twice when two operation types share it.
   *
   * @return array<array{provider_id:string,model_id:string}>
   *   The configured provider/model pairs, in the order ask() should try
   *   them; empty when none are configured.
   */
  protected function resolveCandidates(): array {
    $defaults = (array) $this->configFactory->get('ai.settings')->get('default_providers');
    $candidates = [];
    foreach (['chat_with_tools', 'chat', 'chat_with_complex_json'] as $type) {
      $cfg = $defaults[$type] ?? NULL;
      if (is_array($cfg) && !empty($cfg['provider_id']) && !empty($cfg['model_id'])) {
        $key = $cfg['provider_id'] . '::' . $cfg['model_id'];
        $candidates[$key] = ['provider_id' => (string) $cfg['provider_id'], 'model_id' => (string) $cfg['model_id']];
      }
    }
    return array_values($candidates);
  }

}
