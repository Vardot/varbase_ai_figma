Reference for every `ai` / `ai_agents` function-call plugin this module ships,
grouped the way `varbase_ai_figma_base`'s recipe wires them onto the
`canvas_ai_orchestrator` agent: understand the design, understand the site and
decide, build what the decision chose, connect it to the rest of the site,
then check and improve it. Each entry documents what its `execute()` method
actually does (inputs, permission checks and return shape), not the
LLM-facing marketing copy in its `description`.

All plugins live in `src/Plugin/AiFunctionCall/` and share the `varbase_ai_figma:`
id prefix. Machine permission names are the exact strings checked in code; a
user needs **any one** of the listed permissions, not all of them.

# 1. Understand the design

Before anything is built, the agent reads the Figma file itself.

**Figma: List Design Pages** (`ai_figma:list_design_pages`) is not part of this
module: it ships with the [AI Figma](https://www.drupal.org/project/ai_figma)
dependency and lists a file's top-level frames so the agent can plan one
Canvas page per design frame. See
[AI Figma's own reference](https://project.pages.drupalcode.org/ai_figma/ai-agent-tool/)
for it. This page documents this module's own 29 tools, starting with the one
that reads the design itself.

## Read the design

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:get_design_context` |
| **Function name** | `varbase_figma_get_design_context` |
| **Group** | `information_tools` |
| **Permission** | `use ai figma design context`, or `administer ai agents` / `use Drupal Canvas AI` |

Class: `FigmaGetDesignContext`.

### Inputs

All optional; a pasted `figma_url` overrides `file_key`/`node_id` when both are
given, and an empty `file_key` falls back to the default file key configured
on `ai_figma`.

| Input | Required | Description |
| --- | --- | --- |
| `figma_url` | No | A `figma.com` link, or the whole user message containing an `@`-prefixed one. File key and node id are extracted from it. |
| `file_key` | No | A Figma file key. Ignored if `figma_url` resolves one. |
| `node_id` | No | Node id (`1283:979` or `1283-979`) to scope the read. Leave empty to read the top of the file. |

### Output

A YAML document combining the Figma read with what the active theme can
already build:

```yaml
figma_file_key: RJkuWNHla1P8VYHa5z6dnL
figma_node_id: '1:2'
colors: { ... }
typography: { ... }
node_outline: [ ... ]
content_texts:
  - { text: 'Build faster with Varbase', layer: Heading, size: 52 }
available_components:
  sections: [ ... ]
  cards: [ ... ]
  heroes: [ ... ]
  content: [ ... ]
building_blocks:
  read_context: 'BUILD ON THE EXISTING SITE. ...'
  webforms: { 'webform:contact': Contact }
  views_listings: { ... }
  content_types: { ... }
instruction: '...'
accessibility: '...'
```

`available_components` comes from `FigmaToCanvasBuilder::componentsByFamily()`
(the same live theme registry `component_match` scores against).
`building_blocks` lists visitor-usable Webforms (open, not archived, not a
`template_*` sample), placeable Views listing blocks (admin/a11y views
excluded), and content types, so the agent reuses real Drupal building blocks
instead of hand-written markup. `instruction` and `accessibility` are read
from the "Figma Build Rules" / "Figma Accessibility Rules" AI Context items
when published, else from `ai_figma.settings` config, never hard-coded.

### Use it with the Canvas AI assistant

Add **Read the design** to the assistant's tool list and paste (or `@`-mention)
a Figma link. The assistant reads colours, typography, real copy and the
site's existing components in one call, then builds from what already exists
instead of inventing new markup.

# 2. Understand the site, then decide

Nothing is placed before the site's own inventory is scanned and each design
region is scored against it. This is what makes "reuse before create" a real
decision rather than a hope.

## See what we already have

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:scan_inventory` |
| **Function name** | `varbase_figma_scan_inventory` |
| **Group** | `information_tools` |
| **Permission** | `administer ai agents` or `use Drupal Canvas AI` |

Class: `ScanInventory`, backed by the `varbase_ai_figma.inventory`
(`InventoryScanner`) service.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `kinds` | No | Comma-separated subset of `component, pattern, block, view`. Empty = all four. |
| `roles` | No | Comma-separated content roles (e.g. `heading,image,button`). Only candidates that can hold **all** of them are returned. |
| `limit` | No | Max entries per kind. Defaults to 40. |

### Output

YAML grouped by kind, each entry keyed `id`, `label`, `holds` (its content
roles), plus kind-specific detail: `component` entries add `props` (with enum
values truncated to 8 + `…`) and `slots`; `pattern` entries add a `structure`
summary (`components x N`, `depth`, `repeat`) and `made_of`; `view` entries add
`block_displays` and `lists` (base table); `block` entries add `category`.

```yaml
component (12):
  - id: sdc.vartheme_bs5.card-hero
    label: Card Hero
    holds: 'heading, body, button, image'
    props:
      heading_level: 'string [h2|h3|h4]'
      background_color: 'string [bg-primary|bg-dark|bg-light]'
    slots: media
pattern (3):
  - id: vaf_feature_grid
    label: Feature Grid
    holds: 'heading, body, icon'
    section: '4 components, depth 2, repeats 3'
    made_of: 'card-icon x3, heading x1'
```

Excludes machinery per `varbase_ai_figma.settings:resolver.exclude` (layout
wrappers, theme chrome, admin Views, system blocks); see
[Configuration](configuration.md).

### Use it with the Canvas AI assistant

Always call this before `resolve_design`. The assistant reads the real
props/enums/slots each component accepts, so it never invents a prop name or
writes free text into an enum.

## Decide what to reuse and what to build

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:resolve_design` |
| **Function name** | `varbase_figma_resolve_design` |
| **Group** | `information_tools` |
| **Permission** | `administer ai agents` or `use Drupal Canvas AI` |

Class: `ResolveDesign`, backed by the `varbase_ai_figma.resolver`
(`DesignResolver`) service, tuned entirely by `varbase_ai_figma.settings:resolver`
(role hints, scoring weights, penalties, thresholds; see
[Configuration](configuration.md)).

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `regions` | Yes | A JSON array (a single region object is also tolerated). Each: `{"name","roles":[...],"repeat":1,"dynamic":false,"section":true,"content":{...},"values":{...}}`. Roles: `heading, body, image, video, button, link, badge, list, icon, quote, stat, form, logo, author, date, price`. |
| `kinds` | No | Restrict the palette scored against: comma-separated `component, pattern, block, view`. |

Empty/invalid `regions` returns a usage hint rather than throwing.

### Output

Plain-language prose (not YAML): deliberately no machine names, ids or
scores, per the tool's own instruction to the calling agent. One paragraph per
region ("Hero - use what we already have. Use \"Card Hero\", a component
already on this site."), followed by a one-line tally ("In short: 2 parts we
already have, 1 part that needs adjusting.").

Internally each region resolves to a verdict:

- **REUSE** (score ≥ `thresholds.reuse_at`, default 85): place the named
  thing with the props it hands back.
- **ADAPT** (≥ `thresholds.adapt_at`, default 60): use the closest thing,
  filling only the named gaps.
- **CREATE** (below both): build only what is listed as missing; the closest
  existing thing is still named so the new component is scoped to the gap.

Scoring sums `role_coverage` (45), `structural_fit` (20), `kind_fit` (15),
`value_fit` (10) and `name_affinity` (10), minus penalties for
`missing_required` (15), `dynamic_mismatch` (20) and `subject_mismatch` (35).

### Use it with the Canvas AI assistant

Call it after `scan_inventory`, one entry per design region. Repeat its answer
to the user verbatim: it is already written for a site builder to read, not
for a machine to parse further.

## Find the closest component

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:component_match` |
| **Function name** | `varbase_figma_component_match` |
| **Group** | `information_tools` |
| **Permission** | `use ai figma design context`, or `administer ai agents` / `use Drupal Canvas AI` |

Class: `FigmaComponentMatch`. A deterministic (no LLM call) alternative to
`resolve_design`, scoped to a single Figma node against the theme's component
library alone (not patterns/blocks/views).

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `figma_url` | No | A Figma link (or `@`-prefixed mention); overrides `file_key`/`node_id`. |
| `file_key` | No | Figma file key. Falls back to the configured default. |
| `node_id` | No | Node id to scope the match. |

### Output

YAML with a `fingerprint` of the node (guessed `kind`: hero, card, cta,
banner, …; `family` bucket; heading/paragraph/label counts; `has_button` /
`has_image`; `needs`; `dominant_color`), up to 3 `candidates` each with a
0-100 `score`, `why` (the signals that fired) and `differences` (missing
roles), and an overall `recommendation`:

- `reuse`: top score ≥ 80.
- `extend`: top score 50-79; `rationale` names what to add.
- `create_new`: nothing ≥ 50, or the library is empty.

Scoring is transparent and documented in `scoreCandidate()`: name/keyword
overlap with the guessed kind (up to 55), family-bucket affinity (up to 25),
and prop affinity approximated from the component's bare name (up to 20,
since the builder's real prop readers are protected and not available to this
tool).

```yaml
fingerprint:
  kind: hero
  family: heroes
  headings: 1
  paragraphs: 1
  has_button: true
candidates:
  - component: card-hero
    score: 82
    family: heroes
    why: ['name matches the "hero" kind', 'same "heroes" family as the node']
recommendation: reuse
rationale: 'Component "card-hero" is a strong match (82/100) ...'
```

### Use it with the Canvas AI assistant

Use this for a single pasted component/node when a full region-by-region
`resolve_design` call is more than is needed (e.g. "does this Figma button
already exist as a component?".

# 3. Build what the decision chose

Once the decision is made, these tools place, edit and export the actual
Canvas content.

## Build the page

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:create_canvas_page` |
| **Function name** | `varbase_figma_create_canvas_page` |
| **Group** | `modification_tools` |
| **Permission** | `administer ai agents` or `use Drupal Canvas AI` |

Class: `CreateCanvasPage`.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `title` | Yes | The Canvas page title. If a page with this title exists, its components are **replaced**. |
| `components` | Yes | Comma-separated list, placed in order. Mixes: a code component machine name; a theme SDC (`sdc.vartheme_bs5.card-hero`, or the bare leaf `card-hero`); `webform:<id>`; a block/Views block (`views_block.blog-all`, `block.<id>`, `system_*`). |

### Output

Each name is classified (`classify()`) into `webform` / `sdc` / `block` / `js`
in that priority order (an author's own code component always wins over a
same-named system block); unknown names return the error message plus the
list of existing code components. Draft code components are enabled and
exposed to the Canvas component library (`ComponentSourceManager::generateComponents()`)
before placement, the same step the editor's "Add to components" button
performs. Instance inputs are seeded: SDC props from `prop_field_definitions`
defaults, JS props from each prop's first `examples[0]`, and block/Views/Webform
settings from the block's own `default_settings` (with `webform_id` filled in
for a Webform).

On success: `Created Canvas page 12 ("About Varbase") at /page/12 with component(s): hero, stats, cta.`

### Use it with the Canvas AI assistant

The multi-page build primitive. Prefer passing a resolved Views/Webform/system
block reference over a hand-coded component whenever `resolve_design` named
one, so listings and forms stay live instead of frozen into markup.

## Change a page that already exists

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:page_edit` |
| **Function name** | `varbase_figma_page_edit` |
| **Group** | `modification_tools` |
| **Permission** | `administer ai agents` or `use Drupal Canvas AI` |

Class: `CanvasPageEdit`, backed by `varbase_ai_figma.page_analyzer` and
`varbase_ai_figma.page_editor`.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `page` | Yes | canvas_page numeric id or exact title. |
| `action` | Yes | One of `move, add, remove, swap, set_columns, set_prop`. |
| `target` | For all but `add` | Component uuid, exact label, or machine name. |
| `position` | For `move`/`add` | `before \| after \| start \| end` (default `end`). |
| `anchor` | For `before`/`after` | The reference component. |
| `component` | For `add`/`swap` | What to place: same forms `create_canvas_page` accepts. |
| `columns` | For `set_columns` | A Canvas columns value, e.g. `50-50`, `33-33-33`, `100`. |
| `prop` / `value` | For `set_prop` | The instance prop to set and its new value. |

### Output

Each action reports what changed and then the full resulting component order
(`1. Hero [uuid]; 2. Stats [uuid]; ...`) so the caller can confirm without a
second read. When `target`/`anchor` is empty, not found, or ambiguous, the
message lists every component on the page (`label (component_id=..., uuid=...)`)
instead of failing silently, the disambiguation path the PRD calls for.
`add`/`swap` reuse the same component classification and default-inputs logic
as `create_canvas_page`; `swap` keeps only the inputs the new component
declares (falling back to keeping everything when that can't be discovered).

### Use it with the Canvas AI assistant

Use for a single, surgical change after a page exists (move a section,
swap a block, widen a column) instead of calling `create_canvas_page` again
and losing everything else on the page.

## Fix the details of one thing on a page

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:update_component_inputs` |
| **Function name** | `varbase_figma_update_component_inputs` |
| **Group** | `modification_tools` |
| **Permission** | `administer ai agents` or `use Drupal Canvas AI` |

Class: `UpdateComponentInputs`, backed by `varbase_ai_figma.page_editor`.
Adapted from `ai_agents_experimental_collection`'s
`ai_agents_canvas:update_component_inputs`.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `page_id` | Yes | The numeric canvas_page id. |
| `component_uuid` | Yes | The instance uuid (from the page's component tree). |
| `inputs` | Yes | A JSON **object** of `prop => value` to merge in: only the props sent are changed. |

### Output

`Updated 2 prop(s) on component <uuid> in page "About Varbase" (id 12): heading_text, cta_label.`
Each prop is merged one at a time via `setProp()`, which preserves whether the
row stores `inputs` as an array or a JSON string.

### Use it with the Canvas AI assistant

The fine-grained half of a build-then-verify loop: after previewing a page,
fix one heading or swap one label without touching the rest of the tree.

## Put it in the header or footer

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:place_in_region` |
| **Function name** | `varbase_figma_place_in_region` |
| **Group** | `modification_tools` |
| **Permission** | `administer ai agents` or `use Drupal Canvas AI` |

Class: `PlaceInRegion`.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `components` | Yes | Comma-separated, placed in order: system blocks (`system_branding_block`, `system_menu_block.main`) preferred; a custom code component name as fallback. |
| `region` | Yes | `header` or `footer`. |
| `mode` | No | `replace` (default) or `append`. |

### Output

Resolves `js.<name>` then `block.<name>` (tolerating `:`/`.` derivative
spellings). Creates the theme's `PageRegion` config entity from the block
layout if it doesn't exist yet, enables it, and writes the resolved
components into its `component_tree` (fresh uuids, block default settings
minus `id`/`provider`). `Done: placed system_branding_block, system_menu_block.main into the global header region of vartheme_bs5 (replaced).`
Unmatched names are listed separately, not silently dropped.

### Use it with the Canvas AI assistant

Use once per region to build the site-wide header/footer the Drupal way, so
individual Canvas pages stay content-only.

## Save a section so it can be reused

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:create_pattern` |
| **Function name** | `varbase_figma_create_pattern` |
| **Group** | `modification_tools` |
| **Permission** | `administer ai agents` or `use Drupal Canvas AI` |

Class: `CreatePattern`, backed by `varbase_ai_figma.pattern_builder`
(`PatternBuilder`). Builds a Canvas Pattern **directly from a Figma design**
(contrast with `save_as_pattern`, which saves work already on a page).

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `label` | Yes | Human name; a machine id is generated from it. |
| `figma_url` / `file_key` / `node_id` | No | Identify the design region; a link overrides the explicit fields. |
| `layout` | No | Section shape hint: `auto \| hero \| cards \| feature \| cta \| media \| pricing \| testimonials \| logos \| faq`. Defaults to `auto` (inferred). |

### Output

The builder reuses the closest existing theme component per region and only
scaffolds a new one where nothing fits, then the tool persists a
`canvas.pattern` config entity from the resulting rows:
`Created Canvas pattern "Feature Grid" (id: feature_grid) with 5 component instance(s), layout "feature". Reused components: card-icon, heading. New components created: (none). It now appears in the Canvas editor Patterns tab.`
A build or validation failure returns the error message instead of throwing.

### Use it with the Canvas AI assistant

Use when the user wants a reusable **section**, not a whole page and not a
single component ("save this pricing row as a pattern I can drop on other
pages".

## Save a section already on a page for reuse

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:save_as_pattern` |
| **Function name** | `varbase_figma_save_as_pattern` |
| **Group** | `modification_tools` |
| **Permission** | `administer ai agents` or `use Drupal Canvas AI` |

Class: `SaveAsPattern`.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `page_id` | Yes | The canvas_page whose components to save. |
| `label` | Yes | Human name for the new pattern. |
| `section_uuid` | No | Save only this component and its descendants; omit to save the whole page. |

### Output

Rows are re-keyed with fresh uuids (so the same page can be saved again
without id collisions), the section root is detached from its
`parent_uuid`/`slot`, and inputs are normalized to arrays. The generated
pattern id is `vaf_<slugified label>`, deduplicated with a random suffix on
collision. `Saved "Homepage CTA" as pattern id "vaf_homepage_cta" (3 components). Add it to any page later with "Add a saved section to a page" (insert_pattern).`

### Use it with the Canvas AI assistant

Use after building something good on one page, to make it reusable elsewhere
the counterpart to `create_pattern` for work that already exists.

## Add a saved section to a page

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:insert_pattern` |
| **Function name** | `varbase_figma_insert_pattern` |
| **Group** | `modification_tools` |
| **Permission** | `administer ai agents` or `use Drupal Canvas AI` |

Class: `InsertPattern`.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `pattern_id` | Yes | The `canvas.pattern` id (from `scan_inventory kinds=pattern`). |
| `page_id` | Yes | The target canvas_page id. |

### Output

Every row in the pattern's `component_tree` gets a fresh uuid (parent
references remapped), so the same pattern can be placed on many pages, or
the same page twice, without collisions. Rows are appended to the page's
existing components and saved once. Malformed rows (no resolvable uuid) are
skipped rather than duplicating stale references.
`Inserted pattern "Feature Grid" (5 components) onto page "Homepage" (id 1).`

### Use it with the Canvas AI assistant

This is the "place it" half of a reuse decision: `scan_inventory` lists the
saved sections, `resolve_design` says which to reuse, `insert_pattern` places
it.

## Bring the images across

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:export_figma_images` |
| **Function name** | `varbase_figma_export_images` |
| **Group** | `modification_tools` |
| **Permission** | `use ai figma design context`, or `administer ai agents` / `use Drupal Canvas AI` |

Class: `ExportFigmaImages`. Renders whole nodes (illustrations, composed
frames) via the Figma **Images** API (for the design's real, uploaded image
**fills**, use `fetch_media_assets` instead.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `figma_url` | No | A Figma link; file key extracted from it. Falls back to the configured default. |
| `node_ids` | Yes | Comma-separated node ids to render, e.g. `9652:1031,9652:1067`. |
| `format` | No | `png` (default), `jpg` or `svg`. |

### Output

Each node is rendered, downloaded into `public://figma/figma-<node>.<format>`,
saved as a managed `file` entity, and, when `format` is not `svg` and the
Image media type exists, wrapped in an `Image` media entity. Per-node
failures (Figma couldn't render it, the download failed) are reported inline
rather than aborting the batch:

```yaml
exported:
  '9652:1031': { url: 'https://.../figma/figma-9652-1031.png', file_id: 42, media_id: 17 }
  '9652:1067': { error: 'Download failed: ...' }
hint: 'Reference each url in component markup (src="...") instead of stock photos.'
```

### Use it with the Canvas AI assistant

Call before writing component markup that needs the design's own imagery, so
the agent references the returned URL instead of a stock-photo placeholder.

## Collect the design files (logos, icons, photos)

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:fetch_media_assets` |
| **Function name** | `varbase_figma_fetch_media_assets` |
| **Group** | `modification_tools` |
| **Permission** | `use ai figma design context`, or `administer ai agents` / `use Drupal Canvas AI` |

Class: `FetchMediaAssets`. Refuses immediately if the Media module is not
enabled.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `figma_url` | No | A Figma link; file key (and node id) extracted from it. |
| `file_key` | No | Figma file key, if no link is given. |
| `node_id` | No | Scope to one section (e.g. `9740-5207`); omit to scan the whole file. |

### Output

Walks the design (or node), collects its real image **fills** (logos, photos,
illustrations, via `collectImageRefs()` + `fetchImageFills()`), and for each:
skips it if a file at the stable, ref-keyed filename already exists (creating
the missing media entity if needed); otherwise checks a **content-hash index**
of every image already under `public://figma/` so the exact same bytes are
never stored twice under a different Figma ref; otherwise downloads and
creates both the file and (when the Image media type exists) its media
entity.

```yaml
summary: { total: 4, created: 1, already_existed: 3 }
fetched:
  - { name: Logo, ref: 'abc123...', file: figma-asset-abc123.png, status: existing, media_id: 9, url: '...' }
```

### Use it with the Canvas AI assistant

Call before building so components reference the design's own logos/photos
from the first draft, rather than being patched with real imagery afterward.

## Edit the theme files

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:theme_file` |
| **Function name** | `varbase_figma_theme_file` |
| **Group** | `modification_tools` |
| **Permission** | `administer themes`, or `administer ai agents` / `use Drupal Canvas AI` |

Class: `ThemeFileEditor`. Scoped strictly to the site's active **default**
theme directory (read from `system.theme:default`, not hard-coded), with the
target path resolved and jailed via `realpath()` against that base.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `action` | Yes | `list`, `read` or `write`. |
| `path` | No | Relative to the theme directory, e.g. `css/style.css`; a sub-path for `list`. |
| `content` | For `write` | The full replacement file content. |

### Output

`list` returns every file path under the theme (or sub-path) recursively.
`read`/`list` accept `css, js, yml, twig, md, svg`; **`write` refuses `.twig`
unconditionally** (template-injection risk) and is further restricted to
`css, js, yml, md, svg`. A successful write calls
`drupal_flush_all_caches()` and reports the byte count written. Any path that
resolves outside the theme directory is refused with `"Path ... is outside the <theme> theme. Refused."`.

### Use it with the Canvas AI assistant

Use for a Bootstrap variable override or a small CSS/JS tweak a Figma build
needs, not for template changes, which this tool cannot make.

## Set the site logo

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:set_theme_logo` |
| **Function name** | `varbase_figma_set_theme_logo` |
| **Group** | `modification_tools` |
| **Permission** | `administer themes` or `administer ai agents` (note: `use Drupal Canvas AI` alone is **not** sufficient here) |

Class: `SetThemeLogo`.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `figma_url` | No | A link to the logo node; file key + node id extracted from it. |
| `file_key` / `node_id` | Conditionally | Required (one of link or these): a logo node id must be resolvable. |
| `format` | No | `svg` (default, recommended for logos) or `png`. |
| `apply_to_theme` | No | Theme machine name; defaults to the site default theme. |

### Output

Logos in Figma are usually vector groups with no downloadable image fill, so
this **renders** the node via the Images API (not `fetchImageFills()`),
downloads it to `public://figma/logo-<node>.<format>`, saves it as a managed
file, and sets `<theme>.settings:logo.use_default = false` /
`logo.path = <uri>`.

```yaml
logo_set: true
theme: vartheme_bs5
node_id: '7989-1361'
format: svg
file: logo-7989-1361.svg
url: 'https://.../figma/logo-7989-1361.svg'
note: 'The default theme now uses this logo. Reload the site to see it; clear caches if it does not appear.'
```

### Use it with the Canvas AI assistant

Use for "change the logo to this Figma logo": the author no longer exports
and uploads it by hand.

## Suggest what to ask for next

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:prompt_template` |
| **Function name** | `varbase_figma_prompt_template` |
| **Group** | `modification_tools` |
| **Permission** | `administer ai agents` or `use Drupal Canvas AI` |

Class: `PromptTemplate`. Stores templates in a single config object,
`varbase_ai_figma.prompt_templates`.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `action` | Yes | `save`, `list`, `apply` or `delete`. |
| `name` | For save/apply/delete | The template name (a machine name is derived by lower-casing and collapsing non-alphanumerics to `_`). |
| `instructions` | For `save` | The reusable improvement-prompt text to store. |
| `description` | For `save`, optional | A short human label; defaults to `name`. |

### Output

`list` returns every saved template with a single-line preview (truncated at
117 chars + `...`). `apply` returns the stored instructions verbatim, framed
as `"Apply these improvement instructions to the current page: ..."`, for the
calling agent to execute; this tool never touches a page itself. `save`
overwrites any existing template of the same machine name. A missing template
name returns a message listing the templates that **do** exist.

### Use it with the Canvas AI assistant

Save a quality checklist once ("fix headings, generate missing alt text,
tighten CTAs, add a meta description"), then `apply` it against any page
instead of retyping the same instructions.

## Suggest alternative wording to test

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:ab_variants` |
| **Function name** | `varbase_figma_ab_variants` |
| **Group** | `information_tools` |
| **Permission** | `use Drupal Canvas AI`, `administer ai agents`, or `use ai figma design context` |

Class: `AbVariants`. Read-only: never edits the page, and does not push
variants to an experimentation module (out of scope, noted in the output).

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `page` | Yes | canvas_page numeric id or exact title. |
| `element` | No | Focus on one of `hero \| cta \| headline`; omit to auto-pick all high-impact elements. |

### Output

Identifies up to three candidates from the page summary: the **hero
headline** (the first heading, preferring a larger one among the first three),
the **primary CTA** (a short, non-sentence text on a component whose name
hints `cta`/`button`/`hero`/`call`/`action`), and **above-the-fold copy** (the
first paragraph ≥ 60 chars), the last only when no single `element` was
requested. Requires a configured default AI chat provider (`ai.settings`); asks
it for 1-2 variants per element, each with a hypothesis and a metric from
`CTR | scroll depth | form submissions`.

```yaml
page: '1 (Homepage)'
high_impact_elements:
  - { element: 'hero headline', original: 'Build faster with Varbase' }
variants:
  - { element: 'hero headline', original: '...', variant: 'Ship Drupal faster.', hypothesis: '...', metric: CTR }
note: 'Read-only suggestions. To actually run the test, the agent should push these variants to a connected A/B / experimentation module - that wiring is out of scope here and was not done.'
```

### Use it with the Canvas AI assistant

Use to get testable copy alternatives for a page's highest-impact elements
before wiring them into whatever A/B tool the site actually runs.

## Check many pages at once

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:batch_improve` |
| **Function name** | `varbase_figma_batch_improve` |
| **Group** | `information_tools` |
| **Permission** | `use Drupal Canvas AI` or `administer ai agents` |

Class: `BatchImprove`. Read-only and fully deterministic: no AI/LLM call.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `pages` | No | Comma-separated canvas_page ids/exact titles. Empty = **all** canvas_page entities, capped at 50 (noted in the output). |
| `checks` | No | Comma-separated subset of `accessibility, seo, links`. Defaults to all three. |

### Output

One row per page: `images_missing_alt`, `heading_issues` (missing/multiple
H1, skipped levels), `generic_links` (anchor text like "click here"/"read
more"/empty), and a `needs_attention` flag, sorted with flagged pages first,
then by descending issue count. Site-wide `totals` are aggregated across all
audited pages.

```yaml
pages_audited: 12
checks_run: [accessibility, seo, links]
totals: { images_missing_alt: 3, heading_issues: 1, generic_links: 2, pages_needing_attention: 2 }
pages:
  - { page_id: 4, title: About, images_missing_alt: 2, heading_issues: 1, generic_links: 0, needs_attention: true }
note: 'Read-only batch audit (deterministic). Run ai_figma:audit_page on a specific page for full per-finding detail, then apply low-risk fixes (alt text, meta, spacing) with the targeted tools after review.'
```

### Use it with the Canvas AI assistant

Use for a site-wide sweep, then drill into any flagged page with `audit_page`
for the full per-finding detail.

# 4. Connect it to the rest of the site

Once content exists, these tools wire it into navigation, the front page and
site configuration.

## Connect the design to the real site

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:site_wiring` |
| **Function name** | `varbase_figma_site_wiring` |
| **Group** | `modification_tools` |
| **Permission** | `administer site configuration`, or `administer ai agents` / `use Drupal Canvas AI` |

Class: `FigmaSiteWiring`.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `action` | Yes | `set_front_page`, `add_to_menu` or `set_path_alias`. |
| `page` | Yes | A canvas_page numeric id, exact title, or any internal path starting with `/`. |
| `alias` | For `set_path_alias`, optional | The wanted alias; auto-generated from the title (slugified) when empty. |
| `menu` | For `add_to_menu`, optional | `main`, `secondary` or `footer`. Defaults to `main`. |
| `link_title` | For `add_to_menu`, optional | Defaults to the page title. |
| `weight` | For `add_to_menu`, optional | Integer menu weight. |

### Output

`set_front_page` writes `system.site:page.front`. `add_to_menu` creates a
`menu_link_content` pointing at `internal:/page/<id>` (or the given path),
refusing a duplicate. `set_path_alias` replaces any existing alias for that
path with a `path_alias` entity. Every action returns a plain confirmation
sentence, e.g. `Done: "Homepage" is now the Drupal front page (system.site:page.front = /page/1).`

### Use it with the Canvas AI assistant

Use right after `create_canvas_page` to finish the build the Drupal way: make
the new Homepage the front page, give every created page a clean alias, and
add it to navigation when the design shows it there.

## Manage the menus

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:menu_link` |
| **Function name** | `varbase_figma_menu_link` |
| **Group** | `modification_tools` |
| **Permission** | `administer menu`, or `administer ai agents` / `use Drupal Canvas AI` |

Class: `ManageMenu`. Overlaps `site_wiring`'s `add_to_menu` action but adds
`list` and `remove`.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `action` | Yes | `list`, `add` or `remove`. |
| `menu` | No | `main`, `secondary` or `footer`. Defaults to `main`. |
| `page` | For add/remove | canvas_page id/title, or an internal path. |
| `link_title` | Optional (add); alternative match key (remove) | The link label, or (for `remove` without `page`) the exact title to match. |
| `weight` | For `add`, optional | Integer order weight. |

### Output

`list` returns every link in the menu as `"Title" → /path (weight N)`.
`remove` matches by resolved path first, falling back to an exact
`link_title` match, and deletes every match (reporting how many). `add`
refuses a duplicate (same menu + same `internal:` uri).

### Use it with the Canvas AI assistant

Use when a design adds or drops a page from the main/secondary/footer
navigation, independent of building the page itself.

## Install a set of features (recipe)

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:apply_recipe` |
| **Function name** | `varbase_figma_apply_recipe` |
| **Group** | `modification_tools` |
| **Permission** | `administer site configuration` or `administer ai agents` (deliberately **not** `use Drupal Canvas AI`: recipes can install modules/themes) |

Class: `ApplyTempRecipe`. Modelled on
`\Drupal\varbase_recipes\Recipe\RecipeHelper`.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `recipe_yaml` | Yes | Complete `recipe.yml` content: `name` (required), optional `type`, `install`, `config.actions`. |

### Output

Validates the YAML and requires at least a `name`; defaults `type` to `Site`.
Materialises the recipe via `RecipeHelper::createRecipe()` when
`varbase_recipes` is available, else writes it to a temp directory and loads
it with `\Drupal\Core\Recipe\Recipe::createFromDirectory()`, then runs it
through core's `RecipeRunner::processRecipe()`, the same machinery
`drush recipe` uses. Caches are flushed on success. Changes are
**immediate and permanent**.

### Use it with the Canvas AI assistant

Use for a site configuration change a Figma build needs (enabling a module,
a config action) that doesn't fit `config`'s single-object scalpel. Keep
recipes small and targeted.

## Change a site setting

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:config` |
| **Function name** | `varbase_figma_config` |
| **Group** | `modification_tools` |
| **Permission** | `administer site configuration`, or `administer ai agents` / `use Drupal Canvas AI` |

Class: `ManageConfig`.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `action` | Yes | `list`, `get` or `set`. |
| `name` | For `get`/`set`; optional prefix filter for `list` | The config object name, e.g. `system.site`. |
| `values` | For `set` | A YAML mapping of key paths to new values, e.g. `page.front: /page/1`. |

### Output

`list` is unrestricted (names only, no values) and returns
`\Drupal::config()->listAll($name)`. `get`/`set` are gated by an allow-list
(`allowedConfig()`): `ai_figma.`, `ai_agents.ai_agent.`, `canvas`,
`system.site`, `node.type.`, `field.`, `core.entity_view_display.`,
`views.view.`, `webform.webform.`, `block.block.`, `menu_link_content.`,
`pathauto.`, `metatag.`; anything else is refused. Even inside that
allow-list, `set` additionally refuses any name matching
`/(\.|^)(settings|key|password|secret|smtp|mail|oauth|consumer)/i` (a hard
regex check, not just the prefix list), so credentials and mail/SMTP
configuration can never be written through this tool. `get` returns the
config's raw data as YAML; `set` reports the keys written.

### Use it with the Canvas AI assistant

Use for a single quick setting; for module installs or multi-object changes,
use `apply_recipe` instead.

# 5. Check and improve

Every build ends with verification and, where the site allows it, an
AI-assisted improvement pass: all reversible, all reviewed before applying.

## Look at the page in a real browser

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:preview_token_url` |
| **Function name** | `varbase_figma_preview_token_url` |
| **Group** | `information_tools` |
| **Permission** | `administer ai agents` or `use Drupal Canvas AI` |

Class: `PreviewTokenUrl`.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `page_id` | Yes | The numeric canvas_page id. |

### Output

Mints a random token (`Crypt::randomBytesBase64(32)`) and stores it in an
expirable key-value collection (`varbase_ai_figma.preview_tokens`) bound to
the page id with a **300-second TTL**, then returns an absolute URL to the
`varbase_ai_figma.token_preview` route carrying that token. The route
consumes the token on first open, so the URL renders the page, including an
**unpublished draft**, with no login required, exactly once. A fresh call is
needed for a second look.

### Use it with the Canvas AI assistant

Use right after building a page so an unauthenticated headless browser tool
can actually see (and screenshot) the result, rather than trusting the build
succeeded.

## Check the page loads (quick)

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:check_page_render` |
| **Function name** | `varbase_figma_check_page_render` |
| **Group** | `information_tools` |
| **Permission** | `administer ai agents` or `use Drupal Canvas AI` |

Class: `CheckPageRender`. Adapted from
`ai_agents_experimental_collection`'s `ai_agents_sdc:check_component_render`.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `path` | No | A path on this site, e.g. `/page/12`. Empty = front page. |

### Output

A plain unauthenticated HTTP GET against the site's own base URL
(`http_errors` disabled, 15s timeout, redirects followed). `401`/`403` are
reported as an access/publish issue from an anonymous probe, not necessarily a
render bug. `404` reports the path doesn't exist. Any other `4xx`/`5xx`
returns the status plus up to 800 chars of stripped error output. `200` (or
any `<400`) reports `renders OK`.

### Use it with the Canvas AI assistant

Run this first, right after building a page (much cheaper than a headless
browser preview), and only escalate to `preview_token_url` when this comes
back clean but the result still needs to be **seen**.

## Check a page for problems

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:audit_page` |
| **Function name** | `varbase_figma_audit_page` |
| **Group** | `information_tools` |
| **Permission** | `use Drupal Canvas AI`, `administer ai agents`, or `use ai figma design context` |

Class: `AuditPage`. Read-only and fully deterministic (no AI/LLM call):
every check runs over the page's structured summary from
`CanvasPageAnalyzer`.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `page` | Yes | canvas_page numeric id or exact title. |
| `checks` | No | Comma-separated subset of `accessibility, headings, contrast, seo, spacing, images, links, sections`. Defaults to all eight (a consolidated review). |

### Output

Findings grouped by category, each with `severity` (`critical \| warning \|
suggestion`), a WCAG success-criterion tag where relevant, a plain-language
`message` and a `fix`. Notable implementation details:

- **headings**: flags a missing H1, more than one H1, and any level skip
  (e.g. H2→H4), and proposes a corrected level sequence.
- **contrast**: parses only concrete inline `#rgb`/`#rrggbb`/`rgb()` pairs
  (real WCAG relative-luminance math, `4.5:1` minimum); CSS variables,
  keywords, gradients and `hsl()` cannot be resolved server-side and are
  skipped, not guessed at.
- **seo**: a 0-100 score from five pass/fail checks (single H1, title set,
  heading order, has body text, images have alt); always reported even when
  `seo` isn't explicitly requested.
- **spacing**, **images**, **sections**: explicitly labelled **heuristic**:
  spacing scans component inputs for `p*-`/`m*-`/`padding`/`margin` utility
  strings; images flags demo/stock sources and non-derivative
  `/files/...` paths (byte size isn't available from rendered HTML); sections
  guesses a page "kind" (contact/blog/landing/generic) from component names
  and suggests commonly-missing sections (CTA, testimonials, hero, FAQ, …).

```yaml
page: { id: 4, title: About }
checks_run: [accessibility, headings, ...]
score: { seo: 80 }
summary: { critical: 1, warning: 2, suggestion: 4, total: 7 }
findings:
  accessibility:
    - { severity: critical, wcag: 'WCAG 1.1.1', message: 'Image is missing alt text (.../team.jpg).', fix: 'Add a concise, descriptive alt attribute...' }
note: 'Read-only audit. Apply fixes with the targeted edit/improve tools...'
```

### Use it with the Canvas AI assistant

Use `checks=all` (the default) as the consolidated review panel after a
build; apply what it finds with `page_edit`, `alt_text` and
`meta_description` rather than editing by hand.

## Improve the wording

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:improve_text` |
| **Function name** | `varbase_figma_improve_text` |
| **Group** | `modification_tools` |
| **Permission** | `administer ai agents` or `use Drupal Canvas AI` |

Class: `ImproveText`, backed by `varbase_ai_figma.assistant` (`AiAssistant`,
the site's default AI chat provider).

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `page` | Yes | canvas_page numeric id or exact title. |
| `target` | Yes | Component uuid, exact label, machine name, **or** the exact current copy to locate. |
| `prop` | No | The prop holding the text; auto-detected when omitted. |
| `kind` | No | `headline \| body \| cta`. Defaults to `body`. |
| `instruction` | No | Extra guidance, e.g. "for a technical audience". |
| `apply` | No | `"true"` to write back. Defaults to preview only. |
| `choice` | No | 1-based variant index to apply. Defaults to 1. |

### Output

Resolves `target` first as a component reference, then (if that fails) as
exact/near-exact current text via the page's text summary; an ambiguous or
unresolvable target returns the full `uuid`/`prop`/snippet list rather than
failing blindly. Requires a configured AI chat provider. Returns 2-3 variants,
each with a one-line rationale; `kind` tunes the system prompt (headline:
sharper/scannable and no longer than the original; cta: 1-4 words,
action-oriented; body: clearer and more concise, links/formatting preserved).
When `apply=true`, the chosen variant is written to the resolved prop and the
page saved.

```yaml
page: '4 (About)'
component: Hero
prop: heading_text
kind: headline
original: 'We build websites for organisations'
variants:
  - { n: 1, text: 'Websites built for how you work', why: 'Leads with the benefit, active voice.' }
applied: 'No change written (apply was not set). Re-run with apply="true" and choice=<n> to apply one.'
```

### Use it with the Canvas AI assistant

Use for "shorten this headline" / "make this CTA punchier" / "clarify this
paragraph" on a page that already exists.

## Describe the images for screen readers

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:alt_text` |
| **Function name** | `varbase_figma_alt_text` |
| **Group** | `modification_tools` |
| **Permission** | `administer ai agents` or `use Drupal Canvas AI` |

Class: `GenerateAltText`. **Not true vision**: alt is drafted from the image
filename plus page context, so suggestions need review.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `page` | Yes | canvas_page numeric id or exact title. |
| `apply` | No | `"true"` to write the suggestion back. Defaults to preview only. |

### Output

Finds every image missing (or with empty) alt text, builds a page-context
block (title + up to 8 headings + first substantial paragraph), and for each
image: if the filename matches `icon|divider|spacer|separator|bg|background|
decoration|ornament|pattern|texture|bullet|dot`, marks it **decorative** with
`alt=""` and skips the model call entirely; otherwise asks the model for one
concise alt (≤ 125 chars, no "image of" prefix) or the literal answer
`DECORATIVE`. When `apply=true`, write-back is best-effort in this order: (1)
resolve a managed file by mapping the src back to its `public://` URI and set
`alt` on the first content entity's image-field item referencing it; (2)
failing that, find a page component whose serialised inputs mention the image
and carry an `alt`/`image_alt`/`alt_text`/`imageAlt` prop, and set that. If
neither resolves, the image is reported `review-only`.

### Use it with the Canvas AI assistant

Run after a build (or as part of `audit_page`'s accessibility findings) to
clear WCAG 1.1.1 violations; always treat the drafted alt as a starting point
to review, not a final answer.

## Write the search-engine summary

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:meta_description` |
| **Function name** | `varbase_figma_meta_description` |
| **Group** | `modification_tools` |
| **Permission** | `administer ai agents` or `use Drupal Canvas AI` |

Class: `MetaDescription`.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `page` | Yes | canvas_page numeric id or exact title. |
| `apply` | No | `"true"` to write the chosen variant. Defaults to preview only. |
| `choice` | No | 1-based variant index (1, 2 or 3). Defaults to 1. |

### Output

Collects headings + on-page copy (capped at 2000 chars as the model's source
material), reads any existing description first (`findMetaField()` probes, in
order, a `metatag`-typed field, then `field_metatags`/`metatag`/
`field_meta_tags`, then a plain `description`/`meta_description`/
`field_description` property (`canvas_page` doesn't necessarily carry a
Metatag field), then asks the model for 2-3 variants (≤ 155 chars each,
character counts recomputed server-side, not trusted from the model). When
`apply=true` and a field was found, writes the chosen variant into it
(serialising the Metatag array's `description` key, or setting the plain
property directly) and saves; when no writable field exists, the variants are
still returned with a note to set it via the Metatag UI.

### Use it with the Canvas AI assistant

Use per page after a build, or as the fix for `audit_page`'s "confirm the
page has a meta description" reminder.

## Translate the page

| | |
| --- | --- |
| **Plugin id** | `varbase_ai_figma:translate_page` |
| **Function name** | `varbase_figma_translate_page` |
| **Group** | `modification_tools` |
| **Permission** | `administer ai agents` or `use Drupal Canvas AI` |

Class: `TranslatePage`.

### Inputs

| Input | Required | Description |
| --- | --- | --- |
| `page` | Yes | canvas_page numeric id or exact title. |
| `language` | Yes | Target language, e.g. `French`, `fr`, `Arabic`. |
| `localize` | No | `"true"` (default) adapts idioms/dates/currency/culture, not just a literal translation. |
| `apply` | No | `"true"` to write translations back **in place**. Defaults to preview only. |

### Output

Collects every text prop from the page summary and translates in batches of
40 strings per model call (`translateAll()`/`translateBatch()`), each batch
sent as an index-keyed JSON object so the response stays aligned with the
originals even when the model drops or reorders entries. Returns a
field-by-field change set (`component_uuid`, `prop`, `original`,
`translated`, `changed`). When `apply=true`, only the rows that actually
changed are written back (via `component_uuid` lookup + `setProp()`), and the
page is saved once. The report always warns that applying **overwrites the
source language in place**: to keep the original too, translate a
duplicate/language-translation of the page instead.

```yaml
page_id: 4
target_language: French
localized: true
text_prop_count: 12
translated_count: 11
applied: false
fields:
  - { component_uuid: '...', prop: heading_text, original: 'Build faster', translated: 'Construisez plus vite' }
note: 'Preview only - nothing was written. Call again with apply=true to write the translations back. ...'
```

### Use it with the Canvas AI assistant

Duplicate the page first if the original language must survive, then run this
against the copy with `apply=true`.
