<?php

declare(strict_types=1);

namespace Drupal\Tests\varbase_ai_figma\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\varbase_ai_figma\DesignResolver;
use Drupal\varbase_ai_figma\InventoryScanner;
use Drupal\varbase_ai_figma\RoleVocabulary;

/**
 * Pins the reuse-or-create decision, so the smart logic cannot silently rot.
 *
 * Each test states an intent a designer would have, and asserts the engine
 * reaches the same conclusion a good developer would:
 *   - a whole section that repeats three items belongs to the saved pattern
 *     that already repeats three items;
 *   - a single button belongs to the button component, not to a card;
 *   - a region that lists REAL site content belongs to a View, never to
 *     hand-frozen cards;
 *   - a component whose prop cannot accept the design's value is not a clean
 *     reuse;
 *   - and when nothing genuinely fits, the verdict is CREATE, with the gap
 *     spelled out rather than a component invented over one that exists.
 *
 * @coversDefaultClass \Drupal\varbase_ai_figma\DesignResolver
 * @group varbase_ai_figma
 */
final class DesignResolverTest extends UnitTestCase {

  /**
   * Builds a resolver over a fixed, fake library.
   */
  private function resolver(array $candidates): DesignResolver {
    $inventory = $this->createMock(InventoryScanner::class);
    $inventory->method('scan')->willReturn($candidates);
    $inventory->method('leafName')->willReturnCallback(
      static function (string $id): string {
        $parts = explode('.', $id);
        return (string) end($parts);
      }
    );
    // The vocabulary and the tuning both come from config, so the test supplies
    // the shipped defaults: the same numbers the module installs.
    $settings = [
      'resolver.role_hints' => [
        'heading' => ['heading', 'title', 'headline'],
        'body' => ['body', 'text', 'description', 'content'],
        'image' => ['image', 'media', 'picture'],
        'button' => ['button', 'cta'],
        'link' => ['link', 'url', 'menu'],
        'badge' => ['badge', 'tag'],
        'list' => ['items', 'list', 'menu'],
        'icon' => ['icon'],
        'quote' => ['quote', 'testimonial'],
        'stat' => ['stat', 'number'],
        'form' => ['form', 'webform'],
        'price' => ['price', 'cost'],
        'date' => ['date', 'time'],
      ],
      'resolver' => [
        'weights' => [
          'role_coverage' => 45,
          'structural_fit' => 20,
          'value_fit' => 10,
          'kind_fit' => 15,
          'name_affinity' => 10,
        ],
        'penalties' => [
          'missing_required' => 15,
          'dynamic_mismatch' => 20,
          'subject_mismatch' => 35,
        ],
        'thresholds' => ['reuse_at' => 85, 'adapt_at' => 60],
      ],
    ];
    $configFactory = $this->getConfigFactoryStub(['varbase_ai_figma.settings' => $settings]);
    $vocabulary = new RoleVocabulary($configFactory);
    return new DesignResolver($inventory, $vocabulary, $configFactory);
  }

  /**
   * A candidate in the shape InventoryScanner produces.
   */
  private function candidate(array $overrides): array {
    return $overrides + [
      'kind' => 'component',
      'id' => 'x',
      'label' => 'X',
      'source' => 'sdc',
      'version' => '1',
      'props' => [],
      'slots' => [],
      'roles' => [],
      'structure' => [],
      'dynamic' => FALSE,
    ];
  }

  /**
   * Three repeated items resolve to the pattern that repeats three.
   *
   * @covers ::resolve
   */
  public function testSectionOfThreeResolvesToTheThreeUpPattern(): void {
    $resolver = $this->resolver([
      'pattern:feature_grid' => $this->candidate([
        'kind' => 'pattern',
        'id' => 'feature_grid',
        'label' => 'Feature Grid',
        'roles' => ['heading', 'body', 'icon'],
        'structure' => ['repeat' => 3, 'total' => 7, 'depth' => 2],
      ]),
      'pattern:two_up' => $this->candidate([
        'kind' => 'pattern',
        'id' => 'two_up',
        'label' => 'Two Up',
        'roles' => ['heading', 'body', 'icon'],
        'structure' => ['repeat' => 2, 'total' => 5, 'depth' => 2],
      ]),
    ]);

    $plan = $resolver->resolve([
      [
        'name' => 'feature grid',
        'roles' => ['heading', 'body', 'icon'],
        'repeat' => 3,
        'section' => TRUE,
      ],
    ])[0];

    $this->assertSame('REUSE', $plan['verdict']);
    $this->assertSame('pattern:feature_grid', $plan['match']);
    // The pattern that already repeats exactly three must beat the two-up one.
    $this->assertSame('pattern:two_up', $plan['alternatives'][0]['match']);
    $this->assertGreaterThan($plan['alternatives'][0]['score'], $plan['score']);
  }

  /**
   * A single element resolves to a leaf component, not to a whole section.
   *
   * @covers ::resolve
   */
  public function testSingleElementResolvesToLeafComponent(): void {
    $resolver = $this->resolver([
      'component:button' => $this->candidate([
        'id' => 'sdc.theme.button',
        'label' => 'Button',
        'props' => ['button_text' => ['type' => 'string', 'enum' => [], 'required' => TRUE]],
        'roles' => ['button', 'link'],
      ]),
      'pattern:cta_band' => $this->candidate([
        'kind' => 'pattern',
        'id' => 'cta_band',
        'label' => 'CTA Band',
        'roles' => ['button', 'link', 'heading'],
        'structure' => ['repeat' => 1, 'total' => 3, 'depth' => 1],
      ]),
    ]);

    $plan = $resolver->resolve([
      ['name' => 'button', 'roles' => ['button', 'link'], 'section' => FALSE],
    ])[0];

    $this->assertSame('REUSE', $plan['verdict']);
    $this->assertSame('component:sdc.theme.button', $plan['match']);
  }

  /**
   * A region listing real content resolves to a View, never static cards.
   *
   * This is the mistake the engine exists to prevent: freezing live
   * content into hand-built markup that never updates.
   *
   * @covers ::resolve
   */
  public function testLiveContentResolvesToViewNotStaticCards(): void {
    $resolver = $this->resolver([
      // A static card grid that, on names and roles alone, looks perfect.
      'pattern:card_grid' => $this->candidate([
        'kind' => 'pattern',
        'id' => 'card_grid',
        'label' => 'Latest Posts Card Grid',
        'roles' => ['heading', 'image', 'date', 'list', 'body'],
        'structure' => ['repeat' => 3, 'total' => 7, 'depth' => 2],
        'dynamic' => FALSE,
      ]),
      'view:blog' => $this->candidate([
        'kind' => 'view',
        'id' => 'blog',
        'label' => 'Blog',
        'roles' => ['heading', 'image', 'date', 'list', 'body'],
        'structure' => ['block_displays' => ['block_1'], 'base_table' => 'node_field_data'],
        'dynamic' => TRUE,
      ]),
    ]);

    $plan = $resolver->resolve([
      [
        'name' => 'latest posts',
        'roles' => ['heading', 'image', 'date', 'list'],
        'repeat' => 3,
        'dynamic' => TRUE,
        'section' => TRUE,
      ],
    ])[0];

    $this->assertSame('view:blog', $plan['match'], 'A live listing must resolve to the View.');
    $this->assertNotSame('CREATE', $plan['verdict']);
  }

  /**
   * A prop that cannot accept the design's value is reported, not used.
   *
   * Binding a value an enum rejects is exactly what makes a Canvas build fail
   * validation, so the engine must catch it before anything is placed.
   *
   * @covers ::score
   */
  public function testValueOutsideTheEnumIsReportedAsGap(): void {
    $good = $this->candidate([
      'id' => 'sdc.theme.section-dark',
      'label' => 'Section',
      'props' => [
        'background_color' => ['type' => 'string', 'enum' => ['bg-light', 'bg-dark'], 'required' => FALSE],
      ],
      'slots' => ['col_1'],
      'roles' => ['heading', 'body'],
    ]);
    $bad = $this->candidate([
      'id' => 'sdc.theme.section-light',
      'label' => 'Section Light',
      'props' => [
        'background_color' => ['type' => 'string', 'enum' => ['bg-light'], 'required' => FALSE],
      ],
      'slots' => ['col_1'],
      'roles' => ['heading', 'body'],
    ]);
    $resolver = $this->resolver([]);

    $region = [
      'name' => 'hero',
      'roles' => ['heading', 'body'],
      'repeat' => 1,
      'dynamic' => FALSE,
      'section' => TRUE,
      'values' => ['background_color' => 'bg-dark'],
      'required' => [],
    ];

    $accepts = $resolver->score($region, $good);
    $rejects = $resolver->score($region, $bad);

    // The one that accepts the value binds it and scores higher.
    $this->assertSame(['background_color' => 'bg-dark'], $accepts['bind']);
    $this->assertGreaterThan($rejects['score'], $accepts['score']);
    // The one that cannot take it says so, instead of being placed and failing.
    $this->assertEmpty($rejects['bind']);
    $this->assertNotEmpty(array_filter(
      $rejects['gaps'],
      static fn(string $g): bool => str_contains($g, 'does not accept')
    ));
  }

  /**
   * When nothing genuinely fits, the verdict is CREATE and the gap is named.
   *
   * @covers ::resolve
   */
  public function testGenuineGapResolvesToCreateWithMissingPartsNamed(): void {
    $resolver = $this->resolver([
      'component:card' => $this->candidate([
        'id' => 'sdc.theme.card-pricing',
        'label' => 'Pricing Card',
        'roles' => ['price', 'heading'],
      ]),
    ]);

    $plan = $resolver->resolve([
      [
        'name' => 'pricing calculator',
        'roles' => ['price', 'form', 'stat'],
        'required' => ['form'],
        'section' => TRUE,
      ],
    ])[0];

    $this->assertSame('CREATE', $plan['verdict']);
    // It still names the closest thing, so the new component is scoped to the
    // gap instead of duplicating the pricing card that already exists.
    $this->assertSame('component:sdc.theme.card-pricing', $plan['match']);
    $gaps = implode(' ', $plan['gaps']);
    $this->assertStringContainsString('form', $gaps);
    $this->assertStringContainsString('stat', $gaps);
  }

  /**
   * A listing about the wrong subject loses, even with identical roles.
   *
   * Every view that renders teasers declares the same roles - a title, an
   * image, a date - so on roles alone the Blog view and the Team view are
   * indistinguishable, and a "team grid" would pick whichever sorted first.
   * What separates them is WHAT they list.
   *
   * @covers ::resolve
   */
  public function testTheViewAboutTheRightSubjectWins(): void {
    $roles = ['heading', 'image', 'body', 'date', 'list'];
    $resolver = $this->resolver([
      'view:blog' => $this->candidate([
        'kind' => 'view',
        'id' => 'blog',
        'label' => 'Blog',
        'roles' => $roles,
        'structure' => ['subjects' => ['node', 'blog', 'article']],
        'dynamic' => TRUE,
      ]),
      'view:team' => $this->candidate([
        'kind' => 'view',
        'id' => 'team',
        'label' => 'Team',
        'roles' => $roles,
        'structure' => ['subjects' => ['node', 'team', 'person']],
        'dynamic' => TRUE,
      ]),
    ]);

    $plan = $resolver->resolve([
      [
        'name' => 'team members',
        'roles' => ['heading', 'image', 'body'],
        'repeat' => 4,
        'dynamic' => TRUE,
        'section' => TRUE,
      ],
    ])[0];

    $this->assertSame('view:team', $plan['match'], 'The team region must not pick the blog view.');
  }

  /**
   * A menu region resolves to the menu BLOCK, not to some listing view.
   *
   * A menu and a breadcrumb are live content, but they are blocks. Treating
   * "dynamic" as a synonym for "view" is how a navigation region ends up
   * rendered as an article listing.
   *
   * @covers ::resolve
   */
  public function testMenuResolvesToTheMenuBlockNotView(): void {
    $resolver = $this->resolver([
      'block:menu' => $this->candidate([
        'kind' => 'block',
        'id' => 'menu_block:main',
        'label' => 'Main navigation',
        'roles' => ['link', 'list'],
        'structure' => ['category' => 'Menus'],
        'dynamic' => TRUE,
      ]),
      'view:content' => $this->candidate([
        'kind' => 'view',
        'id' => 'content',
        'label' => 'Content',
        'roles' => ['link', 'list', 'heading', 'body'],
        'structure' => ['subjects' => ['node', 'content']],
        'dynamic' => TRUE,
      ]),
    ]);

    $plan = $resolver->resolve([
      [
        'name' => 'main menu',
        'roles' => ['link', 'list'],
        'dynamic' => TRUE,
        'section' => FALSE,
      ],
    ])[0];

    $this->assertSame('block:menu_block:main', $plan['match']);
  }

  /**
   * A candidate whose subject is unknown is not convicted of the wrong one.
   *
   * The subject gate must only fire on evidence. Penalising a thing that never
   * declared a subject would push every region towards static markup.
   *
   * @covers ::score
   */
  public function testUnknownSubjectIsNotPenalised(): void {
    $resolver = $this->resolver([]);
    $region = [
      'name' => 'latest posts',
      'roles' => ['heading', 'image'],
      'repeat' => 3,
      'dynamic' => TRUE,
      'section' => TRUE,
      'values' => [],
      'required' => [],
    ];
    $noSubject = $this->candidate([
      'kind' => 'view',
      'id' => 'unnamed',
      'label' => 'Unnamed',
      'roles' => ['heading', 'image'],
      'structure' => [],
      'dynamic' => TRUE,
    ]);
    $wrongSubject = $this->candidate([
      'kind' => 'view',
      'id' => 'invoices',
      'label' => 'Invoices',
      'roles' => ['heading', 'image'],
      'structure' => ['subjects' => ['invoice', 'billing']],
      'dynamic' => TRUE,
    ]);

    $this->assertGreaterThan(
      $resolver->score($region, $wrongSubject)['score'],
      $resolver->score($region, $noSubject)['score'],
      'A declared, wrong subject must be punished; an absent one must not be.'
    );
  }

  /**
   * The design's content lands in the component's real props, not in guesses.
   *
   * Choosing the right component is only half of a 1:1 build. The heading has
   * to end up in the prop that IS the heading. Anything with nowhere to go is
   * reported, never dropped in silence.
   *
   * @covers ::resolve
   */
  public function testTheContentIsBoundToTheRealPropNames(): void {
    $resolver = $this->resolver([
      'component:hero' => $this->candidate([
        'id' => 'sdc.theme.card-hero',
        'label' => 'Hero Card',
        'props' => [
          // Deliberately unconventional names: the engine must read them, not
          // assume "heading"/"body".
          'title' => ['type' => 'string', 'enum' => [], 'required' => TRUE],
          'content_text' => ['type' => 'string', 'enum' => [], 'required' => FALSE],
          'button_1_label' => ['type' => 'string', 'enum' => [], 'required' => FALSE],
          'media' => ['type' => 'string', 'enum' => [], 'required' => FALSE],
          // An enum prop is a style switch and must never receive prose.
          'background_color' => ['type' => 'string', 'enum' => ['bg-light', 'bg-dark'], 'required' => FALSE],
        ],
        'slots' => ['content'],
        'roles' => ['heading', 'body', 'button', 'image'],
      ]),
    ]);

    $plan = $resolver->resolve([
      [
        'name' => 'project card',
        'section' => TRUE,
        'values' => ['background_color' => 'bg-dark'],
        'content' => [
          'heading' => 'UNHCR Donations Platform',
          'body' => 'A centralized donations platform.',
          'button' => 'Read case study',
          'image' => '/files/unhcr.jpg',
          // The component has no badge prop: this must be reported.
          'badge' => 'Non-Profit',
        ],
      ],
    ])[0];

    $this->assertSame('component:sdc.theme.card-hero', $plan['match']);
    $this->assertSame('UNHCR Donations Platform', $plan['bind']['title']);
    $this->assertSame('A centralized donations platform.', $plan['bind']['content_text']);
    $this->assertSame('Read case study', $plan['bind']['button_1_label']);
    $this->assertSame('/files/unhcr.jpg', $plan['bind']['media']);
    $this->assertSame('bg-dark', $plan['bind']['background_color']);
    // Prose must never be written into a style enum.
    $this->assertNotSame('UNHCR Donations Platform', $plan['bind']['background_color']);
    // The badge had nowhere to go, and the plan says so.
    $this->assertNotEmpty(array_filter(
      $plan['gaps'],
      static fn(string $g): bool => str_contains($g, 'badge')
    ));
  }

  /**
   * A candidate that holds none of the needed roles is never proposed.
   *
   * @covers ::score
   */
  public function testSomethingHoldingNoneOfTheRolesScoresZero(): void {
    $resolver = $this->resolver([]);
    $result = $resolver->score(
      [
        'name' => 'hero',
        'roles' => ['heading', 'image'],
        'repeat' => 1,
        'dynamic' => FALSE,
        'section' => TRUE,
        'values' => [],
        'required' => [],
      ],
      $this->candidate([
        'id' => 'sdc.theme.spacer',
        'label' => 'Spacer',
        'roles' => [],
      ])
    );
    $this->assertSame(0, $result['score']);
  }

  /**
   * A repeating design is one role said many times, not many unknown roles.
   *
   * The agent describes four feature cards as card1_heading, card1_body,
   * card2_heading … Taken literally those are unknown roles that nothing can
   * hold, and the engine recommends BUILDING what the site already has. They
   * must reduce to heading + body, repeated four times.
   */
  public function testNumberedRolesReduceToOneRoleRepeated(): void {
    $resolver = $this->resolver([
      'pattern:feature_cards' => [
        'kind' => 'pattern',
        'id' => 'feature_cards',
        'label' => 'Feature Cards',
        'roles' => ['heading', 'body', 'icon'],
        'props' => [],
        'slots' => [],
        'dynamic' => FALSE,
        'structure' => ['total' => 9, 'depth' => 3, 'repeat' => 4],
      ],
    ]);

    $plans = $resolver->resolve([
      [
        'name' => 'feature_cards_grid',
        'section' => TRUE,
        'content' => [
          'card1_heading' => 'Fast',
          'card1_body' => 'Very fast indeed',
          'card2_heading' => 'Safe',
          'card2_body' => 'Very safe indeed',
          'card3_heading' => 'Open',
          'card3_body' => 'Very open indeed',
          'card4_heading' => 'Kind',
          'card4_body' => 'Very kind indeed',
        ],
      ],
    ]);

    $plan = $plans[0];
    $this->assertSame(4, $plan['repeat'], 'the four numbered cards become a repeat of 4');
    $this->assertNotSame('CREATE', $plan['verdict'], 'a matching saved section must not be rebuilt from scratch');
    $this->assertSame('pattern:feature_cards', $plan['match']);
  }

  /**
   * A thing can BE the role without naming a prop after it.
   *
   * The Button component IS a button, but its text lives in "label". The engine
   * used to report that the button had nowhere to go while staring straight at
   * the prop it goes in.
   */
  public function testButtonTextGoesInItsLabelProp(): void {
    $resolver = $this->resolver([
      'component:button' => [
        'kind' => 'component',
        'id' => 'sdc.theme.button',
        'label' => 'Button',
        'roles' => ['button'],
        'props' => [
          'label' => ['type' => 'string', 'enum' => [], 'required' => TRUE],
          'href' => ['type' => 'string', 'enum' => [], 'required' => FALSE],
          'variant' => ['type' => 'string', 'enum' => ['primary', 'secondary'], 'required' => FALSE],
        ],
        'slots' => [],
        'dynamic' => FALSE,
        'structure' => [],
      ],
    ]);

    $plans = $resolver->resolve([
      [
        'name' => 'button_group',
        'roles' => ['button'],
        'content' => ['button1_text' => 'Get started', 'button2_text' => 'Learn more'],
      ],
    ]);

    $plan = $plans[0];
    $this->assertSame(2, $plan['repeat'], 'two numbered buttons means the button is used twice');
    $this->assertArrayHasKey('label', $plan['bind'], 'the button text belongs in the label prop');
    $this->assertSame('Get started', $plan['bind']['label']);
    $this->assertSame([], $plan['gaps'], 'nothing is missing: the text has somewhere to go');
  }

}
