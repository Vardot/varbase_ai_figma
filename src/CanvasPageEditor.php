<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Applies targeted edits to an existing Drupal Canvas page's component tree.
 *
 * Unlike a full rebuild, this mutates one component instance at a time -
 * move, remove, swap, insert, or set a single prop/column - by uuid, label or
 * type, then writes the components field back preserving each row's original
 * inputs encoding. This is the foundation for the move/add/remove/swap and
 * column-adjust improvement tools.
 */
class CanvasPageEditor {

  /**
   * Constructs the editor.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected UuidInterface $uuid,
  ) {}

  /**
   * Returns the raw components rows of a page (faithful to storage).
   *
   * @return array[]
   *   The raw component rows.
   */
  public function rows($page): array {
    return (array) $page->get('components')->getValue();
  }

  /**
   * Finds the index of a component row by uuid, exact label, or component name.
   *
   * @param array[] $rows
   *   The raw component rows.
   * @param string $target
   *   A uuid, an exact label, or a bare/full component name.
   *
   * @return int|null
   *   The row index, or NULL when not matched (or ambiguous by name).
   */
  public function indexOf(array $rows, string $target): ?int {
    $target = trim($target);
    // 1. Exact uuid.
    foreach ($rows as $i => $row) {
      if (($row['uuid'] ?? '') === $target) {
        return $i;
      }
    }
    // 2. Exact label.
    foreach ($rows as $i => $row) {
      if (($row['label'] ?? '') !== NULL && (string) $row['label'] === $target) {
        return $i;
      }
    }
    // 3. Component name (full or bare) - only when unambiguous.
    $hits = [];
    foreach ($rows as $i => $row) {
      $cid = (string) ($row['component_id'] ?? '');
      $bare = ($pos = strrpos($cid, '.')) === FALSE ? $cid : substr($cid, $pos + 1);
      if ($cid === $target || $bare === $target) {
        $hits[] = $i;
      }
    }
    return count($hits) === 1 ? $hits[0] : NULL;
  }

  /**
   * Moves a component to a new position relative to another (or absolute).
   *
   * @param array[] $rows
   *   The raw rows (modified copy returned).
   * @param int $from
   *   The index to move.
   * @param string $where
   *   One of 'before', 'after', 'start', 'end'.
   * @param int|null $anchor
   *   The reference index for before/after.
   *
   * @return array[]
   *   The reordered rows.
   */
  public function move(array $rows, int $from, string $where, ?int $anchor = NULL): array {
    $item = $rows[$from];
    array_splice($rows, $from, 1);
    if ($anchor !== NULL && $anchor > $from) {
      $anchor--;
    }
    $pos = match ($where) {
      'start' => 0,
      'end' => count($rows),
      'before' => $anchor ?? 0,
      'after' => ($anchor ?? count($rows) - 1) + 1,
      default => count($rows),
    };
    $pos = max(0, min($pos, count($rows)));
    array_splice($rows, $pos, 0, [$item]);
    return $rows;
  }

  /**
   * Removes a component row by index.
   *
   * @return array[]
   *   The rows without that component (and any of its slot children).
   */
  public function remove(array $rows, int $index): array {
    $uuid = (string) ($rows[$index]['uuid'] ?? '');
    array_splice($rows, $index, 1);
    // Drop orphaned children nested under the removed component.
    if ($uuid !== '') {
      $rows = array_values(array_filter($rows, static fn(array $r): bool => ($r['parent_uuid'] ?? NULL) !== $uuid));
    }
    return $rows;
  }

  /**
   * Swaps a component's type, keeping inputs that the new component declares.
   *
   * @param array[] $rows
   *   The raw rows.
   * @param int $index
   *   The row to swap.
   * @param string $new_component_id
   *   The replacement component id.
   * @param string $new_version
   *   The replacement component active version.
   * @param string[] $declared_props
   *   Prop names the new component declares (kept; others dropped).
   *
   * @return array[]
   *   The rows with the component swapped in place.
   */
  public function swap(array $rows, int $index, string $new_component_id, string $new_version, array $declared_props): array {
    $row = $rows[$index];
    [$inputs, $is_json] = $this->decodeInputs($row);
    if ($declared_props) {
      $inputs = array_intersect_key($inputs, array_flip($declared_props));
    }
    $row['component_id'] = $new_component_id;
    $row['component_version'] = $new_version;
    $row['inputs'] = $is_json ? json_encode($inputs) : $inputs;
    $rows[$index] = $row;
    return $rows;
  }

  /**
   * Sets a single prop on a component instance.
   *
   * @return array[]
   *   The rows with the prop updated.
   */
  public function setProp(array $rows, int $index, string $prop, $value): array {
    $row = $rows[$index];
    [$inputs, $is_json] = $this->decodeInputs($row);
    $inputs[$prop] = $value;
    $row['inputs'] = $is_json ? json_encode($inputs) : $inputs;
    $rows[$index] = $row;
    return $rows;
  }

  /**
   * Inserts a new top-level component row at a position.
   *
   * @param array[] $rows
   *   The raw rows.
   * @param string $component_id
   *   The component id to place.
   * @param string $version
   *   Its active version.
   * @param array $inputs
   *   Instance inputs.
   * @param string $where
   *   One of 'before', 'after', 'start', 'end'.
   * @param int|null $anchor
   *   Reference index for before/after.
   *
   * @return array[]
   *   The rows with the new component inserted.
   */
  public function insert(array $rows, string $component_id, string $version, array $inputs, string $where, ?int $anchor = NULL): array {
    $new = [
      'parent_uuid' => NULL,
      'slot' => NULL,
      'uuid' => $this->uuid->generate(),
      'component_id' => $component_id,
      'component_version' => $version,
      'inputs' => $inputs,
      'label' => NULL,
    ];
    $pos = match ($where) {
      'start' => 0,
      'end' => count($rows),
      'before' => $anchor ?? 0,
      'after' => ($anchor ?? count($rows) - 1) + 1,
      default => count($rows),
    };
    $pos = max(0, min($pos, count($rows)));
    array_splice($rows, $pos, 0, [$new]);
    return $rows;
  }

  /**
   * Persists modified rows back onto the page.
   */
  public function save($page, array $rows): void {
    $page->set('components', array_values($rows));
    $page->save();
  }

  /**
   * Decodes a row's inputs, reporting whether they were JSON-encoded.
   *
   * @return array{0:array,1:bool}
   *   [inputs array, was-json].
   */
  protected function decodeInputs(array $row): array {
    $raw = $row['inputs'] ?? [];
    if (is_string($raw)) {
      $decoded = json_decode($raw, TRUE);
      return [is_array($decoded) ? $decoded : [], TRUE];
    }
    return [is_array($raw) ? $raw : [], FALSE];
  }

}
