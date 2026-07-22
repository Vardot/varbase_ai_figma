// Shared cucumber-js worldParameters for the AI Figma suite.
//
// `cucumber.js` (Drupal / Varbase flavour) imports this and layers its own
// report / screenshot / video paths on top, mirroring the webshare reference
// module's flavour-split config so artefacts do not collide if a second
// flavour is added later (see cucumber.drupalcms.js).

module.exports = {
  launchUrl: process.env.LAUNCH_URL || 'http://localhost',
  // Test users for each role the scenarios exercise.
  //
  // The Webmaster row is the logical "site super-admin" (uid 1) of the target
  // site. On the live test site (v11x00test1) that account is the standard
  // Drupal `admin`, so the username/password resolve to it. The lowercase
  // `webmaster` alias points at the same account and matches the Varbase /
  // Vardoc feature convention so either casing works in a Background.
  // Override per environment with the LOGIN_USER / LOGIN_PASS env vars.
  users: {
    'Webmaster': {
      username: process.env.LOGIN_USER || 'webmaster',
      email: 'webmaster@example.test',
      password: process.env.LOGIN_PASS || 'dD.123123ddd',
      isAdmin: true,
    },
    'webmaster': {
      username: process.env.LOGIN_USER || 'webmaster',
      email: 'webmaster@example.test',
      password: process.env.LOGIN_PASS || 'dD.123123ddd',
      isAdmin: true,
    },
    'Content editor': {
      username: 'content_editor_user',
      email: 'content_editor_user@example.test',
      password: 'dD.123123ddd',
      roles: ['content_editor'],
    },
    'Authenticated user': {
      username: 'authenticated_user',
      email: 'authenticated_user@example.test',
      password: 'dD.123123ddd',
      roles: [],
    },
  },
  minWaitTime: {
    page: 3000,
    before_scenario: 0,
    after_scenario: 0,
    before_step: 0,
    after_step: 0,
  },
  selectors: {
    css: {},
    xpath: {},
    filesPath: './tests/selectors/',
    files: [
      'cms-drupal-core-claro.json',
      'cms-drupal-cms-gin.json',
      'cms-varbase-vartheme.json',
      'ai-figma.json',
    ],
    offset: 60,
    breakpoints: {
      xs:  { width: 375,  height: 667  },
      sm:  { width: 576,  height: 800  },
      md:  { width: 768,  height: 1024 },
      lg:  { width: 992,  height: 768  },
      xl:  { width: 1200, height: 900, default: true },
      xxl: { width: 1400, height: 900 },
    },
  },
  screenshot: {
    dir: './tests/screenshots',
    purge: false,
    onFailed: true,
    onEveryStep: false,
    alwaysFullscreen: false,
    failedPrefix: 'failed_',
    filenamePattern: '{datetime}.{feature_file}.feature_{step_line}.{ext}',
    filenamePatternFailed: '{failed_prefix}{datetime}.{feature_file}.feature_{step_line}.{ext}',
    infoTypes: '',
  },
  video: {
    mode: 'on-failure',
    dir: './tests/videos',
    size: { width: 1280, height: 720 },
    filenamePattern: '{datetime}.{feature_file}.{scenario}.{status}.{ext}',
  },
  javascript: {
    mode: 'warn',
    levels: ['error'],
    ignore: '',
    beforeScenario: false,
    afterScenario: true,
  },
};
