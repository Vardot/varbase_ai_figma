<?php

declare(strict_types=1);

namespace Drupal\Tests\varbase_ai_figma\Unit;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\varbase_ai_figma\InventoryScanner;
use Drupal\varbase_ai_figma\RoleVocabulary;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * How the scanner reads a saved section.
 *
 * @group varbase_ai_figma
 */
#[RunTestsInSeparateProcesses]
class InventoryScannerTest extends UnitTestCase {

  /**
   * A saved section repeats its CONTENT, not the spacers between it.
   *
   * Patterns are padded with spacers, wrappers and dividers. Counting those as
   * the repeat made a single call-to-action banner - one heading, one button,
   * four spacers - look like a four-item grid, and that wrong shape then drove
   * the score for every region compared against it.
   */
  public function testRepeatCountsContentNotSpacers(): void {
    // A call-to-action banner: one of everything that matters, padded out with
    // four spacers. It repeats ONCE, whatever the spacers suggest.
    $scanner = $this->scannerWithPattern('cta_banner', 'Call To Action', [
      ['uuid' => 'a', 'component_id' => 'sdc.theme.section', 'inputs' => []],
      ['uuid' => 'b', 'parent_uuid' => 'a', 'component_id' => 'sdc.theme.spacer', 'inputs' => []],
      ['uuid' => 'c', 'parent_uuid' => 'a', 'component_id' => 'sdc.theme.spacer', 'inputs' => []],
      ['uuid' => 'd', 'parent_uuid' => 'a', 'component_id' => 'sdc.theme.spacer', 'inputs' => []],
      ['uuid' => 'e', 'parent_uuid' => 'a', 'component_id' => 'sdc.theme.spacer', 'inputs' => []],
      [
        'uuid' => 'f',
        'parent_uuid' => 'a',
        'component_id' => 'sdc.theme.heading',
        'inputs' => ['heading_text' => ''],
      ],
      [
        'uuid' => 'g',
        'parent_uuid' => 'a',
        'component_id' => 'sdc.theme.button',
        'inputs' => ['label' => ''],
      ],
    ]);

    $found = $scanner->scan(['pattern']);
    $this->assertSame(1, $found['pattern:cta_banner']['structure']['repeat'], 'a banner with four spacers still repeats once');
  }

  /**
   * A four-card grid repeats four times, however many spacers pad it.
   */
  public function testRepeatFollowsTheRepeatedCard(): void {
    $rows = [['uuid' => 'a', 'component_id' => 'sdc.theme.section', 'inputs' => []]];
    // Six spacers, four cards: the design repeats FOUR times.
    for ($i = 0; $i < 6; $i++) {
      $rows[] = ['uuid' => 's' . $i, 'parent_uuid' => 'a', 'component_id' => 'sdc.theme.spacer', 'inputs' => []];
    }
    for ($i = 0; $i < 4; $i++) {
      $rows[] = [
        'uuid' => 'c' . $i,
        'parent_uuid' => 'a',
        'component_id' => 'sdc.theme.card-text',
        'inputs' => ['heading' => '', 'text' => ''],
      ];
    }

    $scanner = $this->scannerWithPattern('feature_cards', 'Feature Cards', $rows);
    $found = $scanner->scan(['pattern']);
    $this->assertSame(4, $found['pattern:feature_cards']['structure']['repeat'], 'four cards, not six spacers');
  }

  /**
   * A saved section that embeds a view shows real, changing site content.
   *
   * "Latest Blog Posts" carries a views block. Calling it static made the
   * engine penalise it for not being live and recommend building a new one
   * instead - while the section it was rejecting WAS the answer.
   */
  public function testSectionWithEmbeddedViewIsLive(): void {
    $scanner = $this->scannerWithPattern('latest_blog_posts', 'Latest Blog Posts', [
      ['uuid' => 'a', 'component_id' => 'sdc.theme.section', 'inputs' => []],
      [
        'uuid' => 'b',
        'parent_uuid' => 'a',
        'component_id' => 'sdc.theme.heading',
        'inputs' => ['heading_text' => ''],
      ],
      [
        'uuid' => 'c',
        'parent_uuid' => 'a',
        'component_id' => 'block.views_block.blog-latest',
        'inputs' => [],
      ],
    ]);

    $found = $scanner->scan(['pattern']);
    $this->assertTrue($found['pattern:latest_blog_posts']['dynamic'], 'a section carrying a view is live, not static');
  }

  /**
   * A section built only from theme components is static.
   */
  public function testSectionOfPlainComponentsIsStatic(): void {
    $scanner = $this->scannerWithPattern('cta', 'Call To Action', [
      ['uuid' => 'a', 'component_id' => 'sdc.theme.section', 'inputs' => []],
      [
        'uuid' => 'b',
        'parent_uuid' => 'a',
        'component_id' => 'sdc.theme.heading',
        'inputs' => ['heading_text' => ''],
      ],
    ]);

    $found = $scanner->scan(['pattern']);
    $this->assertFalse($found['pattern:cta']['dynamic']);
  }

  /**
   * A scanner whose pattern storage holds exactly one saved section.
   */
  private function scannerWithPattern(string $id, string $label, array $rows): InventoryScanner {
    $pattern = $this->createMock(ConfigEntityInterface::class);
    $pattern->method('id')->willReturn($id);
    $pattern->method('label')->willReturn($label);
    $pattern->method('get')->willReturn($rows);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn([$pattern]);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturn($storage);

    $configFactory = $this->getConfigFactoryStub([
      'varbase_ai_figma.settings' => [
        'resolver' => [
          'role_hints' => [
            'heading' => ['heading', 'title'],
            'body' => ['body', 'text'],
            'button' => ['button', 'cta'],
          ],
          'exclude' => [
            'layout_components' => ['spacer', 'section', 'divider'],
          ],
        ],
      ],
    ]);

    return new InventoryScanner(
      $etm,
      $this->createMock(PluginManagerInterface::class),
      $this->createMock(BlockManagerInterface::class),
      $configFactory,
      $this->createMock(ThemeHandlerInterface::class),
      new RoleVocabulary($configFactory),
    );
  }

}
