<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * The shared vocabulary of content roles, read from config.
 *
 * A "role" is what a piece of a design IS: a heading, a body, an image, a
 * button, a badge. Both halves of the engine need this vocabulary - the
 * scanner to work out what a component can HOLD (from its real prop and
 * slot names), and the resolver to work out which prop a piece of content
 * should GO IN.
 *
 * The vocabulary itself lives in varbase_ai_figma.settings, not in this
 * file, so a site whose components speak a different language teaches the
 * engine a new word by editing config rather than by patching code.
 */
final class RoleVocabulary {

  /**
   * The hints, as loaded from config: role => name fragments.
   *
   * @var array<string, string[]>|null
   */
  private ?array $hints = NULL;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Every role this site knows about.
   *
   * @return string[]
   *   The role names.
   */
  public function roles(): array {
    return array_keys($this->hints());
  }

  /**
   * The role => name-fragment map, from config.
   *
   * @return array<string, string[]>
   *   The hints.
   */
  private function hints(): array {
    if ($this->hints === NULL) {
      $configured = $this->configFactory
        ->get('varbase_ai_figma.settings')
        ->get('resolver.role_hints');
      $this->hints = is_array($configured) ? array_map('array_values', $configured) : [];
    }
    return $this->hints;
  }

  /**
   * Reduces one design-side name to a role, and the item it belongs to.
   *
   * A design that repeats - four feature cards, two buttons - gets described
   * with numbered names: card1_heading, card1_body, card2_heading,
   * button1_text. Those are not roles; they are one role said four times.
   * Taken literally they look like four unknown roles that nothing on the
   * site can hold, and the
   * engine then recommends BUILDING what the site already has. So reduce them:
   * card1_heading is the heading of item 1.
   *
   * @param string $name
   *   A design-side name, possibly numbered.
   *
   * @return array{role: string, item: int}
   *   The canonical role ('' when it maps to nothing we know), and the 1-based
   *   item it belongs to (1 when the name carries no number).
   */
  public function canonicalize(string $name): array {
    $raw = mb_strtolower(trim($name));
    $item = 1;

    // Numbered names: card1_heading, card_1_heading, item2title, button1_text.
    if (preg_match('/^(.*?)[_\-]?(\d+)[_\-]?(.*)$/', $raw, $m)) {
      $n = (int) $m[2];
      if ($n > 0) {
        $item = $n;
        // Both halves are candidates: "card1_heading" -> card + heading.
        $raw = trim($m[1] . ' ' . $m[3], " _-");
      }
    }

    $parts = array_values(array_filter(preg_split('/[^a-z0-9]+/', $raw) ?: []));
    if (!$parts) {
      return ['role' => '', 'item' => $item];
    }

    $wrappers = ['card', 'item', 'feature', 'block', 'tile', 'column', 'cell', 'entry', 'slide', 'step'];
    $roles = $this->roles();

    // Words that ARE roles beat words that merely hint at one.
    //
    // "button1_text": only "button" is a role - "text" just hints at body - so
    // the button wins, and its label is not filed as body copy.
    //
    // "stat1_heading": BOTH are roles. The name reads thing-then-aspect,
    // and the aspect is what this string actually is: the heading OF the
    // stat. Take the last, or a counters design asks only for "stat",
    // loses its headings, and gets answered by a spinner.
    $exact = array_values(array_filter(
      $parts,
      static fn(string $p): bool => in_array($p, $roles, TRUE) && !in_array($p, $wrappers, TRUE)
    ));
    if ($exact) {
      return ['role' => end($exact), 'item' => $item];
    }

    // Otherwise fall back to the name fragments. A leaf word still beats a
    // wrapper word: in "card1_heading" the role that matters is the heading,
    // not the card.
    $best = '';
    foreach ($this->hints() as $role => $fragments) {
      foreach ($fragments as $fragment) {
        foreach ($parts as $part) {
          if ($part === '' || !str_contains($part, (string) $fragment)) {
            continue;
          }
          if (in_array($part, $wrappers, TRUE)) {
            $best = $best ?: $role;
            continue;
          }
          return ['role' => $role, 'item' => $item];
        }
      }
    }

    return ['role' => $best, 'item' => $item];
  }

  /**
   * The roles a set of real prop and slot names can carry.
   *
   * @param string[] $names
   *   Real prop names.
   * @param string[] $slots
   *   Real slot names.
   * @param string $hint
   *   An id or label, used only as a weak extra signal when there are no props.
   *
   * @return string[]
   *   The roles these names can hold.
   */
  public function rolesOf(array $names, array $slots = [], string $hint = ''): array {
    $hay = array_map('mb_strtolower', array_merge($names, $slots));
    $roles = [];
    foreach ($this->hints() as $role => $fragments) {
      foreach ($fragments as $fragment) {
        foreach ($hay as $name) {
          if (str_contains($name, (string) $fragment)) {
            $roles[$role] = TRUE;
            continue 3;
          }
        }
      }
    }
    // A thing with no props at all (a block, a view) can still declare its
    // purpose in its name. This is a fallback, never the primary signal.
    if ($hint !== '') {
      $lower = mb_strtolower($hint);
      foreach ($this->hints() as $role => $fragments) {
        if (isset($roles[$role])) {
          continue;
        }
        foreach ($fragments as $fragment) {
          if (str_contains($lower, (string) $fragment)) {
            $roles[$role] = TRUE;
            break;
          }
        }
      }
    }
    // A slot can host arbitrary children, which covers body content.
    if ($slots && !isset($roles['body'])) {
      $roles['body'] = TRUE;
    }
    return array_keys($roles);
  }

  /**
   * The prop that best carries a role, chosen by its REAL name.
   *
   * @param string $role
   *   The content role, e.g. "heading".
   * @param array $props
   *   Real props: name => ['type' => …, 'enum' => […], 'required' => bool].
   * @param array $taken
   *   Props already bound, so two roles never fight over one prop.
   *
   * @return string|null
   *   The prop name, or NULL when there is nowhere to put this content.
   */
  public function bestPropFor(string $role, array $props, array $taken = []): ?string {
    $best = NULL;
    $bestScore = 0;
    foreach ($props as $name => $spec) {
      if (isset($taken[$name])) {
        continue;
      }
      // An enum prop is a style switch, not a content slot: a heading never
      // belongs in a prop that only accepts "bg-dark" or "pt-5".
      if (!empty($spec['enum'])) {
        continue;
      }
      if (!in_array($role, $this->rolesOf([(string) $name]), TRUE)) {
        continue;
      }
      // Among props that carry the role, prefer the most specific name:
      // "heading_text" beats a generic "text" for a heading.
      $score = str_contains(mb_strtolower((string) $name), $role) ? 2 : 1;
      if ($score > $bestScore) {
        $best = (string) $name;
        $bestScore = $score;
      }
    }
    if ($best !== NULL) {
      return $best;
    }

    // A thing can BE the role without naming a prop after it. The Button
    // component is a button, but its text lives in "label" - so the engine
    // reported that the button had nowhere to go while staring straight at the
    // prop it goes in. When a thing is the role itself, its text belongs in its
    // main text prop.
    if (!in_array($role, $this->plainTextRoles(), TRUE)) {
      return NULL;
    }
    foreach ($this->textProps() as $candidate) {
      foreach ($props as $name => $spec) {
        if (isset($taken[$name]) || !empty($spec['enum'])) {
          continue;
        }
        if (mb_strtolower((string) $name) === $candidate) {
          return (string) $name;
        }
      }
    }
    return NULL;
  }

  /**
   * Roles whose content is a short piece of text the thing itself carries.
   *
   * @return string[]
   *   The role names.
   */
  private function plainTextRoles(): array {
    $configured = $this->configFactory
      ->get('varbase_ai_figma.settings')
      ->get('resolver.plain_text_roles');
    return is_array($configured) && $configured
      ? array_values($configured)
      : ['button', 'badge', 'quote', 'stat', 'price', 'link'];
  }

  /**
   * The prop names a thing keeps its own text in, most specific first.
   *
   * @return string[]
   *   Lower-case prop names.
   */
  private function textProps(): array {
    $configured = $this->configFactory
      ->get('varbase_ai_figma.settings')
      ->get('resolver.text_props');
    return is_array($configured) && $configured
      ? array_map('mb_strtolower', array_values($configured))
      : ['label', 'text', 'title', 'value', 'caption'];
  }

}
