<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Decides, for each region of a design, what to reuse and what to create.
 *
 * This is the reasoning the agent used to have to improvise. It scores every
 * candidate the site already ships (components, patterns, blocks, views)
 * against what a design region actually NEEDS, and returns one of three
 * verdicts with the evidence behind it:
 *
 *   REUSE  - it already covers the region; bind these props and place it.
 *   ADAPT  - the closest thing covers most of it; here are the exact props to
 *            set and the exact gaps to fill (extend it rather than reinvent).
 *   CREATE - nothing covers it; here is precisely what is missing, so the new
 *            component is scoped to the gap instead of duplicating what exists.
 *
 * THE EQUATION (all weights are constants below, so the decision is auditable):
 *
 *   score = 45 * roleCoverage      // does it really hold every role the region
 *                                  // needs? read from actual props + slots
 *         + 20 * structuralFit     // right shape: repeats, children, nesting
 *         + 10 * valueFit          // can the props actually take the design's
 *                                  // values (enums, types)
 *         + 15 * kindFit           // a whole section wants a PATTERN; an atom
 *                                  // wants a COMPONENT; repeating real content
 *                                  // wants a VIEW/BLOCK, not frozen cards
 *         + 10 * nameAffinity      // last and weakest: the name
 *         - penalties              // missing required roles, dynamic mismatch
 *
 * The name used to be worth 45 points on its own. It is now worth 10, and
 * what a thing can genuinely hold is worth 45. That is the whole point:
 * match on capability, not on vocabulary.
 */
final class DesignResolver {

  /**
   * The tuning, read from varbase_ai_figma.settings.
   *
   * Every number the decision turns on - what each signal is worth, what the
   * engine subtracts for, and where the verdict lines fall - is config, so a
   * team that wants the agent to reuse harder or create more freely changes a
   * value rather than a class.
   */
  private array $tuning;

  public function __construct(
    private readonly InventoryScanner $inventory,
    private readonly RoleVocabulary $vocabulary,
    ConfigFactoryInterface $configFactory,
  ) {
    $resolver = (array) ($configFactory->get('varbase_ai_figma.settings')->get('resolver') ?: []);
    $this->tuning = [
      'w' => (array) ($resolver['weights'] ?? []) + [
        'role_coverage' => 45,
        'structural_fit' => 20,
        'value_fit' => 10,
        'kind_fit' => 15,
        'name_affinity' => 10,
      ],
      'p' => (array) ($resolver['penalties'] ?? []) + [
        'missing_required' => 15,
        'dynamic_mismatch' => 20,
        'subject_mismatch' => 35,
      ],
      't' => (array) ($resolver['thresholds'] ?? []) + [
        'reuse_at' => 85,
        'adapt_at' => 60,
      ],
    ];
  }

  /**
   * Resolves every region of a design against everything the site can reuse.
   *
   * @param array[] $regions
   *   Each region: [
   *     'name'    => 'hero',            // free label from the design
   *     'roles'   => ['heading','body','button','image'],
   *     'repeat'  => 3,                 // how many identical items (0/1 = one)
   *     'dynamic' => FALSE,             // repeats REAL content (a listing)
   *     'section' => TRUE,              // a whole section vs a single atom
   *     'values'  => ['background_color' => 'bg-dark'], // mapped tokens
   *   ].
   * @param array $kinds
   *   Restrict the palette. Empty = components + patterns + blocks + views.
   *
   * @return array[]
   *   One plan per region: verdict, chosen candidate, score, why, gaps, props.
   */
  public function resolve(array $regions, array $kinds = []): array {
    $candidates = $this->inventory->scan($kinds);
    $plans = [];
    foreach ($regions as $region) {
      $plans[] = $this->resolveRegion($this->normalizeRegion($region), $candidates);
    }
    return $plans;
  }

  /**
   * Fills in a region's defaults so callers can pass a partial spec.
   */
  private function normalizeRegion(array $r): array {
    $content = (array) ($r['content'] ?? []);
    $rawRoles = array_values(array_unique(array_filter((array) ($r['roles'] ?? []))));
    // The content the design actually carries implies the roles it needs, so a
    // caller that passes content does not have to repeat itself.
    $rawRoles = array_values(array_unique(array_merge($rawRoles, array_keys($content))));

    // A repeating design is described with numbered names - card1_heading,
    // card2_heading, button1_text. Left as-is they read as unknown roles that
    // nothing can hold, and the engine recommends BUILDING what the site
    // already has. Reduce them to the role they actually are, and count the
    // items to learn how often the region repeats.
    $roles = [];
    $items = 1;
    $byItem = [];
    foreach ($rawRoles as $raw) {
      ['role' => $role, 'item' => $item] = $this->vocabulary->canonicalize((string) $raw);
      if ($role === '') {
        continue;
      }
      $roles[$role] = TRUE;
      $items = max($items, $item);
    }
    // Keep the design's content reachable per item, so the caller can still see
    // which card each string belongs to once the thing to reuse is chosen.
    foreach ($content as $key => $value) {
      ['role' => $role, 'item' => $item] = $this->vocabulary->canonicalize((string) $key);
      if ($role !== '') {
        $byItem[$item][$role] = $value;
      }
    }

    // An explicit repeat always wins; otherwise the numbering tells us.
    $repeat = (int) ($r['repeat'] ?? 0);
    $repeat = $repeat > 0 ? $repeat : $items;

    // Bind against one item's worth of content: reusing a thing four times is
    // not the same as needing a thing with four sets of props.
    $first = $byItem[1] ?? $content;

    return [
      'name' => (string) ($r['name'] ?? 'region'),
      'roles' => array_keys($roles),
      'repeat' => max(1, $repeat),
      'dynamic' => (bool) ($r['dynamic'] ?? FALSE),
      'section' => (bool) ($r['section'] ?? FALSE),
      'values' => (array) ($r['values'] ?? []),
      'required' => array_values((array) ($r['required'] ?? [])),
      'content' => $first,
      'items' => $byItem,
    ];
  }

  /**
   * Binds the design's real content to the chosen thing's real prop names.
   *
   * Picking the right component is only half of a 1:1 build. The other half is
   * putting the design's heading in the prop that IS the heading, its body in
   * the body, its button label in the button - which requires knowing the
   * component's actual prop names, not guessing at conventions. Anything the
   * component has no prop for is reported rather than dropped in silence.
   *
   * @param array $region
   *   The normalised region, including its 'content' keyed by role.
   * @param array $c
   *   The chosen candidate.
   * @param string[] $unplaced
   *   Filled with the content that had nowhere to go.
   *
   * @return array
   *   Prop name => value, ready to set on the placed component.
   */
  private function bindContent(array $region, array $c, array &$unplaced): array {
    $bind = [];
    $props = (array) $c['props'];
    if (!$props) {
      // A pattern, block or view carries its own content; nothing to bind.
      return $bind;
    }
    $taken = [];
    foreach ((array) $region['content'] as $role => $value) {
      $role = (string) $role;
      $prop = $this->vocabulary->bestPropFor($role, $props, $taken);
      if ($prop === NULL) {
        $unplaced[] = sprintf('the %s ("%s") has no prop to go in', $role, mb_substr((string) $value, 0, 30));
        continue;
      }
      $taken[$prop] = TRUE;
      $bind[$prop] = $value;
    }
    return $bind;
  }

  /**
   * Scores every candidate for one region and picks the verdict.
   */
  private function resolveRegion(array $region, array $candidates): array {
    $scored = [];
    foreach ($candidates as $key => $c) {
      $s = $this->score($region, $c);
      if ($s['score'] > 0) {
        $scored[$key] = $s + ['candidate' => $c];
      }
    }
    uasort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    $top = array_slice($scored, 0, 5, TRUE);
    $best = $top ? reset($top) : NULL;

    if (!$best) {
      return [
        'region' => $region['name'],
        'verdict' => 'CREATE',
        'score' => 0,
        'match' => NULL,
        'why' => ['nothing in the library holds any of the roles this region needs'],
        'gaps' => $region['roles'],
        'bind' => [],
        'alternatives' => [],
      ];
    }

    $score = $best['score'];
    $verdict = $score >= $this->tuning['t']['reuse_at']
      ? 'REUSE'
      : ($score >= $this->tuning['t']['adapt_at'] ? 'ADAPT' : 'CREATE');

    // Bind the design's real content to the chosen thing's real prop names, so
    // the plan is directly placeable rather than merely correct in principle.
    $unplaced = [];
    $contentBind = $this->bindContent($region, $best['candidate'], $unplaced);
    $gaps = array_values(array_unique(array_merge((array) $best['gaps'], $unplaced)));

    // A CREATE verdict still reports the closest thing, so the new component is
    // scoped to the gap and does not duplicate what already exists.
    return [
      'region' => $region['name'],
      'verdict' => $verdict,
      'score' => $score,
      'match' => $best['candidate']['kind'] . ':' . $best['candidate']['id'],
      'label' => $best['candidate']['label'],
      'kind' => $best['candidate']['kind'],
      'repeat' => (int) $region['repeat'],
      'why' => $best['why'],
      'gaps' => $gaps,
      // Style values first, then the content, both keyed by REAL prop names.
      'bind' => $best['bind'] + $contentBind,
      'items' => (array) ($region['items'] ?? []),
      'alternatives' => array_values(array_map(
        fn($t) => [
          'match' => $t['candidate']['kind'] . ':' . $t['candidate']['id'],
          'label' => $t['candidate']['label'],
          'kind' => $t['candidate']['kind'],
          'score' => $t['score'],
        ],
        array_slice($top, 1, 4, TRUE)
      )),
    ];
  }

  /**
   * The equation. Returns the score plus the evidence behind it.
   */
  public function score(array $region, array $c): array {
    $why = [];
    $gaps = [];

    // --- 1. Role coverage: what it can ACTUALLY hold (props + slots). -------
    $needs = $region['roles'];
    $covers = (array) $c['roles'];
    $met = array_values(array_intersect($needs, $covers));
    $missing = array_values(array_diff($needs, $covers));
    $roleCoverage = $needs ? count($met) / count($needs) : 0.0;
    if ($met) {
      $why[] = 'holds ' . implode(', ', $met);
    }
    foreach ($missing as $m) {
      $gaps[] = 'no place for the ' . $m;
    }

    // A candidate that holds none of the needed roles is not a candidate.
    if ($needs && !$met) {
      return ['score' => 0, 'why' => [], 'gaps' => $needs, 'bind' => []];
    }

    // --- 2. Structural fit: the right shape, not just the right parts. ------
    $structuralFit = $this->structuralFit($region, $c, $why);

    // --- 3. Value fit: can the props take the design's mapped values? -------
    $bind = [];
    $valueFit = $this->valueFit($region, $c, $bind, $gaps);

    // --- 4. Kind fit: a section wants a pattern, a listing wants a view. ----
    $kindFit = $this->kindFit($region, $c, $why);

    // --- 5. Name affinity: last, and deliberately the weakest signal. -------
    $nameAffinity = $this->nameAffinity($region, $c);

    $w = $this->tuning['w'];
    // Being the right SHAPE only counts for the content you can actually hold.
    // A call-to-action banner is the right shape for a section, but it has
    // nowhere to put the design's image - and it was beating a component that
    // could hold every part of the design, purely on shape. A section can be
    // built out of components; a thing with nowhere to put your image cannot be
    // fixed by being the right shape.
    $shape = ($w['structural_fit'] * $structuralFit + $w['kind_fit'] * $kindFit) * $roleCoverage;
    $score = $w['role_coverage'] * $roleCoverage
      + $shape
      + $w['value_fit'] * $valueFit
      + $w['name_affinity'] * $nameAffinity;

    // --- Subject gate. -----------------------------------------------------
    // Everything that renders live content (a View, a listing block) declares
    // the same generic roles - a title, some text, an image, a date - because
    // that is what any teaser has. On roles alone the Blog view and the Team
    // view are indistinguishable, and every dynamic region picks whichever
    // happens to sort first. What actually separates them is the SUBJECT: what
    // they list. So for these candidates the subject is not a tie-breaker worth
    // ten points, it is a gate. A view about articles is not a "team grid", and
    // no amount of role overlap makes it one.
    // The gate only fires when the candidate actually DECLARES a subject. A
    // thing whose subject is unknown cannot be convicted of the wrong one.
    $subject = $this->subjectOf($c);
    if (!empty($c['dynamic']) && $subject !== '' && $nameAffinity <= 0.0) {
      $score -= $this->tuning['p']['subject_mismatch'];
      $gaps[] = sprintf(
        'lists %s, which is not what this region is about',
        $subject
      );
    }

    // --- Penalties. --------------------------------------------------------
    foreach ($region['required'] as $req) {
      if (!in_array($req, $covers, TRUE)) {
        $score -= $this->tuning['p']['missing_required'];
        $gaps[] = 'the region REQUIRES a ' . $req . ' and this cannot hold one';
      }
    }
    // Freezing live content into static markup is the mistake this prevents.
    if ($region['dynamic'] && empty($c['dynamic'])) {
      $score -= $this->tuning['p']['dynamic_mismatch'];
      $gaps[] = 'this is static, but the region repeats real site content (use a view or a block)';
    }

    return [
      'score' => (int) max(0, min(100, round($score))),
      'why' => $why ?: ['weak match'],
      'gaps' => $gaps,
      'bind' => $bind,
    ];
  }

  /**
   * Does it have the right SHAPE: repeats, children, nesting.
   */
  private function structuralFit(array $region, array $c, array &$why): float {
    $repeat = max(1, $region['repeat']);

    if ($c['kind'] === 'pattern') {
      $patternRepeat = (int) ($c['structure']['repeat'] ?? 0);
      if ($repeat > 1 && $patternRepeat === $repeat) {
        $why[] = sprintf('already a saved section with exactly %d repeated items', $repeat);
        return 1.0;
      }
      if ($repeat > 1 && $patternRepeat > 1) {
        // Right idea, wrong count: still a strong structural match, the count
        // is
        // an input, not a rebuild.
        $why[] = sprintf('a saved section that repeats items (%d vs the design\'s %d)', $patternRepeat, $repeat);
        return 0.75;
      }
      return $region['section'] ? 0.7 : 0.4;
    }

    if ($c['kind'] === 'component') {
      // Slots mean it can host the children a multi-part region needs.
      $slots = count((array) $c['slots']);
      if ($region['section'] && $slots > 0) {
        $why[] = 'has ' . $slots . ' slot(s) to host the section\'s children';
        return $slots >= 2 ? 0.9 : 0.7;
      }
      if (!$region['section']) {
        // A leaf region wants a leaf component: no slots is the right shape.
        return $slots === 0 ? 1.0 : 0.6;
      }
      return 0.3;
    }

    if ($c['kind'] === 'view') {
      // A view is inherently a repeating list.
      return $repeat > 1 || $region['dynamic'] ? 1.0 : 0.3;
    }

    // Blocks: a fine fit for a self-contained region, weak for a composed one.
    return $region['section'] ? 0.4 : 0.6;
  }

  /**
   * Can the real props take the design's mapped values (enums, types)?
   *
   * Also produces the exact prop bindings the caller should set, so a REUSE is
   * immediately placeable and an ADAPT states precisely what to change.
   */
  private function valueFit(array $region, array $c, array &$bind, array &$gaps): float {
    $values = $region['values'];
    if (!$values || !$c['props']) {
      return $c['props'] ? 0.5 : 0.0;
    }
    $ok = 0;
    foreach ($values as $prop => $value) {
      if (!isset($c['props'][$prop])) {
        $gaps[] = sprintf('no "%s" prop for the design value "%s"', $prop, (string) $value);
        continue;
      }
      $spec = $c['props'][$prop];
      if ($spec['enum'] && !in_array($value, $spec['enum'], TRUE)) {
        // The prop exists but will not accept this value: this is exactly the
        // class of error that makes a build fail validation.
        $gaps[] = sprintf('"%s" does not accept "%s" (allowed: %s)', $prop, (string) $value, implode(', ', array_slice($spec['enum'], 0, 6)));
        continue;
      }
      $bind[$prop] = $value;
      $ok++;
    }
    return count($values) ? $ok / count($values) : 0.5;
  }

  /**
   * Kind affinity for the region.
   *
   * A whole section wants a pattern; a listing wants a view; an atom a
   * component.
   */
  private function kindFit(array $region, array $c, array &$why): float {
    if ($region['dynamic']) {
      if ($c['kind'] === 'view') {
        $why[] = 'a real view, so the region stays live instead of frozen';
        return 1.0;
      }
      if ($c['kind'] === 'block' && !empty($c['views_block'])) {
        $why[] = 'an existing views block, so the listing stays live';
        return 0.95;
      }
      if ($c['kind'] === 'block') {
        return 0.7;
      }
      return 0.1;
    }
    if ($region['section']) {
      return match ($c['kind']) {
        'pattern' => 1.0,
        'component' => 0.6,
        'block' => 0.4,
        'view' => 0.2,
        default => 0.2,
      };
    }
    // A single atom.
    return match ($c['kind']) {
      'component' => 1.0,
      'block' => 0.5,
      'pattern' => 0.2,
      'view' => 0.1,
      default => 0.2,
    };
  }

  /**
   * A short human phrase for what a dynamic candidate is actually about.
   *
   * @param array $c
   *   The candidate.
   *
   * @return string
   *   What it lists, e.g. "article, blog" or its block category.
   */
  private function subjectOf(array $c): string {
    $subjects = (array) ($c['structure']['subjects'] ?? []);
    if ($subjects) {
      return implode(', ', array_slice($subjects, 0, 3));
    }
    return (string) ($c['structure']['category'] ?? '');
  }

  /**
   * The weakest signal: does the name look like the region.
   */
  private function nameAffinity(array $region, array $c): float {
    // For a view, the id and label are not enough: every view that renders
    // teasers holds the same roles, so what tells Team from Blog is WHAT it
    // lists. The same goes for a block, whose category carries its purpose.
    $extra = implode(' ', array_merge(
      (array) ($c['structure']['subjects'] ?? []),
      [(string) ($c['structure']['category'] ?? '')]
    ));
    $hay = mb_strtolower(trim(
      $this->inventory->leafName($c['id']) . ' ' . $c['label'] . ' ' . $extra
    ));
    $needle = mb_strtolower($region['name']);
    if ($needle === '' || $hay === '') {
      return 0.0;
    }
    if (str_contains($hay, $needle) || str_contains($needle, $hay)) {
      return 1.0;
    }
    $tokens = array_filter(preg_split('/[^a-z0-9]+/', $needle) ?: [], fn($t) => strlen($t) >= 3);
    if (!$tokens) {
      return 0.0;
    }
    $hits = 0;
    foreach ($tokens as $t) {
      if (str_contains($hay, $t)) {
        $hits++;
      }
    }
    return $hits / count($tokens);
  }

}
