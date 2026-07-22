# Varbase AI Figma

Varbase's own tuning of [AI Figma](https://www.drupal.org/project/ai_figma) for
the Bootstrap 5.3 `vartheme_bs5` theme: the same Figma-to-Canvas engine, but
taught the real components, patterns, blocks and views a Varbase site ships,
and taught to **resolve a design against them before it builds anything**.

Where AI Figma reads a design, this module adds the decision layer: a resolver
that scores every region of the design against what the site already has, then
returns REUSE, ADAPT or CREATE, so the Canvas AI assistant reuses a component,
a saved Pattern, a Block or a View instead of inventing something that is
already sitting in the library.

## How it fits together

```
Figma REST API  ──▶  ai_figma (design context)
                              │
                              ▼
                 varbase_ai_figma resolver
        (scan_inventory + resolve_design, config-driven)
                              │
                              ▼
                REUSE / ADAPT / CREATE, region by region
                              │
                              ▼
      29 AI Agent tools: build, wire up, check, improve
                              │
                              ▼
                Drupal Canvas AI assistant
```

- **`scan_inventory`** shows the palette: Canvas components with their real
  props/enums/slots, saved Patterns, Block plugins and Views.
- **`resolve_design`** scores each design region against that palette using
  config-driven weights, penalties and thresholds; nothing is hard-coded.
- The remaining tools build what the decision chose, wire it into the rest of
  the site (front page, menus, Webforms/Views), and check and improve it
  afterwards.

Everything the resolver reasons with (content roles, signal weights, the
reuse/adapt/create thresholds, and what is never a candidate) lives in
`varbase_ai_figma.settings`, so behaviour is tuned in config, not code. See
[Configuration](configuration.md).

## Next steps

- [Installation](installation.md): install the module (or apply
  [Varbase AI Figma Base](https://www.drupal.org/project/varbase_ai_figma_base)
  for the full recipe).
- [Configuration](configuration.md): the resolver's tuning and the AI Context
  items it teaches the agent.
- [AI Agent tools](ai-agent-tools.md): reference for all 29 tools.

## Requirements

- Drupal **^11.3**
- `ai_figma`, `ai_context`, `easy_encryption`, `ai_agents`, `ai`, `key`,
  `canvas` (Drupal Canvas)
- vartheme_bs5 (Bootstrap 5.3) as the active theme, for the layouts and
  component choices this module ships
