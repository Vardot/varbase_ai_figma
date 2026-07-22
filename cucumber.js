// Default cucumber-js config - runs the Drupal / Varbase suite.
//
// The AI Figma feature set is split by flavour the same way the webshare
// reference module splits its suite:
//   tests/features/drupal/      - Varbase 11 / Drupal (vartheme_bs5 + Canvas)
//   tests/features/drupalcms/   - Drupal CMS distribution (reserved)
//
// This config loads only the `drupal/` features. The Drupal CMS suite lives
// in `cucumber.drupalcms.js`:
//
//   npx cucumber-js --config cucumber.js              # Drupal / Varbase (default)
//   npx cucumber-js --config cucumber.drupalcms.js    # Drupal CMS
//
// Browser-only BDD (Playwright + Cucumber via webship-js). Point it at any
// running site that has the ai_figma + varbase_ai_figma modules enabled:
//
//   LAUNCH_URL=https://your-site.ddev.site npm test
//
// Loads webship-js's built-in step library plus this module's custom steps.

const baseWorldParameters = require('./cucumber.shared.js');

// This flavour writes its cucumber JSON under tests/reports/drupal/ (see the
// `format` block below). webship-js auto-generates an HTML report at process
// exit and defaults to tests/reports/cucumber_report.json, so point its input
// (and HTML output) at the flavour directory; otherwise the exit hook throws
// ENOENT and the process exits non-zero even though every scenario passed.
// Respect an explicit override if the operator already set these.
process.env.WEBSHIP_REPORT_JSON =
  process.env.WEBSHIP_REPORT_JSON || 'tests/reports/drupal/cucumber_report.json';
process.env.WEBSHIP_REPORT_OUT =
  process.env.WEBSHIP_REPORT_OUT || 'tests/reports/drupal/cucumber_report.html';

module.exports = {
  default: {
    timeout: 45000,
    requireModule: ['tsx/cjs'],
    require: [
      'node_modules/webship-js/tests/step-definitions/**/*.js',
      'tests/step-definitions/**/*.js',
    ],
    paths: ['tests/features/drupal/**/*.feature'],
    // The default lane is the always-green CI set. Two families are opt-in and
    // excluded here:
    //   @canvas-editor - needs a real canvas_page on the target site (the
    //                    Canvas editor is a single-page app). Run with
    //                      npx cucumber-js --config cucumber.js --tags @canvas-editor
    //   @demo          - the live Canvas-AI demo scenarios. They send a real
    //                    prompt to the AI provider and wait 60-140s for a
    //                    streamed build, so they are network + provider + key
    //                    dependent and deliberately kept OUT of CI. Run with
    //                      npx cucumber-js --config cucumber.js --tags @demo
    //                    (a CLI --tags replaces this config expression, exactly
    //                    as the @canvas-editor note above already relies on).
    // See 02-01-01-ai-figma-tools.feature and the 05-0x-0x demo features.
    tags: 'not @canvas-editor and not @demo',
    format: [
      'pretty',
      'json:tests/reports/drupal/cucumber_report.json',
    ],
    worldParameters: Object.assign({}, baseWorldParameters, {
      screenshot: Object.assign({}, baseWorldParameters.screenshot, {
        dir: './tests/screenshots/drupal',
      }),
      video: Object.assign({}, baseWorldParameters.video, {
        dir: './tests/videos/drupal',
      }),
    }),
  },
};
