<?php

declare(strict_types=1);

namespace Drupal\Tests\varbase_ai_figma\Unit;

use Drupal\varbase_ai_figma\CanvasPageEditor;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the pure array transforms of CanvasPageEditor.
 *
 * Every method under test takes plain $rows arrays and returns a transformed
 * copy, so no entity or storage is needed - the EntityTypeManager is a bare
 * mock and the UUID service is a stub returning a fixed string.
 *
 * @coversDefaultClass \Drupal\varbase_ai_figma\CanvasPageEditor
 *
 * @group ai_figma
 */
class CanvasPageEditorTest extends UnitTestCase {

  /**
   * The editor under test.
   */
  protected CanvasPageEditor $editor;

  /**
   * The fixed uuid the stub generator returns.
   */
  protected const STUB_UUID = 'NEW-UUID-1234';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $uuid = $this->createMock(UuidInterface::class);
    $uuid->method('generate')->willReturn(self::STUB_UUID);
    $this->editor = new CanvasPageEditor($etm, $uuid);
  }

  /**
   * Builds three component rows in document order.
   *
   * @return array[]
   *   Rows: a=js.hero "Hero", b=sdc.theme.card "Card" (array inputs),
   *   c=js.cta "CTA" (JSON-string inputs).
   */
  protected function sampleRows(): array {
    return [
      ['uuid' => 'a', 'component_id' => 'js.hero', 'label' => 'Hero', 'inputs' => []],
      ['uuid' => 'b', 'component_id' => 'sdc.theme.card', 'label' => 'Card', 'inputs' => ['heading' => 'Hi']],
      ['uuid' => 'c', 'component_id' => 'js.cta', 'label' => 'CTA', 'inputs' => '{"text":"Go"}'],
    ];
  }

  /**
   * @covers ::indexOf
   */
  public function testIndexOfByUuid(): void {
    $this->assertSame(1, $this->editor->indexOf($this->sampleRows(), 'b'));
  }

  /**
   * @covers ::indexOf
   */
  public function testIndexOfByLabel(): void {
    $this->assertSame(2, $this->editor->indexOf($this->sampleRows(), 'CTA'));
  }

  /**
   * @covers ::indexOf
   */
  public function testIndexOfByComponentNameUnique(): void {
    // Full id and bare name both resolve when unambiguous.
    $this->assertSame(0, $this->editor->indexOf($this->sampleRows(), 'js.hero'));
    $this->assertSame(0, $this->editor->indexOf($this->sampleRows(), 'hero'));
  }

  /**
   * @covers ::indexOf
   */
  public function testIndexOfAmbiguousNameReturnsNull(): void {
    // Two components share the bare name "card" -> ambiguous -> NULL.
    $rows = [
      ['uuid' => 'a', 'component_id' => 'sdc.theme.card', 'label' => 'One', 'inputs' => []],
      ['uuid' => 'b', 'component_id' => 'js.card', 'label' => 'Two', 'inputs' => []],
    ];
    $this->assertNull($this->editor->indexOf($rows, 'card'));
  }

  /**
   * @covers ::indexOf
   */
  public function testIndexOfNoMatchReturnsNull(): void {
    $this->assertNull($this->editor->indexOf($this->sampleRows(), 'does-not-exist'));
  }

  /**
   * @covers ::move
   */
  public function testMoveToEnd(): void {
    $rows = $this->editor->move($this->sampleRows(), 0, 'end');
    $this->assertSame(['b', 'c', 'a'], array_column($rows, 'uuid'));
  }

  /**
   * @covers ::move
   */
  public function testMoveToStart(): void {
    $rows = $this->editor->move($this->sampleRows(), 2, 'start');
    $this->assertSame(['c', 'a', 'b'], array_column($rows, 'uuid'));
  }

  /**
   * @covers ::move
   */
  public function testMoveBefore(): void {
    // Move 'a' (0) before 'c' (anchor 2). After removing 'a' the anchor shifts
    // left, so 'a' lands just before 'c': b, a, c.
    $rows = $this->editor->move($this->sampleRows(), 0, 'before', 2);
    $this->assertSame(['b', 'a', 'c'], array_column($rows, 'uuid'));
  }

  /**
   * @covers ::move
   */
  public function testMoveAfter(): void {
    // Move 'a' (0) after 'b' (anchor 1): b, a, c.
    $rows = $this->editor->move($this->sampleRows(), 0, 'after', 1);
    $this->assertSame(['b', 'a', 'c'], array_column($rows, 'uuid'));
  }

  /**
   * @covers ::remove
   */
  public function testRemoveDropsRowAndChildren(): void {
    $rows = $this->sampleRows();
    // A child nested under 'b' via parent_uuid.
    $rows[] = ['uuid' => 'd', 'component_id' => 'sdc.x', 'label' => 'Child', 'parent_uuid' => 'b', 'inputs' => []];

    $result = $this->editor->remove($rows, 1);

    // Both 'b' (removed) and its child 'd' are gone; rows are re-indexed.
    $this->assertSame(['a', 'c'], array_column($result, 'uuid'));
    $this->assertArrayHasKey(0, $result);
    $this->assertArrayHasKey(1, $result);
  }

  /**
   * @covers ::setProp
   */
  public function testSetPropOnArrayInputs(): void {
    $rows = $this->editor->setProp($this->sampleRows(), 1, 'subhead', 'Yo');
    // Array inputs stay an array, existing prop preserved, new prop added.
    $this->assertSame(['heading' => 'Hi', 'subhead' => 'Yo'], $rows[1]['inputs']);
  }

  /**
   * @covers ::setProp
   */
  public function testSetPropOnJsonStringInputsKeepsEncoding(): void {
    $rows = $this->editor->setProp($this->sampleRows(), 2, 'url', '/go');
    // JSON-string inputs are written back as a JSON string (encoding
    // preserved).
    $this->assertIsString($rows[2]['inputs']);
    $this->assertSame(['text' => 'Go', 'url' => '/go'], json_decode($rows[2]['inputs'], TRUE));
  }

  /**
   * @covers ::insert
   */
  public function testInsertAtEnd(): void {
    $rows = $this->editor->insert($this->sampleRows(), 'sdc.theme.banner', 'v1', ['title' => 'New'], 'end');

    $this->assertCount(4, $rows);
    $last = end($rows);
    $this->assertSame(self::STUB_UUID, $last['uuid']);
    $this->assertSame('sdc.theme.banner', $last['component_id']);
    $this->assertSame('v1', $last['component_version']);
    $this->assertSame(['title' => 'New'], $last['inputs']);
  }

  /**
   * @covers ::insert
   */
  public function testInsertBeforeAnchor(): void {
    $rows = $this->editor->insert($this->sampleRows(), 'sdc.theme.banner', 'v1', [], 'before', 0);

    // The new row lands at the front with the stub uuid.
    $this->assertSame(self::STUB_UUID, $rows[0]['uuid']);
    $this->assertSame(['NEW-UUID-1234', 'a', 'b', 'c'], array_column($rows, 'uuid'));
  }

}
