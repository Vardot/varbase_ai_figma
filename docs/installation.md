# Installation

## Install the module

```bash
composer require drupal/varbase_ai_figma
ddev drush en varbase_ai_figma -y
```

This also installs `ai_figma`, `ai_context`, `easy_encryption`, `ai_agents`,
`ai`, `key` and `canvas` (Drupal Canvas).

On install the module:

- Seeds the **Figma Design Resolution Protocol** AI Context item from
  `varbase_ai_figma.settings` (see [Configuration](configuration.md)), so a
  Canvas AI agent that reads AI Context immediately knows to resolve a design
  before it builds.
- Wires its [AI Agent tools](ai-agent-tools.md) onto the
  `canvas_ai_orchestrator` agent, in the reuse-before-create order the
  resolver expects.

!!! tip "Prefer the recipe for a full site"
    [Varbase AI Figma Base](https://www.drupal.org/project/varbase_ai_figma_base)
    applies this module together with Varbase AI Base, grants the right
    permissions to the right roles, and wires the orchestrator in one step:

    ```bash
    composer require drupal/varbase_ai_figma_base
    ddev drush recipe ../recipes/varbase_ai_figma_base -y
    ```

## Connect a Figma token

The Figma connection itself (the personal access token, the default file key)
is configured once, in `ai_figma`; see
[AI Figma's installation guide](https://project.pages.drupalcode.org/ai_figma/installation/).
This module only adds the resolver and the site-specific tools on top.

## Permissions

Grant these to the roles that should use the assistant (see the recipe for the
default grants):

| Permission | Typical role |
| --- | --- |
| `administer ai figma` | Site Administrator (Figma settings page) |
| `use ai figma design context` | Site Administrator / Content Admin |
| `use Drupal Canvas AI` | Content Admin / Content Editor (the Canvas AI panel) |

## Uninstall

```bash
ddev drush pmu varbase_ai_figma -y
```

The seeded AI Context item and the orchestrator's tool wiring are this
module's config; removing it does not remove `ai_figma`, `ai_context` or the
other dependencies.
