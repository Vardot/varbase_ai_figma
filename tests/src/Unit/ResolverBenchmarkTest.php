<?php

declare(strict_types=1);

namespace Drupal\Tests\varbase_ai_figma\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\varbase_ai_figma\DesignResolver;
use Drupal\varbase_ai_figma\InventoryScanner;
use Drupal\varbase_ai_figma\RoleVocabulary;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The engine, measured against what a real Varbase 11 actually ships.
 *
 * The unit tests elsewhere pin one behaviour each. This one asks the question
 * the product is judged on: given the components and saved sections a fresh
 * Varbase 11 really has, and the sections a real design really asks for, how
 * often does the engine reach for something that already exists?
 *
 * The inventory is the real one, exported from a fresh install: the
 * theme's own components with their real props, and the 14 saved
 * sections with their real trees. The regions are written the way the
 * assistant really describes a design - numbered names and all.
 *
 * @group varbase_ai_figma
 */
#[RunTestsInSeparateProcesses]
class ResolverBenchmarkTest extends UnitTestCase {

  /**
   * Every section a real design asks for, and what should answer it.
   *
   * @return array<string, array>
   *   Region spec, plus the verdict we refuse to regress past.
   */
  public static function designs(): array {
    return [
      'hero' => [
        [
          'name' => 'hero',
          'section' => TRUE,
          'content' => [
            'heading' => 'Varbase, better than ever',
            'body' => 'Elevate your digital experience.',
            'button' => 'Try Varbase for free',
            'image' => 'hero.jpg',
          ],
        ],
        'component:sdc.vartheme_bs5.card-hero',
      ],
      'feature cards' => [
        [
          'name' => 'feature_cards',
          'section' => TRUE,
          'content' => [
            'card1_heading' => 'AI integration',
            'card1_body' => 'Built in.',
            'card2_heading' => 'Responsive',
            'card2_body' => 'Every screen.',
            'card3_heading' => 'Multilingual',
            'card3_body' => 'Out of the box.',
            'card4_heading' => 'Secure',
            'card4_body' => 'Enterprise grade.',
          ],
        ],
        'pattern:feature_cards',
      ],
      'call to action' => [
        [
          'name' => 'call_to_action',
          'section' => TRUE,
          'content' => [
            'heading' => 'Ready to start?',
            'body' => 'Get in touch today.',
            'button' => 'Contact us',
          ],
        ],
        'pattern:cta_banner',
      ],
      'frequently asked questions' => [
        [
          'name' => 'faq',
          'section' => TRUE,
          'content' => [
            'q1_heading' => 'What is Varbase?',
            'q1_body' => 'A distribution.',
            'q2_heading' => 'Is it free?',
            'q2_body' => 'Yes.',
            'q3_heading' => 'Who maintains it?',
            'q3_body' => 'Vardot.',
          ],
        ],
        'pattern:faq_accordion',
      ],
      'counters' => [
        [
          'name' => 'counters',
          'section' => TRUE,
          'content' => [
            'stat1_heading' => '10k',
            'stat1_body' => 'Sites',
            'stat2_heading' => '99%',
            'stat2_body' => 'Uptime',
            'stat3_heading' => '24/7',
            'stat3_body' => 'Support',
          ],
        ],
        'pattern:counters',
      ],
      'media banner' => [
        [
          'name' => 'media_banner',
          'section' => TRUE,
          'content' => [
            'heading' => 'Built to empower',
            'body' => 'One platform.',
            'image' => 'banner.jpg',
            'button' => 'Learn more',
          ],
        ],
        '',
      ],
      'latest blog posts (live content)' => [
        [
          'name' => 'latest_blog_posts',
          'section' => TRUE,
          'dynamic' => TRUE,
          'content' => [
            'heading' => 'From the blog',
            'post1_heading' => 'A post',
            'post1_body' => 'Some words.',
            'post2_heading' => 'Another post',
            'post2_body' => 'More words.',
          ],
        ],
        '',
      ],
      'contact form' => [
        [
          'name' => 'contact',
          'section' => TRUE,
          'content' => [
            'heading' => 'Get in touch',
            'body' => 'We reply within a day.',
            'form' => 'contact',
          ],
        ],
        '',
      ],
      'testimonial' => [
        [
          'name' => 'testimonial',
          'section' => TRUE,
          'content' => [
            'quote' => 'Varbase saved us months.',
            'author' => 'A happy client',
            'image' => 'face.jpg',
          ],
        ],
        '',
      ],
      'logo strip' => [
        [
          'name' => 'logo_strip',
          'section' => TRUE,
          'content' => [
            'logo1_image' => 'a.svg',
            'logo2_image' => 'b.svg',
            'logo3_image' => 'c.svg',
            'logo4_image' => 'd.svg',
          ],
        ],
        '',
      ],
    ];
  }

  /**
   * A design a real site can already answer must not be rebuilt from scratch.
   *
   * @param array $region
   *   The region, as the assistant would describe it.
   * @param string $expected
   *   The thing on the site that should answer this design.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('designs')]
  public function testTheSiteAnswersItsOwnDesigns(array $region, string $expected): void {
    $plans = $this->resolver()->resolve([$region]);
    $plan = $plans[0];

    $this->assertNotSame(
      'CREATE',
      $plan['verdict'],
      sprintf(
        'the site already has something for "%s", but the engine wants to build one: closest was "%s" at %d/100',
        $region['name'],
        (string) ($plan['label'] ?? 'nothing'),
        (int) $plan['score']
      )
    );
    $this->assertNotEmpty($plan['label'], 'a reused thing must be nameable to a site builder');
    if ($expected !== '') {
      $this->assertSame(
        $expected,
        $plan['match'],
        sprintf('"%s" should be answered by %s, but the engine chose %s (%d/100)', $region['name'], $expected, (string) $plan['match'], (int) $plan['score'])
      );
    }

    // Whatever it picks, it must be able to hold the design. Being the right
    // shape is no use if the design's image has nowhere to go.
    $homeless = array_filter(
      (array) $plan['gaps'],
      static fn(string $gap): bool => str_starts_with($gap, 'no place for the ')
    );
    $this->assertSame(
      [],
      array_values($homeless),
      sprintf('"%s" was answered by "%s", which cannot hold: %s', $region['name'], (string) $plan['label'], implode(', ', $homeless))
    );
  }

  /**
   * A resolver loaded with the inventory a fresh Varbase 11 really ships.
   */
  private function resolver(): DesignResolver {
    $settings = [
      'resolver' => [
        'role_hints' => [
          'heading' => ['heading', 'title', 'headline', 'subtitle', 'suptitle'],
          'body' => ['body', 'text', 'description', 'summary', 'content', 'excerpt', 'intro'],
          'image' => ['image', 'media', 'picture', 'photo', 'thumbnail', 'src'],
          'video' => ['video', 'embed', 'oembed'],
          'button' => ['button', 'cta', 'action'],
          'link' => ['link', 'url', 'href', 'menu', 'nav', 'breadcrumb'],
          'badge' => ['badge', 'tag', 'label', 'pill'],
          'list' => ['list', 'items', 'rows', 'results'],
          'icon' => ['icon', 'glyph', 'symbol'],
          'quote' => ['quote', 'testimonial', 'citation'],
          'stat' => ['stat', 'counter', 'number', 'metric'],
          'form' => ['form', 'webform', 'contact', 'subscribe'],
          'logo' => ['logo', 'brand'],
          'author' => ['author', 'byline', 'name'],
          'date' => ['date', 'time', 'published'],
          'price' => ['price', 'cost', 'amount'],
        ],
        'plain_text_roles' => ['button', 'badge', 'quote', 'stat', 'price', 'link'],
        'text_props' => ['label', 'text', 'title', 'value', 'caption'],
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
        'exclude' => [
          'layout_components' => ['spacer', 'section', 'divider', 'container', 'column', 'html-code'],
        ],
      ],
    ];
    $configFactory = $this->getConfigFactoryStub(['varbase_ai_figma.settings' => $settings]);
    $vocabulary = new RoleVocabulary($configFactory);

    $inventory = $this->createMock(InventoryScanner::class);
    $inventory->method('scan')->willReturn($this->realInventory($vocabulary));

    return new DesignResolver($inventory, $vocabulary, $configFactory);
  }

  /**
   * The real inventory, exported from a fresh Varbase 11.0.0-beta1 install.
   *
   * @param \Drupal\varbase_ai_figma\RoleVocabulary $vocabulary
   *   Used to work out what each thing can hold, exactly as the scanner does -
   *   so the fixture can never drift from the logic it is testing.
   *
   * @return array
   *   The candidates.
   */
  private function realInventory(RoleVocabulary $vocabulary): array {
    $raw = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/varbase-11-inventory.json'), TRUE);
    $out = [];
    foreach ($raw as $key => $c) {
      if ($c['kind'] === 'component') {
        $c['roles'] = $vocabulary->rolesOf(array_keys($c['props']), $c['slots'], $c['id']);
      }
      else {
        // A saved section holds whatever the parts inside it hold.
        $roles = [];
        foreach (array_keys((array) ($c['structure']['components'] ?? [])) as $childId) {
          $child = $raw['component:' . $childId] ?? NULL;
          if ($child) {
            $roles = array_merge($roles, $vocabulary->rolesOf(array_keys($child['props']), $child['slots'], $child['id']));
          }
        }
        $c['roles'] = array_values(array_unique($roles));
      }
      $out[$key] = $c;
    }
    return $out;
  }

}
