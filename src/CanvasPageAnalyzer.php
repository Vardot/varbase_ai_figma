<?php

declare(strict_types=1);

namespace Drupal\varbase_ai_figma;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;

/**
 * Reads an existing Drupal Canvas page into a structured, analysable model.
 *
 * This is the foundation for the "page intelligence" tools: it loads a
 * canvas_page, normalises its component tree, and extracts the things the
 * audit/improvement tools reason over - headings, text, images, links and the
 * per-component inputs - plus the rendered HTML when a deeper pass is needed.
 * It performs no mutation; CanvasPageEditor applies changes.
 */
class CanvasPageAnalyzer {

  /**
   * Constructs the analyzer.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RendererInterface $renderer,
  ) {}

  /**
   * Loads a canvas_page by numeric id or exact title.
   *
   * @param string $id_or_title
   *   A numeric page id, or a page title.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The page entity, or NULL when not found.
   */
  public function loadPage(string $id_or_title) {
    $storage = $this->entityTypeManager->getStorage('canvas_page');
    if ($id_or_title !== '' && ctype_digit($id_or_title)) {
      $page = $storage->load($id_or_title);
      if ($page) {
        return $page;
      }
    }
    $matches = $storage->loadByProperties(['title' => $id_or_title]);
    return $matches ? reset($matches) : NULL;
  }

  /**
   * Normalises a page's component tree into a flat list of instance rows.
   *
   * Each row: uuid, component_id, family (js|block|sdc), bare (name without the
   * source prefix), label, slot, parent_uuid, inputs (decoded array) and the
   * raw inputs encoding so an editor can write it back faithfully.
   *
   * @param \Drupal\Core\Entity\EntityInterface $page
   *   The canvas_page entity.
   *
   * @return array[]
   *   The normalised component rows, in document order.
   */
  public function tree($page): array {
    $rows = [];
    foreach ((array) $page->get('components')->getValue() as $delta => $row) {
      $component_id = (string) ($row['component_id'] ?? '');
      $raw_inputs = $row['inputs'] ?? [];
      $inputs = $raw_inputs;
      $inputs_is_json = FALSE;
      if (is_string($raw_inputs)) {
        $inputs_is_json = TRUE;
        $decoded = json_decode($raw_inputs, TRUE);
        $inputs = is_array($decoded) ? $decoded : [];
      }
      [$family, $bare] = $this->splitComponentId($component_id);
      $rows[] = [
        'delta' => $delta,
        'uuid' => (string) ($row['uuid'] ?? ''),
        'component_id' => $component_id,
        'component_version' => (string) ($row['component_version'] ?? ''),
        'family' => $family,
        'bare' => $bare,
        'label' => $row['label'] ?? NULL,
        'slot' => $row['slot'] ?? NULL,
        'parent_uuid' => $row['parent_uuid'] ?? NULL,
        'inputs' => $inputs,
        'inputs_is_json' => $inputs_is_json,
      ];
    }
    return $rows;
  }

  /**
   * Splits a component id ("js.hero", "block.views_block.x", "sdc.theme.card").
   *
   * @return array{0:string,1:string}
   *   [family, bare-name]. family is js|block|sdc|other.
   */
  public function splitComponentId(string $component_id): array {
    foreach (['js' => 'js.', 'block' => 'block.', 'sdc' => 'sdc.'] as $family => $prefix) {
      if (str_starts_with($component_id, $prefix)) {
        return [$family, substr($component_id, strlen($prefix))];
      }
    }
    $pos = strrpos($component_id, '.');
    return ['other', $pos === FALSE ? $component_id : substr($component_id, $pos + 1)];
  }

  /**
   * Renders the page to HTML for content-level analysis.
   *
   * @param \Drupal\Core\Entity\EntityInterface $page
   *   The canvas_page entity.
   *
   * @return string
   *   The rendered HTML, or an empty string when rendering is unavailable.
   */
  public function renderHtml($page): string {
    try {
      $view_builder = $this->entityTypeManager->getViewBuilder('canvas_page');
      $build = $view_builder->view($page, 'full');
      return (string) $this->renderer->renderInIsolation($build);
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Extracts a structured content summary from a page.
   *
   * Combines the component tree (props/inputs) with the rendered HTML so the
   * audit tools have both the declared structure and the real output. Keys:
   * components, texts, headings, images, links, colors.
   *
   * @param \Drupal\Core\Entity\EntityInterface $page
   *   The canvas_page entity.
   *
   * @return array
   *   The structured summary.
   */
  public function summary($page): array {
    $tree = $this->tree($page);
    $html = $this->renderHtml($page);

    $texts = [];
    foreach ($tree as $row) {
      foreach ($row['inputs'] as $prop => $value) {
        if (is_string($value) && trim($value) !== '' && $this->looksTextual($prop, $value)) {
          $texts[] = ['uuid' => $row['uuid'], 'prop' => $prop, 'text' => trim($value)];
        }
      }
    }

    return [
      'page_id' => (int) $page->id(),
      'title' => (string) $page->label(),
      'component_count' => count($tree),
      'components' => array_map(static fn(array $r): array => [
        'uuid' => $r['uuid'],
        'component_id' => $r['component_id'],
        'family' => $r['family'],
        'bare' => $r['bare'],
        'label' => $r['label'],
      ], $tree),
      'texts' => $texts,
      'headings' => $this->extractHeadings($html),
      'images' => $this->extractImages($html),
      'links' => $this->extractLinks($html),
      'colors' => $this->extractInlineColors($html),
    ];
  }

  /**
   * Whether a prop/value pair is human-readable copy (not a class/enum/url).
   */
  protected function looksTextual(string $prop, string $value): bool {
    if (preg_match('/url|href|variant|color|colour|class|icon|id$|^id|align|size|ratio|width|height/i', $prop)) {
      return FALSE;
    }
    // Real copy contains a space or is reasonably long; enums/utilities don't.
    return str_contains(trim($value), ' ') || mb_strlen(trim($value)) > 24;
  }

  /**
   * Extracts the heading outline (level + text) from rendered HTML.
   *
   * @return array[]
   *   Rows of ['level' => int, 'text' => string], in document order.
   */
  public function extractHeadings(string $html): array {
    if ($html === '') {
      return [];
    }
    $out = [];
    if (preg_match_all('/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $html, $m, PREG_SET_ORDER)) {
      foreach ($m as $match) {
        $text = trim(html_entity_decode(strip_tags($match[2])));
        if ($text !== '') {
          $out[] = ['level' => (int) $match[1], 'text' => mb_substr($text, 0, 160)];
        }
      }
    }
    return $out;
  }

  /**
   * Extracts images (src + alt + whether alt is missing/empty) from HTML.
   *
   * @return array[]
   *   Rows of ['src' => string, 'alt' => string, 'missing_alt' => bool].
   */
  public function extractImages(string $html): array {
    if ($html === '') {
      return [];
    }
    $out = [];
    if (preg_match_all('/<img\b[^>]*>/is', $html, $tags)) {
      foreach ($tags[0] as $tag) {
        $src = preg_match('/\bsrc\s*=\s*"([^"]*)"/i', $tag, $s) ? $s[1] : '';
        $has_alt = preg_match('/\balt\s*=\s*"([^"]*)"/i', $tag, $a) === 1;
        $alt = $has_alt ? trim($a[1]) : '';
        $out[] = [
          'src' => $src,
          'alt' => $alt,
          // Missing alt attribute entirely, or a non-empty src with empty alt
          // that is not explicitly marked decorative (alt="").
          'missing_alt' => !$has_alt,
        ];
      }
    }
    return $out;
  }

  /**
   * Extracts links (href + visible text) from HTML.
   *
   * @return array[]
   *   Rows of ['href' => string, 'text' => string, 'internal' => bool].
   */
  public function extractLinks(string $html): array {
    if ($html === '') {
      return [];
    }
    $out = [];
    if (preg_match_all('/<a\b[^>]*\bhref\s*=\s*"([^"]*)"[^>]*>(.*?)<\/a>/is', $html, $m, PREG_SET_ORDER)) {
      foreach ($m as $match) {
        $href = trim($match[1]);
        $text = trim(html_entity_decode(strip_tags($match[2])));
        $internal = $href !== '' && (str_starts_with($href, '/') || str_contains($href, $_SERVER['HTTP_HOST'] ?? '\0never'));
        $out[] = ['href' => $href, 'text' => $text, 'internal' => $internal];
      }
    }
    return $out;
  }

  /**
   * Extracts inline foreground/background colours declared in style attributes.
   *
   * Best-effort: only inline `color`/`background(-color)` hex/rgb values are
   * surfaced (computed CSS variables are not resolvable server-side). Useful as
   * a hint for the contrast audit, which also reasons over the theme token map.
   *
   * @return array[]
   *   Rows of ['color' => string, 'background' => string].
   */
  public function extractInlineColors(string $html): array {
    if ($html === '') {
      return [];
    }
    $out = [];
    if (preg_match_all('/style\s*=\s*"([^"]*)"/i', $html, $styles)) {
      foreach ($styles[1] as $style) {
        $fg = preg_match('/(?<![-\w])color\s*:\s*([^;]+)/i', $style, $c) ? trim($c[1]) : '';
        $bg = preg_match('/background(?:-color)?\s*:\s*([^;]+)/i', $style, $b) ? trim($b[1]) : '';
        if ($fg !== '' || $bg !== '') {
          $out[] = ['color' => $fg, 'background' => $bg];
        }
      }
    }
    return $out;
  }

}
