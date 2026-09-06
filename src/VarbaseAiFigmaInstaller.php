<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma;

use Drupal\ai_figma\AiFigmaInstaller;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\Component\Plugin\PluginManagerInterface;

/**
 * Install/runtime wiring for Varbase AI Figma.
 *
 * Holds the logic that used to live in the procedural helpers in
 * varbase_ai_figma.install: apply the vartheme_bs5 profile onto
 * ai_figma.settings, bake a demo Figma token, seed the AI Context items
 * (delegating to the ai_figma installer) and wire the advanced Epic 2 tools
 * onto the Canvas AI Orchestrator.
 */
final class VarbaseAiFigmaInstaller {

  /**
   * The AI Agent tools the Canvas AI Orchestrator is given by default.
   *
   * Read the list top to bottom and it is the job, in order: read the design,
   * see what the site already has, decide what to reuse, build it, then check
   * and improve what was built. That order is the point. A shorter list of
   * tools whose names say what they do is a list a project manager can read
   * and a list the agent picks from more reliably.
   *
   * This is the DEFAULT set, not the whole set. Every other tool the module
   * ships (menus, the site logo, theme files, recipes, site settings, A/B
   * wording, bulk checks) is still a discoverable plugin an administrator can
   * switch on per agent at /admin/config/ai/agents - it is simply not pushed
   * onto every site, because a tool nobody asked for is a tool the agent can
   * still reach for by mistake.
   *
   * Kept out of the default set on purpose:
   * - component_match: the resolver supersedes it. It only ever looked at
   *   components, while resolve_design scores components, patterns, blocks and
   *   views together.
   * - fetch_media_assets: export_figma_images already brings the imagery over.
   * - set_theme_logo, theme_file, config: one-off administrative jobs, and two
   *   of them can write to the theme or to site settings. Handing every site a
   *   tool that edits site config, for a job an administrator does once, is a
   *   tool the agent can reach for by mistake. Module installs and config
   *   changes go through apply_recipe, which is the Drupal way and is
   *   reviewable.
   * - ab_variants, batch_improve, prompt_template: nice to have, but they are
   *   not part of turning a design into a site.
   */
  public const TOOLS = [
    // 1. Understand the design.
    'ai_figma:list_design_pages',
    'varbase_ai_figma:get_design_context',
    // 2. Understand the site, then decide. Nothing is built before this.
    'varbase_ai_figma:scan_inventory',
    'varbase_ai_figma:resolve_design',
    // 3. Build what the decision chose.
    'varbase_ai_figma:create_canvas_page',
    'varbase_ai_figma:page_edit',
    'varbase_ai_figma:update_component_inputs',
    'varbase_ai_figma:place_in_region',
    'varbase_ai_figma:create_pattern',
    'varbase_ai_figma:save_as_pattern',
    'varbase_ai_figma:insert_pattern',
    'varbase_ai_figma:export_figma_images',
    // 4. Connect what was built to the rest of the site: the front page, the
    // URL aliases, the menus, and the Webforms/Views a design region needs.
    'varbase_ai_figma:site_wiring',
    'varbase_ai_figma:menu_link',
    'varbase_ai_figma:apply_recipe',
    // 5. Look at it, then check and improve what was built.
    'varbase_ai_figma:preview_token_url',
    'varbase_ai_figma:check_page_render',
    'varbase_ai_figma:audit_page',
    'varbase_ai_figma:improve_text',
    'varbase_ai_figma:alt_text',
    'varbase_ai_figma:meta_description',
    'varbase_ai_figma:translate_page',
  ];

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
    protected readonly ExtensionPathResolver $extensionPathResolver,
    protected readonly PluginManagerInterface $keyProviderManager,
    protected readonly AiFigmaInstaller $aiFigmaInstaller,
  ) {}

  /**
   * Applies the shipped vartheme_bs5/Bootstrap 5.3 profile to ai_figma.
   */
  public function applyProfile(): void {
    $module_path = $this->extensionPathResolver->getPath('module', 'varbase_ai_figma');
    $file = $module_path . '/varbase_ai_figma.profile.yml';
    if (!is_file($file)) {
      $this->loggerFactory->get('varbase_ai_figma')->warning('vartheme_bs5 profile not found at @file; engine left on its generic defaults.', ['@file' => $file]);
      return;
    }
    $profile = Yaml::decode((string) file_get_contents($file));
    if (!is_array($profile)) {
      return;
    }
    $config = $this->configFactory->getEditable('ai_figma.settings');
    foreach ($profile as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
    $this->loggerFactory->get('varbase_ai_figma')->info('Applied the vartheme_bs5 / Bootstrap 5.3 profile to ai_figma.settings (@n keys).', ['@n' => count($profile)]);
  }

  /**
   * Bakes a demo Figma token into the shared `figma` Key from the environment.
   *
   * Reads FIGMA_DEMO_TOKEN; when empty leaves the Key for an administrator to
   * fill. Encrypts at rest via easy_encryption when available.
   */
  public function bakeDemoToken(): void {
    $token = (string) getenv('FIGMA_DEMO_TOKEN');
    if ($token === '') {
      $this->loggerFactory->get('varbase_ai_figma')->info('No FIGMA_DEMO_TOKEN environment variable set; leaving the Figma Key empty for an administrator to fill at /admin/config/system/keys.');
      return;
    }
    try {
      $storage = $this->entityTypeManager->getStorage('key');
      /** @var \Drupal\key\Entity\Key|null $key */
      $key = $storage->load('figma');
      if (!$key) {
        $key = $storage->create([
          'id' => 'figma',
          'dependencies' => ['enforced' => ['module' => ['ai_figma']]],
          'label' => 'Figma access token',
          'key_type' => 'authentication',
          'key_input' => 'text_field',
          'key_input_settings' => ['base64_encoded' => FALSE],
        ]);
      }
      if ($this->keyProviderManager->hasDefinition('easy_encrypted')) {
        if ($key->getKeyProvider()->getPluginId() !== 'easy_encrypted') {
          $key->setPlugin('key_provider', 'easy_encrypted');
          $key->set('key_provider_settings', []);
        }
        $key->save();
        $provider = $key->getKeyProvider();
        if (method_exists($provider, 'setKeyValue') && $provider->setKeyValue($key, $token)) {
          $key->save();
        }
      }
      else {
        $key->setPlugin('key_provider', 'config');
        $key->set('key_provider_settings', ['key_value' => $token, 'base64_encoded' => FALSE]);
        $key->save();
      }
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('varbase_ai_figma')->warning('Could not bake the demo Figma token: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  /**
   * Pushes the Varbase config prompts onto the editable AI Context items.
   *
   * Delegates to the general ai_figma seeder (config-driven) with
   * update = TRUE,
   * so the Varbase build rules / accessibility rules / mapping governance from
   * config overwrite the generic items ai_figma seeded. No rule text in PHP.
   */
  public function seedContext(): void {
    $this->aiFigmaInstaller->seedContextItems(TRUE);
    $this->seedOwnContextItems();
  }

  /**
   * Seeds the AI Context items this module declares in its own config.
   *
   * The ai_figma module seeds a fixed set of three items. Anything this
   * module wants to teach the Canvas AI agents beyond those - how to resolve
   * a design against what it already ships - is declared as a list in
   * varbase_ai_figma.settings, and seeded here. Adding to what the agent
   * knows is therefore a config edit, not a code change: add an entry,
   * re-apply, done.
   */
  protected function seedOwnContextItems(): void {
    $this->entityTypeManager->clearCachedDefinitions();
    if (!$this->entityTypeManager->hasDefinition('ai_context_item')) {
      return;
    }
    $settings = $this->configFactory->get('varbase_ai_figma.settings');
    $items = (array) ($settings->get('ai_context_items') ?: []);
    if (!$items) {
      return;
    }
    // Reuse the scope the Figma context items already use, so everything this
    // module teaches lands in the same place.
    $scope = (array) ($this->configFactory->get('ai_figma.settings')->get('context_scope')
      ?: ['global' => ['global']]);

    try {
      $storage = $this->entityTypeManager->getStorage('ai_context_item');

      // ai_context caps published global items (default 3) and rejects saves
      // over the cap, which silently drops the items seeded here when other
      // modules (e.g. ai_figma) seeded theirs first. Raise the cap so every
      // item this module teaches fits alongside the ones already published.
      $published_globals = 0;
      foreach ($storage->loadByProperties(['status' => 1]) as $existing_item) {
        if (method_exists($existing_item, 'isStoredGlobal') ? $existing_item->isStoredGlobal() : TRUE) {
          $published_globals++;
        }
      }
      $needed = $published_globals + count($items);
      $ai_context_settings = $this->configFactory->getEditable('ai_context.settings');
      $current_max = (int) ($ai_context_settings->get('max_global_items') ?? 3);
      if ($needed > $current_max) {
        $ai_context_settings->set('max_global_items', $needed)->save();
      }
      foreach ($items as $item) {
        $label = trim((string) ($item['label'] ?? ''));
        $content = trim((string) ($item['content'] ?? ''));
        if ($label === '' || $content === '') {
          continue;
        }
        $existing = $storage->loadByProperties(['label' => $label]);
        $entity = $existing ? reset($existing) : NULL;
        if ($entity) {
          $entity->set('content', ['value' => $content, 'format' => 'plain_text']);
          $this->applyContextScope($entity, $scope);
          $entity->save();
          continue;
        }
        $new_entity = $storage->create([
          'type' => 'default',
          'status' => TRUE,
          'uid' => 1,
          'label' => $label,
          'description' => ['value' => (string) ($item['description'] ?? ''), 'format' => 'plain_text'],
          'purpose' => ['value' => (string) ($item['purpose'] ?? ''), 'format' => 'plain_text'],
          'content' => ['value' => $content, 'format' => 'plain_text'],
        ]);
        $this->applyContextScope($new_entity, $scope);
        $new_entity->save();
      }
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('varbase_ai_figma')->warning(
        'Could not seed the Varbase AI Figma context items: @msg',
        ['@msg' => $e->getMessage()]
      );
    }
  }

  /**
   * Assigns a grouped scope array to an AI Context item.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The ai_context_item entity to assign the scope to.
   * @param array $scope
   *   Grouped scope, keyed by scope plugin ID, each an array of values.
   */
  protected function applyContextScope(EntityInterface $entity, array $scope): void {
    if (method_exists($entity, 'setScope')) {
      $entity->setScope($scope);
      return;
    }
    $entity->set('scope', $scope);
  }

  /**
   * Adds or removes the advanced Figma tools on the Canvas AI Orchestrator.
   *
   * No-op when the orchestrator agent (canvas_ai) is absent, so the module
   * never hard-depends on canvas_ai.
   *
   * @param bool $enable
   *   TRUE to add the tools, FALSE to remove them.
   */
  public function setOrchestratorTools(bool $enable): void {
    try {
      $orchestrator = $this->configFactory->getEditable('ai_agents.ai_agent.canvas_ai_orchestrator');
      if ($orchestrator->isNew()) {
        return;
      }
      $tools = $orchestrator->get('tools') ?: [];
      $settings = $orchestrator->get('tool_settings') ?: [];
      $changed = FALSE;
      foreach (self::TOOLS as $tool_id) {
        if ($enable && empty($tools[$tool_id])) {
          $tools[$tool_id] = TRUE;
          $settings[$tool_id] = [
            'return_directly' => 0,
            'require_usage' => 0,
            'description_override' => '',
            'progress_message' => '',
            'use_artifacts' => 0,
          ];
          $changed = TRUE;
        }
        elseif (!$enable && array_key_exists($tool_id, $tools)) {
          unset($tools[$tool_id], $settings[$tool_id]);
          $changed = TRUE;
        }
      }
      if ($changed) {
        $orchestrator->set('tools', $tools)->set('tool_settings', $settings)->save();
      }
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('varbase_ai_figma')->warning('Could not update the Canvas AI orchestrator advanced tools: @msg', ['@msg' => $e->getMessage()]);
    }
  }

}
