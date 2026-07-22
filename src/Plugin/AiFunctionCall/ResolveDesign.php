<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma\Plugin\AiFunctionCall;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\varbase_ai_figma\DesignResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AI Agent tool: decide, per design region, what to reuse and what to create.
 *
 * The agent describes what each region of the design NEEDS (which content
 * roles, how many repeated items, whether it repeats real site content,
 * whether it is a whole section or a single element, and any mapped
 * design-token values), and this returns a verdict per region, scored against
 * everything the site already ships:
 *
 *   REUSE  - place this exact thing, with these prop values.
 *   ADAPT  - use the closest thing and set these props / fill these gaps.
 *   CREATE - nothing covers it; here is exactly what is missing.
 *
 * It scores components, patterns, blocks AND views together, so a repeating
 * region of real content resolves to an existing View instead of frozen cards,
 * and a whole section resolves to a saved Pattern instead of being rebuilt part
 * by part. The score is capability-first: what a thing can genuinely hold
 * counts
 * for far more than what it is called.
 */
#[FunctionCall(
  id: 'varbase_ai_figma:resolve_design',
  function_name: 'varbase_figma_resolve_design',
  name: 'Decide what to reuse and what to build',
  description: 'The decision step, taken before anything is built. For each part of a design it works out whether the site can already cover it, whether the closest thing it has would cover it after a few changes, or whether something genuinely has to be built. It checks components, saved sections, blocks and lists together, so live content stays live instead of being frozen into a picture of itself. This tool already writes its answer in the words a site builder uses — repeat that answer as it comes back. Do not label the parts with internal decision words, and do not quote machine names, ids or scores at the person; say what will be used, whether it already exists, and what is still missing, in plain sentences. Pass "regions" as a JSON array; each region: {"name":"hero","roles":["heading","body","button","image"],"repeat":1,"dynamic":false,"section":true,"content":{"heading":"..."},"values":{"background_color":"bg-dark"}}. Roles: heading, body, image, video, button, link, badge, list, icon, quote, stat, form, logo, author, date, price. Set "dynamic":true when the part shows real, changing site content. Set "section":true for a whole section, false for a single element.',
  group: 'information_tools',
  module_dependencies: ['varbase_ai_figma', 'canvas'],
  context_definitions: [
    'regions' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Regions (JSON)'),
      description: new TranslatableMarkup('A JSON array of region specs. Each: {"name":…, "roles":[…], "repeat":1, "dynamic":false, "section":true, "required":[…], "values":{…}}.'),
      required: TRUE,
    ),
    'kinds' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Kinds'),
      description: new TranslatableMarkup('Restrict the palette: comma separated component, pattern, block, view. Empty = all four.'),
      required: FALSE,
    ),
  ],
)]
final class ResolveDesign extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The design resolver.
   */
  protected DesignResolver $resolver;

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
    $instance->resolver = $container->get('varbase_ai_figma.resolver');
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
      throw new \Exception('You do not have permission to resolve designs.');
    }

    $raw = trim((string) $this->getContextValue('regions'));
    if ($raw === '') {
      $this->result = 'Describe the design regions. Example: [{"name":"hero","roles":["heading","body","button"],"section":true}]';
      return;
    }
    $regions = Json::decode($raw);
    if (!is_array($regions) || !$regions) {
      $this->result = 'The "regions" value must be a non-empty JSON array of region objects.';
      return;
    }
    // Tolerate a single region object instead of an array of them.
    if (isset($regions['roles']) || isset($regions['name'])) {
      $regions = [$regions];
    }

    $kinds = array_values(array_filter(array_map('trim', explode(',', (string) $this->getContextValue('kinds')))));
    $plans = $this->resolver->resolve($regions, $kinds);

    $lines = [];
    $reuse = $adapt = $create = 0;
    foreach ($plans as $p) {
      match ($p['verdict']) {
        'REUSE' => $reuse++,
        'ADAPT' => $adapt++,
        default => $create++,
      };
      $lines[] = $this->describePlan($p);
    }

    $summary = [];
    if ($reuse) {
      $summary[] = $reuse . ($reuse === 1 ? ' part we already have' : ' parts we already have');
    }
    if ($adapt) {
      $summary[] = $adapt . ($adapt === 1 ? ' part that needs adjusting' : ' parts that need adjusting');
    }
    if ($create) {
      $summary[] = $create . ($create === 1 ? ' part to build' : ' parts to build');
    }

    $this->result = "Here is what I found for this design.\n\n"
      . implode("\n\n", $lines)
      . "\n\nIn short: " . $this->sentenceList($summary) . '.'
      . ($create
        ? ' Only the parts listed as "to build" are genuinely new - everything else is already on this site.'
        : ' Nothing new needs building; this design can be made entirely from what the site already has.');
  }

  /**
   * One region, described the way a site builder would say it out loud.
   *
   * No machine names, no ids, no design-file node numbers: a person reading
   * this wants to know what will be used, whether it already exists, and
   * what is still missing.
   */
  private function describePlan(array $p): string {
    $region = $this->humanize((string) $p['region']);
    $thing = trim((string) ($p['label'] ?? ''));
    $kind = (string) ($p['kind'] ?? '');
    $repeat = (int) ($p['repeat'] ?? 1);

    $noun = match ($kind) {
      'pattern' => 'a ready-made section',
      'block' => 'a ready-made block',
      'view' => 'a live list of real content',
      default => 'a component',
    };

    $head = match ($p['verdict']) {
      'REUSE' => sprintf('%s - use what we already have.', $region),
      'ADAPT' => sprintf('%s - use the closest thing we have, with a few changes.', $region),
      default => sprintf('%s - this one needs building.', $region),
    };

    $body = [];
    if ($thing !== '' && $p['verdict'] !== 'CREATE') {
      $times = $repeat > 1 ? sprintf(', repeated %d times', $repeat) : '';
      $body[] = sprintf('Use "%s", %s already on this site%s.', $thing, $noun, $times);
    }
    elseif ($thing !== '') {
      $body[] = sprintf('The closest thing we have is "%s", but it does not go far enough.', $thing);
    }
    else {
      $body[] = 'Nothing on the site comes close to this one.';
    }

    if (!empty($p['bind'])) {
      $body[] = 'It will be filled in with the wording from the design.';
    }

    $gaps = array_values((array) ($p['gaps'] ?? []));
    if ($gaps) {
      $body[] = ($p['verdict'] === 'CREATE' ? 'What is missing: ' : 'Still to sort out: ')
        . $this->sentenceList(array_map([$this, 'humanizeGap'], $gaps)) . '.';
    }

    return $head . ' ' . implode(' ', $body);
  }

  /**
   * Turns an internal gap note into something a person would say.
   */
  private function humanizeGap(string $gap): string {
    // "no place for the heading" / 'no "x" prop for the design value "y"'.
    $gap = preg_replace('/no "([^"]+)" prop for the design value "([^"]+)"/', 'the $1 style ("$2") has no matching setting', $gap) ?? $gap;
    $gap = preg_replace('/the (\w+) \("([^"]*)"\) has no prop to go in/', 'the $1 has nowhere to go', $gap) ?? $gap;
    $gap = str_replace('no place for the ', 'no place for the ', $gap);
    return $gap;
  }

  /**
   * Joins items as "a, b and c".
   */
  private function sentenceList(array $items): string {
    $items = array_values(array_filter($items));
    if (!$items) {
      return '';
    }
    if (count($items) === 1) {
      return $items[0];
    }
    $last = array_pop($items);
    return implode(', ', $items) . ' and ' . $last;
  }

  /**
   * Turns a machine-ish region name into a human one.
   */
  private function humanize(string $s): string {
    $s = trim(str_replace(['_', '-'], ' ', $s));
    return $s === '' ? 'This part' : ucfirst($s);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
