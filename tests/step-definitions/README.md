# Step Definitions

`ai-figma.steps.js` holds the only steps not shipped by webship-js:

- **Theme-independent login** - `Given I am a logged in user with the
  "<user>" user` (uses Drupal's stable `#edit-name` / `#edit-pass` ids).
- **Test-user provisioning** - `Given I add testing users` (creates the
  non-admin rows from `worldParameters.users`).
- **Default-settings helper** - `Given I enable only the default AI Figma
  settings` (empties the default file key and restores the canonical Figma
  API base through the settings form, so later assertions are deterministic).
- **Named-selector vocabulary** (mirrors the webshare reference module):
  - `Then the "<key>" element should be visible|hidden|attached|focused|enabled|disabled|editable`
  - `Then the "<key>" element should have a count of <N>`
  - `Then the "<key>" element should have class "<class>"`
  - `Then the "<key>" element should contain text "<text>"`
  - `When I click the "<key>" element`
  - `Then I should see a|an "<label>" field`
  - `Then I should see the button "<text>"`
- **Live Canvas-AI demo vocabulary** (used only by the opt-in `@demo` / `@slow`
  scenarios - `tests/features/drupal/05-0x-0x-demo-*.feature`):
  - `When I fill in the "<key>" element with "<text>"` and a DocString variant
    `When I fill in the "<key>" element with:` - types a prompt into a named
    textbox (e.g. the Canvas AI `Build me a ...` input) and focuses it, so a
    following `When I press the key "Enter"` submits.
  - `Then the "<key>" element should not be empty within <N> seconds` - polls a
    named selector's text until it is non-empty.
  - `Then the "<key>" element should contain text matching "<regex>" within <N>
    seconds` - polls until the text matches a case-insensitive JS regex (a
    tolerant token like `match|reuse|component|score`, not exact LLM wording).
  - Both polling steps carry their own per-step timeout (`DEMO_STEP_TIMEOUT`,
    200s) so a 60-140s live LLM build never trips the suite-wide 45s default -
    which stays in force for the green lane.

`<key>` resolves against the selector registry in `tests/selectors/*.json`;
an unknown key fails fast with a "Did you mean …?" hint.

Everything else (navigation, `I should see …`, `I fill in …`, `I press …`,
`there should be no JavaScript errors`, `the page should have a … landmark`,
`the page should have no serious accessibility violations`) comes from
webship-js's built-in step library.
