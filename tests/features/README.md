# AI Figma - varbase-e2e feature scenarios

Browser-only BDD (Playwright + Cucumber via varbase-e2e) for the `ai_figma` +
`varbase_ai_figma` modules. The feature set is split by flavour, the same way
the `webshare` reference module splits its suite:

- `drupal/` - Varbase 11 / Drupal (vartheme_bs5 + Canvas). Loaded by the
  default `cucumber.js`.
- `drupalcms/` - reserved for a Drupal CMS (Mercury theme) variant. Loaded by
  `cucumber.drupalcms.js`.

| File | Tags | Covers |
|------|------|--------|
| `drupal/01-01-01-ai-figma-settings.feature` | `@ai-figma @admin` | The single config page at `/admin/config/ai/figma`: Figma token Key, default file key, API base, "Test Figma connection" + "Save configuration". Confirms there is **no** layout or builder admin page (those paths show no settings form). |
| `drupal/02-01-01-ai-figma-tools.feature` | `@ai-figma @canvas` | The AI Figma tools are surfaced to the Drupal Canvas AI assistant (UI presence only - no live LLM run): settings page clean of JS errors, Canvas AI settings surface reachable, and (opt-in `@canvas-editor`) the editor's "Open AI Panel" control + "Build me a ..." assistant input. |
| `drupal/03-01-01-ai-figma-accessibility.feature` | `@ai-figma @a11y @admin` | The settings page is a labelled form, exposes the main + navigation landmarks, has no serious accessibility violations, and produces no JS errors. |
| `drupal/05-01-01-demo-create-component.feature` | `@ai-figma @demo @slow @canvas-editor` | **Live LLM demo.** Asks the Canvas AI assistant to turn a Figma component into a reusable Canvas component; asserts the panel produced a relevant response and no fatal error dialog. |
| `drupal/05-02-01-demo-match-components.feature` | `@ai-figma @demo @canvas-editor` | **Live LLM demo (the fast, reliable one).** Asks the assistant to match a Figma section to the existing component library (reuse / extend / create + score); asserts a matching/reuse token appears. |
| `drupal/05-03-01-demo-build-section.feature` | `@ai-figma @demo @slow @canvas-editor` | **Live LLM demo (the long build, 60-140s).** Asks the assistant to build a Figma section reusing existing components; asserts a response and that the empty-region placeholder is gone. May need a re-send on a transient "Failed to fetch". |
| `drupal/05-04-01-demo-reorder-sections.feature` | `@ai-figma @demo @slow @canvas-editor` | **Live LLM demo.** On the rich "Features" page, asks the assistant to reorder sections and report the new order; asserts an order/move token appears. |

> The `@demo` features are **opt-in** and excluded from the default green lane:
> they drive a real AI provider and wait 60-140s for a streamed build, so they
> are network-, provider- and API-key-dependent. They also need a Canvas page
> to exist - `canvas_page` **8** (empty "AI Figma Demo", the build target) and
> **1** ("Features", the reorder target) - on the target site. Run them from
> their own config (a CLI `--tags @demo` is ANDed with the default config's
> `not @demo`, so it selects nothing - hence the dedicated config):
>
> ```bash
> LAUNCH_URL=https://v11x00test1.ddev.site \
>   npx cucumber-js --config cucumber.demo.js              # all 4 demos
> npx cucumber-js --config cucumber.demo.js \
>   tests/features/drupal/05-02-01-demo-match-components.feature  # the fast one
> ```
>
> The suite-wide 45s cucumber timeout is left untouched; the long LLM waits
> carry their own per-step timeout inside the custom polling steps (see
> `tests/step-definitions/ai-figma.steps.js`, `DEMO_STEP_TIMEOUT`). Raise the
> `within N seconds` number on an assertion if your provider is slower.

## Running the suite

```bash
npm test                                   # all drupal/ features (default)
npm test -- --tags @admin                  # only admin scenarios
npm test -- --tags @a11y                   # only accessibility scenarios
npm test -- --tags @canvas                 # only Canvas AI scenarios
npm test -- --tags "@canvas-editor"        # the opt-in live-editor scenario
```

Point the suite at any running site that has the modules enabled:

```bash
LAUNCH_URL=https://your-site.ddev.site npm test
```

## Step vocabulary

Every scenario uses step phrasings varbase-e2e ships (navigation, `I should
see …`, `I fill in …`, `I press …`, the JavaScript-error check, the landmark
and accessibility audits) plus the named-selector vocabulary defined in
`tests/step-definitions/ai-figma.steps.js`
(`Then the "<key>" element should be visible / have a count of N / contain
text "…"`, `When I click the "<key>" element`, `Then I should see a "<label>"
field`, `Then I should see the button "<text>"`). Named selector keys resolve
against `tests/selectors/*.json`.
