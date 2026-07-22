# AI Figma - webship-js BDD suite

Browser-only BDD tests (Playwright + Cucumber via webship-js) for the
`ai_figma` and `varbase_ai_figma` modules. No Drush, no shell - every step
drives the site through the browser, so the suite runs against any site that
has the modules enabled. Structured exactly like the `webshare` reference
module's suite.

## Run

```bash
npm install
LAUNCH_URL=https://your-site.ddev.site npm test
```

The target site needs the modules enabled and (for the live "Test Figma
connection" / Canvas build paths, which this suite deliberately does not
exercise) a Figma token in the Key module. Run a tag subset with, for
example, `npm test -- --tags @a11y`.

## Layout

- `features/drupal/`     numbered `NN-NN-NN-name.feature` files for the Drupal
  / Varbase flavour: settings, Canvas AI tools, accessibility.
- `features/drupalcms/`  reserved for a Drupal CMS variant (loaded by
  `cucumber.drupalcms.js`).
- `step-definitions/`    `ai-figma.steps.js` - theme-independent login, the
  default-settings helper, and the named-selector vocabulary (`should be
  visible` / `have a count of N` / `contain text` / click / field / button).
- `selectors/`           canonical CSS selector registry: `ai-figma.json` for
  the module UI plus `cms-drupal-core-claro.json` for shared admin chrome.
- `reports/` `screenshots/` `videos/`  per-flavour run artefacts. The folders
  and their READMEs are tracked; the generated files inside are gitignored so
  release tarballs stay clean.

The `Webmaster` row in `cucumber.shared.js` `worldParameters.users` is the
site-install super-admin. Override `LAUNCH_URL` for any target site.

## Configs

- `cucumber.js`           default - loads `features/drupal/**`.
- `cucumber.drupalcms.js` loads `features/drupalcms/**`.
- `cucumber.shared.js`    shared worldParameters (users, selectors, breakpoints,
  screenshot / video settings) both flavours layer their own paths on top of.
- `playwright.config.ts`  browser launch + context options (`BROWSER=` env).
- `tsconfig.json`         TypeScript settings for `tsx/cjs`.
