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
use Drupal\ai_figma\FigmaContextClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * AI Agent tool: fetch Figma design context (tokens + node outline).
 *
 * Lets the Drupal Canvas AI Orchestrator (and any AI Agent) pull live design
 * context from a Figma file - brand colors, typography and the node outline -
 * so generated Canvas components can match the approved design system.
 */
#[FunctionCall(
  id: 'varbase_ai_figma:get_design_context',
  function_name: 'varbase_figma_get_design_context',
  name: 'Read the design',
  description: 'Reads the design: its colours, its fonts, its layout, and the real words written in it - and, alongside that, the list of things this site can already build with. Everything the assistant needs to know before it builds. Technically: brand colours, typography, the node outline and the node\'s real text content from a Figma file, plus the EXISTING components the active theme ships (available_components), a build-with-them instruction, and an accessibility directive. Fill the built components with the returned content_texts (the design\'s own headings/copy/labels) rather than lorem ipsum. Call this whenever the user asks to "implement this design from Figma" or pastes a Figma link (including an "@"-prefixed link), before building or restyling Drupal Canvas components - then assemble the page from the listed existing theme components (section → group/card/hero) rather than custom code, styled with the returned colours and typography, and make every added component and text accessible (WCAG 2.1 AA - see the returned accessibility field) so the result passes an axe-core audit.',
  group: 'information_tools',
  module_dependencies: ['ai_figma'],
  context_definitions: [
    'figma_url' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Figma URL'),
      description: new TranslatableMarkup('A Figma link, e.g. https://www.figma.com/design/<fileKey>/<name>?node-id=<node>. You may pass the whole user message or an "@"-prefixed link (e.g. "Implement this design from Figma. @https://www.figma.com/design/…") - the file key and node id are extracted from it and override the fields below.'),
      required: FALSE,
    ),
    'file_key' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Figma file key'),
      description: new TranslatableMarkup('The Figma file key (the long id in a figma.com/design/<fileKey>/... URL). Leave empty to use the configured default file.'),
      required: FALSE,
    ),
    'node_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Node id'),
      description: new TranslatableMarkup('Optional node id to scope the context, e.g. "1283:979" or "1283-979" (from a node-id URL parameter). Leave empty to read the top of the file.'),
      required: FALSE,
    ),
  ],
)]
class FigmaGetDesignContext extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The Figma context client.
   */
  protected FigmaContextClient $figmaClient;

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The Figma-to-Canvas builder (provides the theme's live component list).
   *
   * @var \Drupal\varbase_ai_figma\FigmaToCanvasBuilder
   */
  protected $builder;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
    $instance->loggerFactory = $container->get('logger.factory');
    $instance->configFactory = $container->get('config.factory');
    $instance->builder = $container->get('varbase_ai_figma.builder');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * Logs a message to the module channel (and the AI channel when enabled).
   *
   * When AI prompt logging is on (ai.settings:prompt_logging) the message is
   * also written to the 'ai' channel so it surfaces in the AI logs.
   *
   * @param string $level
   *   A PSR log level ('info', 'warning', 'error').
   * @param string $message
   *   The message with placeholders.
   * @param array $context
   *   Placeholder values.
   */
  protected function log(string $level, string $message, array $context = []): void {
    $this->loggerFactory->get('ai_figma')->log($level, $message, $context);
    if ((bool) $this->configFactory->get('ai.settings')->get('prompt_logging')) {
      $this->loggerFactory->get('ai')->log($level, 'Figma design context tool: ' . $message, $context);
    }
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
      $this->log('warning', 'Permission denied fetching Figma design context for user @uid.', ['@uid' => $this->currentUser->id()]);
      throw new \Exception('You do not have permission to fetch Figma design context.');
    }

    $file_key = trim((string) $this->getContextValue('file_key'));
    $node_id = trim((string) $this->getContextValue('node_id'));

    // A pasted Figma URL overrides the explicit fields.
    $url = trim((string) $this->getContextValue('figma_url'));
    if ($url !== '') {
      $parsed = FigmaContextClient::parseFigmaUrl($url);
      // A link was supplied but no file key could be read from it: tell the
      // agent rather than silently reading the configured default file.
      if ($parsed['file_key'] === '' && $file_key === '') {
        $this->log('warning', 'Figma tool got an unparseable link: @url', ['@url' => $url]);
        throw new \InvalidArgumentException('The figma_url does not look like a Figma link - no file key could be extracted. Provide a figma.com/design/<fileKey>/… link or set file_key.');
      }
      if ($parsed['file_key'] !== '') {
        $file_key = $parsed['file_key'];
      }
      if ($parsed['node_id'] !== '') {
        $node_id = $parsed['node_id'];
      }
    }

    if ($file_key === '') {
      $file_key = $this->figmaClient->getDefaultFileKey();
    }
    if ($file_key === '') {
      throw new \InvalidArgumentException('No Figma file key was provided and no default is configured.');
    }

    try {
      $data = $this->figmaClient->fetchNodes($file_key, $node_id);
      $summary = $this->figmaClient->summarizeTokens($data);
    }
    catch (\Throwable $e) {
      $this->log('error', 'Figma tool failed for @key node @node: @msg', [
        '@key' => $file_key,
        '@node' => $node_id !== '' ? $node_id : '(file top level)',
        '@msg' => $e->getMessage(),
      ]);
      throw $e;
    }

    // The components the active theme actually ships, grouped by family, so the
    // agent builds from EXISTING theme components instead of inventing custom
    // code. The instruction tells the orchestrator/page-builder to reuse these.
    $families = $this->builder->componentsByFamily();
    $available = [
      'sections' => $families['structure'],
      'cards' => $families['cards'],
      'heroes' => $families['heroes'],
      'content' => $families['content'],
    ];

    // The real text the node contains (headings, body copy, button labels -
    // the design's own placeholder/sample content), document order, so the
    // agent fills components with the actual Figma wording, not lorem ipsum.
    $content_texts = [];
    foreach (($summary['texts'] ?? []) as $t) {
      $txt = trim((string) ($t['text'] ?? ''));
      if ($txt !== '') {
        $content_texts[] = [
          'text' => $txt,
          'layer' => (string) ($t['name'] ?? ''),
          'size' => $t['size'] ?? 0,
        ];
      }
    }

    $out = [
      'figma_file_key' => $file_key,
      'figma_node_id' => $node_id !== '' ? $node_id : '(file top level)',
      'colors' => $summary['colors'],
      'typography' => $summary['typography'],
      'node_outline' => $summary['outline'],
      'content_texts' => $content_texts,
      'available_components' => $available,
      // The site's own ready Drupal building blocks (webforms, Views listing
      // blocks, content types) so the agent reuses them instead of reinventing
      // them.
      'building_blocks' => $this->readyBuildingBlocks(),
      // Build rules + accessibility rules. The text is NOT hard-coded here: it
      // comes from the editable AI Context items ("Figma Build Rules" /
      // "Figma Accessibility Rules") when present, else from the module's own
      // config (ai_figma.settings), so every prompt/rule lives in config and is
      // editable - generic and theme-agnostic. varbase_ai_figma and other
      // setups add or override these via their own config + AI Context items.
      'instruction' => $this->getContextRule('Figma Build Rules', (string) $this->configFactory->get('ai_figma.settings')->get('build_rules')),
      'accessibility' => $this->getContextRule('Figma Accessibility Rules', (string) $this->configFactory->get('ai_figma.settings')->get('accessibility_rules')),
    ];

    $this->log('info', 'Figma tool read @key node @node: @c colours, @t type styles.', [
      '@key' => $file_key,
      '@node' => $node_id !== '' ? $node_id : '(file top level)',
      '@c' => count($summary['colors']),
      '@t' => count($summary['typography']),
    ]);

    $this->result = Yaml::dump($out, 6, 2);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

  /**
   * Lists the site's ready Drupal building blocks for the agent to reuse.
   *
   * Surfaces the FUNCTIONAL Drupal building blocks the site already ships so
   * the agent reuses them (no mockup HTML): visitor-usable Webforms, Views
   * listing blocks placeable in Canvas, and content types — plus a reminder to
   * read and obey any AI Context items on the site and reuse the site's
   * modules/tools and the other Drupal AI agents. Pass any of these straight to
   * varbase_ai_figma:create_canvas_page (webform:<id>, views_block.<id>).
   *
   * @return array
   *   Keyed: webforms, views_listings, content_types, read_context.
   */
  protected function readyBuildingBlocks(): array {
    $cf = $this->configFactory;

    // Visitor-usable Webforms: open, not archived, not a template_* sample.
    $webforms = [];
    foreach ($cf->listAll('webform.webform.') as $name) {
      $id = substr($name, strlen('webform.webform.'));
      if (str_starts_with($id, 'template_')) {
        continue;
      }
      $c = $cf->get($name);
      if ((string) $c->get('status') === 'open' && !$c->get('archive')) {
        $webforms['webform:' . $id] = (string) ($c->get('title') ?: $id);
      }
    }

    // Ready Views listing blocks placeable in Canvas (skip admin/a11y views).
    $views = [];
    foreach ($cf->listAll('canvas.component.block.views_block.') as $name) {
      $id = substr($name, strlen('canvas.component.block.'));
      if (preg_match('/editoria11y|publishing_content|recent_pages|a11y_tools/', $id)) {
        continue;
      }
      $views[$id] = (string) $cf->get($name)->get('label');
    }

    // Content types (use the matching one for content-driven sections).
    $types = [];
    foreach ($cf->listAll('node.type.') as $name) {
      $types[substr($name, strlen('node.type.'))] = (string) $cf->get($name)->get('name');
    }

    return [
      'read_context' => 'BUILD ON THE EXISTING SITE. Read and obey any AI Context items published on this site (Context Control Center / ai_context) - brand, editorial and safety rules. Reuse the site\'s ready modules and tools (Webform, Views, Media, Paragraphs, Scheduler, Pathauto, UI Skins/Styles when present) and the other Drupal AI agents (content-type/field/Views agents, the Canvas page/component agents) instead of reinventing them.',
      'webforms' => $webforms,
      'views_listings' => $views,
      'content_types' => $types,
    ];
  }

  /**
   * Reads a build rule from an AI Context item, with a hard-coded fallback.
   *
   * Site builders edit the rules at Admin → AI → Context (Context Control
   * Center / ai_context module); the items ship with the
   * ai_figma_recipe. When the module or the item is absent, the
   * built-in default keeps the tool functional.
   *
   * @param string $label
   *   The ai_context_item label, e.g. "Varbase Figma Build Rules".
   * @param string $fallback
   *   The default rule text used when no published item carries that label.
   *
   * @return string
   *   The rule text.
   */
  protected function getContextRule(string $label, string $fallback): string {
    try {
      $etm = $this->entityTypeManager;
      if (!$etm->hasDefinition('ai_context_item')) {
        return $fallback;
      }
      $items = $etm->getStorage('ai_context_item')->loadByProperties([
        'label' => $label,
        'status' => 1,
      ]);
      $item = reset($items);
      if ($item && $item->hasField('content') && !$item->get('content')->isEmpty()) {
        $value = trim((string) $item->get('content')->value);
        if ($value !== '') {
          return $value;
        }
      }
    }
    catch (\Throwable $e) {
      $this->log('warning', 'Could not read AI Context rule "@label": @msg', [
        '@label' => $label,
        '@msg' => $e->getMessage(),
      ]);
    }
    return $fallback;
  }

}
