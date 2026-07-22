# Configuration

Everything the resolver reasons with lives in `varbase_ai_figma.settings`: a
site changes behaviour by editing config, never by patching code.

## The resolver

`resolve_design` scores every design region against the site's real component
library using this equation, then returns REUSE / ADAPT / CREATE.

### Content roles

`resolver.role_hints` maps a **role** (what a piece of a design content-wise
*is*: `heading`, `body`, `image`, `button`, `badge`, `list`, `icon`, `quote`,
`stat`, `form`, `logo`, `author`, `date`, `price`, and so on) to the real prop/slot
names a component ships. Add a role or extend its hint list to teach the
resolver to recognise it everywhere, immediately.

Some roles carry their own short text rather than naming a prop after the role
(a Button *is* a button, but its text lives in `label`). `resolver.
plain_text_roles` lists which roles work this way, and `resolver.text_props`
lists the prop names checked for that text (`label`, `text`, `title`, `value`,
`caption`).

### The scoring weights

`resolver.weights` (out of 100): `role_coverage` (45, the strongest signal:
what a component can genuinely hold), `structural_fit` (20), `value_fit` (10),
`kind_fit` (15), `name_affinity` (10, deliberately the weakest; raise it only
if your components are named with real discipline).

`resolver.penalties` subtract for a mismatch: `missing_required` (15, the
region needs a role this thing cannot hold), `dynamic_mismatch` (20, the
region shows live content but this thing is static markup), `subject_mismatch`
(35, it lists live content about the wrong subject: a view of articles is not
a team grid).

### The verdict thresholds

`resolver.thresholds.reuse_at` (85) and `.adapt_at` (60) are where the verdict
lines fall. Lower `reuse_at` to reuse more aggressively; raise it to let the
agent create new components more readily.

### What is never a candidate

`resolver.exclude` keeps layout plumbing, theme chrome, administrative Views
and machinery blocks out of consideration entirely:

| Key | Excludes |
| --- | --- |
| `layout_components` | Purely structural components (`spacer`, `section`, `column`, …) so they never get counted as "the four things a grid repeats". |
| `components` | Theme chrome (`page`, `page-header`, style-guide canvases like `colors`/`typography`). |
| `view_words` | Administrative/internal Views, matched on whole words (`log` never condemns `blog`). |
| `block_prefixes` / `block_categories` | Machinery blocks (`entity_field:`, `system_main_block`, the `administration`/`system` categories). |
| `block_category_keep` | Exceptions kept even in an excluded category: menus, breadcrumbs, branding are genuine design decisions. |

## AI Context items

`ai_context_items` seeds editable
[AI Context](https://www.drupal.org/project/ai_context) items on install,
currently one, **Figma Design Resolution Protocol**: the instruction that
tells a Canvas AI agent to call `scan_inventory` then `resolve_design` before
building anything, and to obey the verdict. Edit the seeded item at
**Configuration → AI → Context**, or change the default in
`varbase_ai_figma.settings` before install/reinstall.

Without `ai_context` installed, this is a no-op: the resolver tools still
work, but the agent is not proactively taught to call them.

## Orchestrator tool wiring

The 29 [AI Agent tools](ai-agent-tools.md) this module ships are attached to
the `canvas_ai_orchestrator` agent's `tools` map on install, in the
understand → decide → build → connect → check order the resolver expects. A
site that customises `ai_agents.ai_agent.canvas_ai_orchestrator` directly can
re-run the wiring by reinstalling the module or applying
[Varbase AI Figma Base](https://www.drupal.org/project/varbase_ai_figma_base),
which declares the same list as a recipe config action.
