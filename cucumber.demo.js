// cucumber-js config for the LIVE Canvas-AI demo lane (opt-in, never CI).
//
// The `05-0x-0x-demo-*.feature` scenarios drive the real Drupal Canvas AI
// assistant against a live AI provider: they type a prompt and wait 60-140s
// for a streamed LLM build. They are network-, provider- and API-key-dependent
// and are deliberately kept OUT of the always-green default lane (cucumber.js,
// which filters `not @demo and not @canvas-editor`).
//
// This separate config selects ONLY the @demo scenarios and routes their
// reports / screenshots / videos into demo-specific subdirectories, mirroring
// the module's existing flavour split (see cucumber.drupalcms.js). It exists
// because, in cucumber-js v12, a CLI `--tags` expression is ANDed with the
// config `tags` (it does not replace it), so `--config cucumber.js --tags @demo`
// resolves to `(not @demo) and (@demo)` = 0 scenarios. A dedicated config is
// the reliable way to run the opt-in lane.
//
// Run as (point LAUNCH_URL at a site with the modules + a provider key, and
// with canvas_page 8 (empty) and 1 (Features) present):
//
//   LAUNCH_URL=https://v11x00test1.ddev.site \
//     npx cucumber-js --config cucumber.demo.js
//
//   # the fast, reliable matching demo only:
//   npx cucumber-js --config cucumber.demo.js tests/features/drupal/05-02-01-demo-match-components.feature
//
// TIMEOUTS: the per-scenario step timeout below stays at the normal 45s; the
// long LLM waits live inside the custom polling steps (DEMO_STEP_TIMEOUT, 200s)
// in tests/step-definitions/ai-figma.steps.js, which override the default for
// those steps only - so the green suite's reliability is never lowered.

const baseWorldParameters = require('./cucumber.shared.js');

process.env.VARBASE_E2E_REPORT_JSON =
  process.env.VARBASE_E2E_REPORT_JSON || 'tests/reports/demo/cucumber_report.json';
process.env.VARBASE_E2E_REPORT_OUT =
  process.env.VARBASE_E2E_REPORT_OUT || 'tests/reports/demo/cucumber_report.html';

module.exports = {
  default: {
    timeout: 45000,
    requireModule: ['tsx/cjs'],
    require: [
      'node_modules/@vardot/varbase-e2e/tests/step-definitions/**/*.js',
      'tests/step-definitions/**/*.js',
    ],
    paths: ['tests/features/drupal/**/*.feature'],
    // Only the live-LLM demo scenarios.
    tags: '@demo',
    format: [
      '@cucumber/pretty-formatter',
      'json:tests/reports/demo/cucumber_report.json',
    ],
    worldParameters: Object.assign({}, baseWorldParameters, {
      screenshot: Object.assign({}, baseWorldParameters.screenshot, {
        dir: './tests/screenshots/demo',
      }),
      video: Object.assign({}, baseWorldParameters.video, {
        dir: './tests/videos/demo',
      }),
    }),
  },
};
