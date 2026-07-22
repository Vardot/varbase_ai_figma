<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma\Plugin\AiFunctionCall;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\ai_figma\FigmaContextClient;
use Drupal\canvas\Entity\Pattern;
use Drupal\varbase_ai_figma\PatternBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AI Agent tool: create a reusable Drupal Canvas Pattern from a Figma design.
 *
 * A Canvas Pattern (canvas.pattern config entity) is a reusable, saved
 * composition of components - a "section/group" that authors can drop onto any
 * page from the editor's Patterns tab. Where create_canvas_page builds a whole
 * page and component_match/create tools work on single components, this tool
 * builds a PATTERN out of the site's atomic components.
 *
 * It reasons before it builds: for each region of the Figma design it PREFERS
 * to reuse an existing component from the default theme's full component
 * palette (every registered vartheme_bs5 SDC), and only scaffolds a NEW code
 * component when nothing in that palette fits - then assembles the Pattern from
 * those atomic components. The composition logic lives in the
 * varbase_ai_figma.pattern_builder service; this tool is the thin AI-facing
 * wrapper that resolves the Figma node, invokes the builder, and persists the
 * resulting canvas.pattern entity.
 */
#[FunctionCall(
  id: 'varbase_ai_figma:create_pattern',
  function_name: 'varbase_figma_create_pattern',
  name: 'Save a section so it can be reused',
  description: 'Saves a section of the design so it can be used again on any page - it appears in the editor\'s Patterns tab, ready to drop in. Build it once, reuse it everywhere. Use this when the user wants a reusable PATTERN or SECTION, not a whole page and not a single component. The tool REASONS first: it uses ALL existing components from the default theme (every registered vartheme_bs5 SDC) as its atomic palette, REUSES the closest existing component for each region of the design, and only creates a NEW code component when nothing in the palette matches - then assembles the Pattern from those atomic components. Identify the design by a Figma link in "figma_url" (or "file_key" + "node_id"); "label" is the human name for the pattern. Optional "layout" hints the section shape (auto | hero | cards | feature | cta | media | pricing | testimonials | logos | faq); leave empty to let the tool infer it from the design. Patterns use only static inputs (they are not bound to any page\'s content), so the copy is seeded from the design and can be edited after insertion.',
  group: 'modification_tools',
  module_dependencies: ['varbase_ai_figma', 'ai_figma', 'canvas'],
  context_definitions: [
    'label' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Pattern label'),
      description: new TranslatableMarkup('The human-facing name of the pattern, e.g. "Feature Grid" or "Pricing Row". A machine id is generated from it.'),
      required: TRUE,
    ),
    'figma_url' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Figma URL'),
      description: new TranslatableMarkup('A Figma link, e.g. https://www.figma.com/design/<fileKey>/<name>?node-id=<node>. The file key and node id are extracted from it and override the fields below.'),
      required: FALSE,
    ),
    'file_key' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Figma file key'),
      description: new TranslatableMarkup('The Figma file key. Leave empty to use the configured default file.'),
      required: FALSE,
    ),
    'node_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Node id'),
      description: new TranslatableMarkup('Optional node id scoping the design region, e.g. "1283:979" or "1283-979".'),
      required: FALSE,
    ),
    'layout' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Layout hint'),
      description: new TranslatableMarkup('Optional section shape hint: auto | hero | cards | feature | cta | media | pricing | testimonials | logos | faq. Defaults to auto (inferred from the design).'),
      required: FALSE,
    ),
  ],
)]
class CreatePattern extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The Figma context client (resolves the file key / node id).
   *
   * @var \Drupal\ai_figma\FigmaContextClient
   */
  protected FigmaContextClient $figmaClient;

  /**
   * The pattern builder (assembles the component tree from the palette).
   *
   * @var \Drupal\varbase_ai_figma\PatternBuilder
   */
  protected PatternBuilder $patternBuilder;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

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
    $instance->figmaClient = $container->get('ai_figma.client');
    $instance->patternBuilder = $container->get('varbase_ai_figma.pattern_builder');
    $instance->loggerFactory = $container->get('logger.factory');
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
      throw new \Exception('You do not have permission to create Canvas patterns.');
    }

    $label = trim((string) $this->getContextValue('label'));
    if ($label === '') {
      $this->result = 'A "label" for the pattern is required.';
      return;
    }
    $layout = strtolower(trim((string) $this->getContextValue('layout'))) ?: 'auto';

    // 1. Resolve file_key / node_id, identical to FigmaComponentMatch: a pasted
    //    URL overrides the explicit fields, falling back to the default file.
    $file_key = trim((string) $this->getContextValue('file_key'));
    $node_id = trim((string) $this->getContextValue('node_id'));
    $url = trim((string) $this->getContextValue('figma_url'));
    if ($url !== '') {
      $parsed = FigmaContextClient::parseFigmaUrl($url);
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
      $this->result = 'No Figma file key was provided and no default is configured. Pass a figma.com/design/<fileKey>/… link or set file_key.';
      return;
    }

    // 2. Assemble the pattern's component tree from the default-theme
    //    palette (reuse-before-create; new components scaffolded only when
    //    nothing fits).
    try {
      $result = $this->patternBuilder->buildTree($file_key, $node_id, $label, $layout);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('varbase_ai_figma')->error('create_pattern build failed: @m', ['@m' => $e->getMessage()]);
      $this->result = sprintf('Could not read or assemble the design: %s', $e->getMessage());
      return;
    }

    $rows = $result['rows'] ?? [];
    if (!$rows) {
      $this->result = 'The design produced no components, so no pattern was created.';
      return;
    }

    // 3. Persist the Pattern config entity. The id is auto-generated from the
    //    label by Pattern::preCreate(); component_tree accepts a plain list and
    //    is re-keyed by uuid on save.
    try {
      $pattern = Pattern::create([
        'label' => $label,
        'status' => TRUE,
        'component_tree' => array_values($rows),
      ]);
      $pattern->save();
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('varbase_ai_figma')->error('create_pattern save failed: @m', ['@m' => $e->getMessage()]);
      $this->result = sprintf('The pattern tree was assembled but failed validation on save: %s', $e->getMessage());
      return;
    }

    $created = $result['created_components'] ?? [];
    $reused = $result['reused_components'] ?? [];
    $this->result = sprintf(
      'Created Canvas pattern "%s" (id: %s) with %d component instance(s), layout "%s". Reused components: %s. New components created: %s. It now appears in the Canvas editor Patterns tab.',
      (string) $pattern->label(),
      (string) $pattern->id(),
      count($rows),
      $result['layout'] ?? $layout,
      $reused ? implode(', ', $reused) : '(none)',
      $created ? implode(', ', $created) : '(none)',
    );
    $this->loggerFactory->get('varbase_ai_figma')->info('create_pattern ran: @s', ['@s' => sprintf('%s (%d rows)', (string) $pattern->id(), count($rows))]);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
