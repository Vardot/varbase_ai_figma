<?php

declare(strict_types=1);

namespace Drupal\Tests\varbase_ai_figma\Unit;

use Drupal\varbase_ai_figma\CanvasPageAnalyzer;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the pure HTML extractors of CanvasPageAnalyzer.
 *
 * The extractors and splitComponentId() are regex/string transforms with no
 * dependency on the entity system or renderer, so both collaborators are bare
 * mocks.
 *
 * @coversDefaultClass \Drupal\varbase_ai_figma\CanvasPageAnalyzer
 *
 * @group ai_figma
 */
class CanvasPageAnalyzerHtmlTest extends UnitTestCase {

  /**
   * The analyzer under test.
   */
  protected CanvasPageAnalyzer $analyzer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $renderer = $this->createMock(RendererInterface::class);
    $this->analyzer = new CanvasPageAnalyzer($etm, $renderer);
  }

  /**
   * @covers ::extractHeadings
   */
  public function testExtractHeadings(): void {
    $out = $this->analyzer->extractHeadings('<h1>A</h1><h3>B</h3>');
    $this->assertSame(
      [
        ['level' => 1, 'text' => 'A'],
        ['level' => 3, 'text' => 'B'],
      ],
      $out,
    );
  }

  /**
   * @covers ::extractHeadings
   */
  public function testExtractHeadingsEmptyHtml(): void {
    $this->assertSame([], $this->analyzer->extractHeadings(''));
  }

  /**
   * @covers ::extractImages
   */
  public function testExtractImagesMissingAlt(): void {
    // First img has no alt attribute -> missing_alt TRUE; second has alt.
    $out = $this->analyzer->extractImages('<img src="x"><img src="y" alt="hi">');

    $this->assertCount(2, $out);
    $this->assertTrue($out[0]['missing_alt']);
    $this->assertSame('', $out[0]['alt']);
    $this->assertSame('x', $out[0]['src']);

    $this->assertFalse($out[1]['missing_alt']);
    $this->assertSame('hi', $out[1]['alt']);
    $this->assertSame('y', $out[1]['src']);
  }

  /**
   * @covers ::extractLinks
   */
  public function testExtractLinks(): void {
    $out = $this->analyzer->extractLinks('<a href="/p">go</a>');

    $this->assertCount(1, $out);
    $this->assertSame('/p', $out[0]['href']);
    $this->assertSame('go', $out[0]['text']);
    // A root-relative href is considered internal.
    $this->assertTrue($out[0]['internal']);
  }

  /**
   * @covers ::extractInlineColors
   */
  public function testExtractInlineColors(): void {
    $out = $this->analyzer->extractInlineColors('<div style="color:#fff;background:#000">x</div>');

    $this->assertCount(1, $out);
    $this->assertSame('#fff', $out[0]['color']);
    $this->assertSame('#000', $out[0]['background']);
  }

  /**
   * @covers ::splitComponentId
   *
   * @dataProvider splitProvider
   */
  public function testSplitComponentId(string $component_id, array $expected): void {
    $this->assertSame($expected, $this->analyzer->splitComponentId($component_id));
  }

  /**
   * Data provider for ::testSplitComponentId().
   *
   * @return array<string, array{0:string, 1:array{0:string,1:string}}>
   *   Each case: component id, expected [family, bare].
   */
  public static function splitProvider(): array {
    return [
      'js' => ['js.hero', ['js', 'hero']],
      // The sdc. prefix is stripped; the rest (theme + name) is the bare value.
      'sdc dotted' => ['sdc.t.card', ['sdc', 't.card']],
      'block' => ['block.views_block.foo', ['block', 'views_block.foo']],
      // No known prefix: family is "other", bare is the last dotted segment.
      'other dotted' => ['custom.thing.widget', ['other', 'widget']],
      'bare no dot' => ['bare', ['other', 'bare']],
    ];
  }

}
