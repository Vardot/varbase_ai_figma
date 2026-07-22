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
use Drupal\varbase_ai_figma\FigmaToCanvasBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * AI Agent tool: match a Figma node to the site's component library.
 *
 * Deterministically compares a pasted Figma component/node against the
 * existing Single Directory Components the active theme ships (the same
 * library varbase_ai_figma:get_design_context surfaces) and returns ranked
 * candidates, each with a 0-100 similarity score, the structural
 * differences, and an overall recommendation to:
 *   - REUSE an exact match (score >= 80),
 *   - EXTEND the closest one (50-79), naming the missing props/slots, or
 *   - CREATE a NEW component (nothing >= 50).
 *
 * This implements the component-mapping governance of the PRD without an LLM
 * call: stories 1.2 (exact match), 1.3 (closest/partial match) and 1.4
 * (create-new-vs-modify decision). The scoring is intentionally transparent and
 * documented (see scoreCandidate()): name/keyword overlap with the node's
 * guessed kind, family-bucket affinity, and a content-needs vs component-name
 * affinity signal. It is framework- and theme-agnostic: the library, the family
 * buckets and the component names all come from whatever the theme registers
 * (FigmaToCanvasBuilder::componentsByFamily()), nothing is hard-coded.
 *
 * Note on prop affinity: the builder's per-component prop/slot readers
 * (declaredProps()/declaredSlots()/componentDef()) are PROTECTED, so this tool
 * does NOT call them. Prop affinity is approximated from the component's bare
 * NAME alone (e.g. a name containing "card"/"text"/"hero"/"cta" implies the
 * heading/body/button/image roles it can carry), keeping the tool decoupled
 * from the builder's internals while staying deterministic.
 */
#[FunctionCall(
  id: 'varbase_ai_figma:component_match',
  function_name: 'varbase_figma_component_match',
  name: 'Find the closest component',
  description: 'Compares a pasted Figma component/node against the site\'s EXISTING component library (the Single Directory Components the active theme ships) and returns ranked matches - each with a 0-100 similarity score and the structural differences (missing props/slots, extra content the component lacks) - plus a recommendation to REUSE an exact match, EXTEND the closest one, or CREATE a NEW component. This implements the component-mapping governance deterministically (no LLM call): exact match (story 1.2), closest/partial match (story 1.3), and the create-new-vs-modify decision (story 1.4). Call this BEFORE building or restyling Drupal Canvas components from a Figma link, so the agent reuses or extends an existing component instead of inventing custom code; use the returned recommendation to decide whether to place the matched component, extend it, or scaffold a new one.',
  group: 'information_tools',
  module_dependencies: ['ai_figma'],
  context_definitions: [
    'figma_url' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Figma URL'),
      description: new TranslatableMarkup('A Figma link, e.g. https://www.figma.com/design/<fileKey>/<name>?node-id=<node>. You may pass the whole user message or an "@"-prefixed link (e.g. "Match this component. @https://www.figma.com/design/…") - the file key and node id are extracted from it and override the fields below.'),
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
      description: new TranslatableMarkup('Optional node id to scope the match, e.g. "1283:979" or "1283-979" (from a node-id URL parameter). Leave empty to read the top of the file.'),
      required: FALSE,
    ),
  ],
)]
class FigmaComponentMatch extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The Figma context client.
   *
   * @var \Drupal\ai_figma\FigmaContextClient
   */
  protected FigmaContextClient $figmaClient;

  /**
   * The Figma-to-Canvas builder (provides the theme's live component library).
   *
   * @var \Drupal\varbase_ai_figma\FigmaToCanvasBuilder
   */
  protected FigmaToCanvasBuilder $builder;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The collected readable output (YAML).
   *
   * @var string
   */
  protected string $result = '';

  /**
   * Score at or above which a candidate is treated as an exact, reusable match.
   */
  protected const SCORE_REUSE = 80;

  /**
   * Score at or above which a candidate is treated as an extendable match.
   */
  protected const SCORE_EXTEND = 50;

  /**
   * Layout/kind keywords looked for in the node's name and outline.
   *
   * The guessed "kind" of the Figma node is the first of these whose word
   * appears in the root name or the node outline. Kept here (not hard-coded
   * inline) so the vocabulary is in one place; it describes design intent, not
   * any particular theme's component names.
   *
   * @var string[]
   */
  protected const KIND_KEYWORDS = [
    'hero',
    'banner',
    'card',
    'cta',
    'accordion',
    'gallery',
    'stats',
    'list',
    'form',
    'nav',
    'footer',
    'header',
    'feature',
  ];

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
    $instance->builder = $container->get('varbase_ai_figma.builder');
    $instance->currentUser = $container->get('current_user');
    $instance->loggerFactory = $container->get('logger.factory');
    return $instance;
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
      throw new \Exception('You do not have permission to match Figma components.');
    }

    // 1. Resolve file_key / node_id - identical resolution to
    //    FigmaGetDesignContext (a pasted URL overrides the explicit fields,
    //    falling back to the configured default file key).
    $file_key = trim((string) $this->getContextValue('file_key'));
    $node_id = trim((string) $this->getContextValue('node_id'));

    $url = trim((string) $this->getContextValue('figma_url'));
    if ($url !== '') {
      $parsed = FigmaContextClient::parseFigmaUrl($url);
      // A link was supplied but no file key could be read from it: tell the
      // agent rather than silently reading the configured default file.
      if ($parsed['file_key'] === '' && $file_key === '') {
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

    // 2. Pull the design summary (texts with sizes/names, colors, typography,
    //    outline, root_name).
    $summary = $this->figmaClient->summarizeTokens($this->figmaClient->fetchNodes($file_key, $node_id));

    // 3. Derive a lightweight fingerprint of the Figma node.
    $fingerprint = $this->fingerprint($summary);

    // 4. Pull the live component library, grouped by family.
    $families = $this->builder->componentsByFamily();

    // 5. Score every candidate component against the fingerprint, rank, and
    //    derive the reuse / extend / create-new recommendation.
    $candidates = $this->rankCandidates($fingerprint, $families);
    [$top, $overall] = $this->recommend($candidates, $fingerprint, $families);

    $out = [
      'figma_file_key' => $file_key,
      'figma_node_id' => $node_id !== '' ? $node_id : '(file top level)',
      'fingerprint' => $fingerprint,
      'candidates' => $top,
      'recommendation' => $overall['recommendation'],
      'rationale' => $overall['rationale'],
    ];

    $this->result = Yaml::dump($out, 6, 2);
    $this->loggerFactory->get('ai_figma')->info('component_match ran: @s', ['@s' => sprintf('%s -> %s', $fingerprint['kind'], $overall['recommendation'])]);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

  /**
   * Derives a lightweight fingerprint of the Figma node from its token summary.
   *
   * The fingerprint captures the design intent the scorer compares components
   * against:
   *   - kind: the guessed family/kind (hero, card, cta, banner, …) from the
   *     root name + outline keywords;
   *   - family: which library family bucket that kind belongs to (structure /
   *     cards / heroes / content) - so a hero-kind node scores hero components
   *     higher, a card-kind node scores cards higher, etc.;
   *   - headings / paragraphs / labels: how many of each the node contains,
   *     classified from the text sizes/lengths exactly like the builder does
   *     (size >= 24 = heading, len >= 40 = paragraph, otherwise a short
   *     label/button);
   *   - has_button / has_image: whether the node carries a button-ish label or
   *     an image/icon layer;
   *   - needs: the content roles the node requires (heading, body, button,
   *     image), used for the prop-affinity signal;
   *   - dominant_color: the most prominent palette colour.
   *
   * @param array $summary
   *   A FigmaContextClient::summarizeTokens() result.
   *
   * @return array
   *   The fingerprint, ready to embed in the YAML output and feed the scorer.
   */
  protected function fingerprint(array $summary): array {
    $root = (string) ($summary['root_name'] ?? '');
    $outline = (array) ($summary['outline'] ?? []);
    $texts = (array) ($summary['texts'] ?? []);

    // Haystack for kind detection: the root name + every outline line + every
    // text-layer name, lower-cased.
    $hay = mb_strtolower($root . ' ' . implode(' ', $outline));
    foreach ($texts as $t) {
      $hay .= ' ' . mb_strtolower((string) ($t['name'] ?? ''));
    }

    // Classify the node's text content the same way the builder's
    // deriveContent() does: large text = heading, long text = paragraph, short
    // wordish text = a label/button.
    $headings = 0;
    $paragraphs = 0;
    $labels = 0;
    $has_button = FALSE;
    foreach ($texts as $t) {
      $text = trim((string) ($t['text'] ?? ''));
      if ($text === '') {
        continue;
      }
      $len = mb_strlen($text);
      $size = (float) ($t['size'] ?? 0);
      $is_heading = $size >= 24 && $len <= 80;
      $is_paragraph = $len >= 40;
      if ($is_heading) {
        $headings++;
      }
      elseif ($is_paragraph) {
        $paragraphs++;
      }
      else {
        $labels++;
        // A short, wordish, digit-free label reads as a button/CTA.
        if (
          $len <= 24
          && str_word_count($text) >= 1
          && str_word_count($text) <= 4
          && preg_match('/\p{L}{2,}/u', $text) === 1
          && !preg_match('/\d/', $text)
        ) {
          $has_button = TRUE;
        }
      }
    }

    // An image/icon layer anywhere in the outline (IMAGE/RECTANGLE/VECTOR or a
    // name mentioning image/icon/logo/avatar/photo) means the node needs an
    // image slot/prop.
    $has_image = (bool) preg_match('/\b(image|icon|logo|avatar|photo|picture)\b/', $hay)
      || (bool) preg_match('/(?:^|\n)\s*(IMAGE|VECTOR):/m', implode("\n", $outline));

    // Guess the kind: the first kind-keyword found in the haystack, else infer
    // from the content shape (button-led = cta, image-led = banner, two+
    // heading→body pairs = cards, a single heading+body = hero, else content).
    $kind = '';
    foreach (self::KIND_KEYWORDS as $word) {
      if (str_contains($hay, $word)) {
        $kind = $word;
        break;
      }
    }
    if ($kind === '') {
      if ($headings >= 2 && $paragraphs >= 2) {
        $kind = 'card';
      }
      elseif ($has_image && $headings <= 1) {
        $kind = 'banner';
      }
      elseif ($has_button && $paragraphs <= 1 && $headings <= 1) {
        $kind = 'cta';
      }
      elseif ($headings >= 1) {
        $kind = 'hero';
      }
      else {
        $kind = 'content';
      }
    }

    // The content roles the node needs (drives the prop-affinity signal).
    $needs = [];
    if ($headings > 0) {
      $needs[] = 'heading';
    }
    if ($paragraphs > 0) {
      $needs[] = 'body';
    }
    if ($has_button) {
      $needs[] = 'button';
    }
    if ($has_image) {
      $needs[] = 'image';
    }

    return [
      'root_name' => $root,
      'kind' => $kind,
      'family' => $this->familyForKind($kind),
      'headings' => $headings,
      'paragraphs' => $paragraphs,
      'labels' => $labels,
      'has_button' => $has_button,
      'has_image' => $has_image,
      'needs' => $needs,
      'dominant_color' => $this->dominantColor($summary['colors'] ?? []),
    ];
  }

  /**
   * Maps a guessed node kind to a library family bucket.
   *
   * The buckets are the ones componentsByFamily() returns: structure, cards,
   * heroes, content. A hero/banner-kind node belongs to "heroes", a card-kind
   * node to "cards", a structural keyword to "structure"; everything else
   * (cta, list, form, content, …) is plain "content".
   *
   * @param string $kind
   *   The guessed kind from fingerprint().
   *
   * @return string
   *   One of: structure, cards, heroes, content.
   */
  protected function familyForKind(string $kind): string {
    return match ($kind) {
      'hero', 'banner' => 'heroes',
      'card' => 'cards',
      'header', 'footer', 'nav' => 'structure',
      default => 'content',
    };
  }

  /**
   * Picks the most prominent palette colour (first detected), or ''.
   *
   * @param array $colors
   *   The colors map (hex => layer name) from the token summary.
   *
   * @return string
   *   The dominant hex, or '' when the node has no palette.
   */
  protected function dominantColor(array $colors): string {
    foreach (array_keys($colors) as $hex) {
      return (string) $hex;
    }
    return '';
  }

  /**
   * Scores every library component against the node fingerprint and ranks them.
   *
   * @param array $fingerprint
   *   The node fingerprint from fingerprint().
   * @param array $families
   *   componentsByFamily() output (structure/cards/heroes/content/all).
   *
   * @return array
   *   Candidates sorted by descending score, each:
   *   ['component','score','family','why','differences'].
   */
  protected function rankCandidates(array $fingerprint, array $families): array {
    // Map each bare component name to the family bucket it sits in (the bucket
    // it was placed in by componentsByFamily(), so a theme's own card/hero
    // names are honoured without hard-coding).
    $component_family = [];
    foreach (['structure', 'cards', 'heroes', 'content'] as $bucket) {
      foreach ((array) ($families[$bucket] ?? []) as $name) {
        // First bucket wins (a name only appears in one bucket anyway).
        $component_family[$name] = $component_family[$name] ?? $bucket;
      }
    }

    $candidates = [];
    foreach ((array) ($families['all'] ?? []) as $name) {
      $name = (string) $name;
      if ($name === '') {
        continue;
      }
      $family = $component_family[$name] ?? 'content';
      $scored = $this->scoreCandidate($name, $family, $fingerprint);
      $candidates[] = [
        'component' => $name,
        'score' => $scored['score'],
        'family' => $family,
        'why' => $scored['why'],
        'differences' => $scored['differences'],
      ];
    }

    // Rank by score desc; tie-break by component name for stable output.
    usort($candidates, static function (array $a, array $b): int {
      return [$b['score'], $a['component']] <=> [$a['score'], $b['component']];
    });

    return $candidates;
  }

  /**
   * Scores one component against the node fingerprint (0-100), transparently.
   *
   * Three deterministic, documented signals are summed and clamped to 0-100:
   *
   *   1. Name / keyword overlap (STRONG, up to 55). The node's guessed kind
   *      (hero, card, cta, …) appearing in the component's bare name is the
   *      most reliable signal a component is meant for that kind (+45). A
   *      softer partial overlap - the component name sharing the kind's word
   *      stem, or matching a synonym like cta/button or banner/media - adds up
   *      to +25.
   *   2. Family-bucket affinity (up to 25). A component sitting in the same
   *      library family as the node's kind (hero-kind → "heroes" bucket,
   *      card-kind → "cards", …) scores +25; a "structure" component for any
   *      content-bearing node scores a small +5 (it can wrap the content).
   *   3. Prop affinity (up to 20). Because the builder's prop readers are
   *      protected, prop coverage is approximated from the component NAME: a
   *      card/hero/feature/media/cta-named component is assumed to carry the
   *      heading + body + button (+ image for hero/media) roles; a
   *      heading/title-named one carries a heading; a text/body/paragraph-named
   *      one carries body; a button/link/cta-named one carries a button; an
   *      image/media-named one carries an image. Each of the node's needs that
   *      the name plausibly covers adds a share of 20.
   *
   * @param string $name
   *   The bare component name.
   * @param string $family
   *   The component's library family bucket.
   * @param array $fingerprint
   *   The node fingerprint.
   *
   * @return array
   *   ['score' => int, 'why' => string[], 'differences' => string[]].
   */
  protected function scoreCandidate(string $name, string $family, array $fingerprint): array {
    $lower = mb_strtolower($name);
    $kind = (string) $fingerprint['kind'];
    $why = [];
    $score = 0;

    // --- Signal 1: name / keyword overlap with the guessed kind. ----------
    $synonyms = $this->kindSynonyms($kind);
    $exact_name_hit = FALSE;
    foreach ($synonyms as $word) {
      if ($word !== '' && str_contains($lower, $word)) {
        $exact_name_hit = TRUE;
        break;
      }
    }
    if ($exact_name_hit) {
      $score += 45;
      $why[] = sprintf('name matches the "%s" kind', $kind);
    }
    else {
      // Softer partial token overlap between the component name tokens and the
      // kind word (e.g. component "card-text" vs kind "card" already handled
      // above; this catches stem overlaps like "heading" vs kind "header").
      $overlap = $this->tokenOverlap($lower, $kind);
      if ($overlap > 0) {
        $add = (int) min(25, 25 * $overlap);
        $score += $add;
        $why[] = sprintf('partial name overlap with "%s"', $kind);
      }
    }

    // --- Signal 2: family-bucket affinity. --------------------------------
    if ($family === $fingerprint['family']) {
      $score += 25;
      $why[] = sprintf('same "%s" family as the node', $family);
    }
    elseif ($family === 'structure' && !empty($fingerprint['needs'])) {
      // A structural wrapper can always host the node's content.
      $score += 5;
      $why[] = 'structural wrapper can host the content';
    }

    // --- Signal 3: prop affinity, approximated from the component name. ----
    $covers = $this->rolesCoveredByName($lower);
    $needs = (array) $fingerprint['needs'];
    $differences = [];
    if ($needs) {
      $met = array_values(array_intersect($needs, $covers));
      $missing = array_values(array_diff($needs, $covers));
      $share = (int) round(20 * (count($met) / max(1, count($needs))));
      $score += $share;
      if ($met) {
        $why[] = 'covers node roles: ' . implode(', ', $met);
      }
      // Differences = the roles the node needs that this component's name does
      // not plausibly carry (what an "extend" would have to add).
      foreach ($missing as $role) {
        $differences[] = sprintf('no %s prop/slot for the node\'s %s', $role, $role);
      }
    }

    $score = (int) max(0, min(100, $score));
    return [
      'score' => $score,
      'why' => $why ?: ['weak match'],
      'differences' => $differences,
    ];
  }

  /**
   * Returns the name-search synonyms for a guessed kind.
   *
   * Lets a kind match the component names a theme might actually use for it -
   * e.g. a "cta" kind also matches "button"/"action", a "banner" kind matches
   * "media"/"hero". Generic design vocabulary, not a theme-specific mapping.
   *
   * @param string $kind
   *   The guessed kind.
   *
   * @return string[]
   *   Lower-case search words (the kind itself first).
   */
  protected function kindSynonyms(string $kind): array {
    $map = [
      'hero' => ['hero', 'banner', 'jumbotron'],
      'banner' => ['banner', 'media', 'hero'],
      'card' => ['card', 'tile', 'panel'],
      'cta' => ['cta', 'button', 'action', 'call-to-action'],
      'accordion' => ['accordion', 'collapse'],
      'gallery' => ['gallery', 'carousel', 'slider', 'grid'],
      'stats' => ['stat', 'counter', 'number'],
      'list' => ['list', 'listing'],
      'form' => ['form', 'webform', 'contact'],
      'nav' => ['nav', 'menu'],
      'footer' => ['footer'],
      'header' => ['header', 'head'],
      'feature' => ['feature', 'card', 'icon'],
      'content' => ['text', 'content', 'paragraph', 'body'],
    ];
    return $map[$kind] ?? [$kind];
  }

  /**
   * A rough token-overlap ratio between a component name and a kind word.
   *
   * Splits the component name on non-letters and reports the share of its
   * tokens that contain (or are contained by) the kind word - a soft stem
   * match (e.g. "header" vs "head").
   *
   * @param string $lower_name
   *   The lower-case component name.
   * @param string $kind
   *   The lower-case guessed kind.
   *
   * @return float
   *   0.0-1.0 overlap ratio.
   */
  protected function tokenOverlap(string $lower_name, string $kind): float {
    if ($kind === '') {
      return 0.0;
    }
    $tokens = array_values(array_filter(preg_split('/[^a-z0-9]+/', $lower_name) ?: []));
    if (!$tokens) {
      return 0.0;
    }
    $hits = 0;
    foreach ($tokens as $token) {
      if ($token === '') {
        continue;
      }
      if (str_contains($token, $kind) || str_contains($kind, $token)) {
        $hits++;
      }
    }
    return $hits / count($tokens);
  }

  /**
   * Approximates which content roles a component carries from its bare name.
   *
   * Deliberate stand-in for reading declared props/slots (which are protected
   * on the builder): the component NAME is a strong hint at what it holds. A
   * card/hero/feature/media/cta-named component is treated as carrying the
   * heading + body + button roles (plus image for hero/media); more specific
   * names map to a single role. The result is intersected with the node's
   * needs to compute coverage and the missing-role differences.
   *
   * @param string $lower_name
   *   The lower-case component name.
   *
   * @return string[]
   *   Roles from {heading, body, button, image} the name plausibly covers.
   */
  protected function rolesCoveredByName(string $lower_name): array {
    $roles = [];
    // Composite components that bundle several roles.
    if (preg_match('/card|hero|feature|media|cta|teaser|banner/', $lower_name)) {
      $roles = ['heading', 'body', 'button'];
      if (preg_match('/hero|media|banner|image/', $lower_name)) {
        $roles[] = 'image';
      }
      return array_values(array_unique($roles));
    }
    // Single-role components.
    if (preg_match('/head|title|name/', $lower_name)) {
      $roles[] = 'heading';
    }
    if (preg_match('/text|body|paragraph|desc|content|summary/', $lower_name)) {
      $roles[] = 'body';
    }
    if (preg_match('/button|link|action/', $lower_name)) {
      $roles[] = 'button';
    }
    if (preg_match('/image|icon|logo|avatar|photo|picture|media/', $lower_name)) {
      $roles[] = 'image';
    }
    return array_values(array_unique($roles));
  }

  /**
   * Picks the top candidates and derives the overall recommendation.
   *
   * Story 1.2/1.3/1.4 decision:
   *   - top score >= SCORE_REUSE (80)  → 'reuse' that component (exact match);
   *   - top score >= SCORE_EXTEND (50) → 'extend' it (close match), naming the
   *     structural differences;
   *   - nothing >= SCORE_EXTEND        → 'create_new'.
   *
   * @param array $candidates
   *   Ranked candidates from rankCandidates().
   * @param array $fingerprint
   *   The node fingerprint (for the rationale wording).
   * @param array $families
   *   componentsByFamily() output (to report whether the library is empty).
   *
   * @return array
   *   [array $top_candidates (1-3), array $overall], where $overall is
   *   ['recommendation' => string, 'rationale' => string].
   */
  protected function recommend(array $candidates, array $fingerprint, array $families): array {
    $top = array_slice($candidates, 0, 3);
    $best = $top[0] ?? NULL;
    $kind = (string) $fingerprint['kind'];

    // Empty library, or no candidate clears the close-match bar: create new.
    if (!$best || (int) $best['score'] < self::SCORE_EXTEND) {
      $library_empty = empty($families['all']);
      $rationale = $library_empty
        ? 'The site ships no components to match against, so a new component must be created for this design.'
        : sprintf(
          'No existing component scored at least %d/100 against this "%s" node (best: %s at %d); create a new component for it.',
          self::SCORE_EXTEND,
          $kind,
          $best['component'] ?? '(none)',
          (int) ($best['score'] ?? 0),
        );
      return [$top, ['recommendation' => 'create_new', 'rationale' => $rationale]];
    }

    if ((int) $best['score'] >= self::SCORE_REUSE) {
      return [
        $top,
        [
          'recommendation' => 'reuse',
          'rationale' => sprintf(
            'Component "%s" is a strong match (%d/100) for this "%s" node - reuse it as-is and fill it with the node\'s content.',
            $best['component'],
            (int) $best['score'],
            $kind,
          ),
        ],
      ];
    }

    // Close match: extend it, naming what is missing.
    $diff = !empty($best['differences'])
      ? ' Extend it to add: ' . implode('; ', $best['differences']) . '.'
      : ' Extend it to cover the remaining structural differences.';
    return [
      $top,
      [
        'recommendation' => 'extend',
        'rationale' => sprintf(
          'Component "%s" is the closest match (%d/100) for this "%s" node but not an exact one.%s',
          $best['component'],
          (int) $best['score'],
          $kind,
          $diff,
        ),
      ],
    ];
  }

}
