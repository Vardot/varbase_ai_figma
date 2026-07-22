'use strict';

/**
 * @file
 * Custom step definitions for the AI Figma + Varbase AI Figma test suite.
 *
 * Modelled on the webshare reference module: every step drives the site
 * through the browser only - no Drush, no shell. The Mink-style navigation /
 * assertion / form steps (`I am on …`, `I should see …`, `I fill in …`,
 * `I press …`), the JavaScript-error check, the landmark and accessibility
 * audits are all provided by webship-js. Only the steps below are
 * module-specific or are the named-selector vocabulary webshare itself
 * defines (`Then the "<key>" element should be visible / have a count of N`,
 * `When I click the "<key>" element`, `Then I should see a "<label>" field`,
 * `Then I should see the button "<text>"`).
 *
 * Navigation and waiting reuse webship-js's own helpers - gotoUrl (friendly
 * navigation errors) and waitForPageLoad (BBR smart-settle: DOM ready,
 * network idle, no pending AJAX/timers, DOM-quiet) - instead of raw
 * Playwright waits, and failures are wrapped with friendly().
 */

const { Given, Then, When } = require('@cucumber/cucumber');
const {
  friendly,
  gotoUrl,
  waitForPageLoad,
} = require('webship-js/tests/step-definitions/webship');

/**
 * Run a step body and rethrow any failure as a tester-friendly error.
 *
 * @param {Function} body  - async function performing the step.
 * @param {string} message - human-readable description for failures.
 */
async function attempt(body, message) {
  try {
    await body();
  } catch (err) {
    throw friendly(message, err);
  }
}

/**
 * Log in as a named test user defined in cucumber.shared.js
 * worldParameters.users.
 *
 * The Webmaster row is the site-install super-admin; the lowercase
 * `webmaster` alias resolves to the same account and matches the Varbase /
 * Vardoc feature convention. Uses Drupal's stable field IDs so the step is
 * theme-independent (Olivero, Claro/Gin and vartheme_bs5 all render
 * `#edit-name` / `#edit-pass`).
 *
 * Example #1: Given I am a logged in user with the "Webmaster" user
 * Example #2: Given I am a logged in user with the "webmaster" user
 * Example #3: Given I am a logged in user with the "Content editor" user
 */
Given(/^I am a logged in user with( the)*( username)* "([^"]*)?"( user)?$/, async function (theCase, usernameCase, key, userCase) {
  const users = this.parameters.users || {};
  if (!(key in users)) {
    throw new Error(`No user named "${key}" in cucumber.shared.js worldParameters.users`);
  }
  const { username, password } = users[key];
  if (!username || !password) {
    throw new Error(`User "${key}" is missing username or password in worldParameters.users`);
  }
  await attempt(async () => {
    await this.context.clearCookies();
    await gotoUrl(this.page, `${this.parameters.launchUrl}/user/login`);
    await this.page.locator('#edit-name').fill(username);
    await this.page.locator('#edit-pass').fill(password);
    // Scope the submit to the login form so it works whether the active theme
    // renders it as an <input> (Olivero / Claro / Gin) or a <button>
    // (vartheme_bs5 / Bootstrap) - and never matches a header search button.
    await this.page.locator('#user-login-form #edit-submit').first().click();
    await waitForPageLoad(this.page, this.minWaitTime && this.minWaitTime.page);
  }, `Could not log in as "${key}"`);
});

/**
 * Assert the page does not contain a PHP error, fatal, warning, notice, or
 * Drupal's "unexpected error" page. Covers both "the page should not" and
 * "I the page should not" forms that appear after Given/And keywords.
 *
 * Example #1: Then the page should not have PHP errors
 * Example #2: And the page should not have PHP errors
 * Example #3: Then I the page should not have PHP errors
 * Example #4: And I the page should not have PHP errors
 * Example #5: And we the page should not have PHP errors
 */
Then(/^(?:I |we )?the page should not have PHP errors$/, async function () {
  await attempt(async () => {
    const content = await this.page.content();
    const phpErrorPatterns = [
      /Fatal error:/i,
      /Warning:.*on line/i,
      /Notice:.*on line/i,
      /Parse error:/i,
      /The website encountered an unexpected error/i,
    ];
    for (const pattern of phpErrorPatterns) {
      if (pattern.test(content)) {
        throw new Error(`PHP error detected on page: ${this.page.url()}`);
      }
    }
  }, 'Expected page to be free of PHP errors');
});

/**
 * Navigate to a path and assert the server denied access. Checks the HTTP
 * response status (403) first - theme-agnostic - and falls back to the Drupal
 * "not authorized" body text. Proves non-administrators are kept out of the AI
 * Figma configuration.
 *
 * Example #1: Then I am denied access to "/admin/config/ai/figma"
 * Example #2: And I am denied access to "/admin/config/ai/figma"
 * Example #3: Then I am denied access to "/admin/config/ai"
 * Example #4: And we am denied access to "/admin/reports/status"
 * Example #5: Then I am denied access to "/admin/config/ai"
 */
Then(/^(?:I |we )?am denied access to "([^"]*)"$/, async function (path) {
  await attempt(async () => {
    const response = await this.page.goto(`${this.parameters.launchUrl}${path}`, { waitUntil: 'networkidle' });
    const status = response ? response.status() : 0;
    if (status === 403) {
      return;
    }
    const body = await this.page.content();
    if (!/not authorized to access this page|access denied/i.test(body)) {
      throw new Error(`Expected access to "${path}" to be denied (HTTP 403), got HTTP ${status}`);
    }
  }, `Expected to be denied access to "${path}"`);
});

/**
 * Provision every non-admin user from worldParameters.users via Drupal's
 * /admin/people/create form. Entries flagged isAdmin: true are skipped (the
 * site-install Webmaster already exists). Idempotent. Must be invoked while
 * logged in as the Webmaster.
 *
 * Example #1: Given I add testing users
 * Example #2: And I add testing users
 */
Given(/^(?:I |we )?add( the)? testing users$/, async function (theCase) {
  const users = this.parameters.users || {};
  await attempt(async () => {
    for (const [key, info] of Object.entries(users)) {
      if (info.isAdmin) continue;
      // The lowercase "webmaster" alias points at the same admin account;
      // skip it too so we never try to recreate the super-admin.
      if (key === 'webmaster') continue;
      await gotoUrl(this.page, `${this.parameters.launchUrl}/admin/people/create`);
      await this.page.evaluate((info) => {
        const set = (sel, val) => { const el = document.querySelector(sel); if (el) el.value = val; };
        set('#edit-name', info.username);
        set('#edit-mail', info.email || `${info.username}@example.test`);
        set('#edit-pass-pass1', info.password);
        set('#edit-pass-pass2', info.password);
        for (const role of info.roles || []) {
          const cb = document.querySelector(`input[name="roles[${role}]"]`);
          if (cb) cb.checked = true;
        }
      }, info);
      // JS-click sidesteps any sticky form-actions overlay.
      await this.page.evaluate(() => document.querySelector('#edit-submit').click());
      await waitForPageLoad(this.page);
    }
  }, 'Could not provision the testing users');
});

/**
 * Restore the AI Figma settings to their fresh-install default through the
 * settings form: an empty default file key and the canonical Figma API base
 * (https://api.figma.com). This is the AI Figma analogue of webshare's
 * "I enable only the default Webshare platforms" - it puts the one config
 * page into a known state so later assertions are deterministic.
 *
 * Must be invoked while logged in as a user with "administer ai figma"
 * (e.g. the Webmaster). No live Figma call is made.
 *
 * Example: Given I enable only the default AI Figma settings
 */
Given(/^I enable only the default AI Figma settings$/, async function () {
  await attempt(async () => {
    await gotoUrl(this.page, `${this.parameters.launchUrl}/admin/config/ai/figma`);
    await waitForPageLoad(this.page);
    await this.page.evaluate(() => {
      const fileKey = document.querySelector("input[name='default_file_key']");
      if (fileKey) fileKey.value = '';
      const apiBase = document.querySelector("input[name='figma_api_base']");
      if (apiBase) apiBase.value = 'https://api.figma.com';
    });
    // "Save configuration" is the ConfigFormBase primary submit (#edit-submit).
    await this.page.evaluate(() => {
      const btn = document.querySelector('#edit-submit')
        || document.querySelector("input[value='Save configuration']");
      if (btn) btn.click();
    });
    await waitForPageLoad(this.page);
  }, 'Could not restore the default AI Figma settings');
});

/**
 * Resolve a webship-js named selector from the world registry.
 *
 * The registry (`world.__selectorsCss`) is hydrated by webship-js from
 * cucumber.shared.js's `selectors.files` list - see tests/selectors/*.json
 * for the catalog. Throws when the name is unknown so a typo never silently
 * passes through to Playwright as a literal CSS string.
 */
function resolveName(world, name) {
  const css = world.__selectorsCss || {};
  const key = name.trim();
  if (Object.prototype.hasOwnProperty.call(css, key)) {
    return css[key];
  }
  // Suggest the closest registered name (Levenshtein distance) so a typo
  // surfaces a one-line hint instead of a wall of selectors. The bulk dump
  // is still available via `Then print css selectors` (webship-js).
  const keys = Object.keys(css);
  let best = null;
  let bestDistance = Infinity;
  for (const candidate of keys) {
    const distance = (function lev(a, b) {
      const m = a.length;
      const n = b.length;
      if (!m) return n;
      if (!n) return m;
      const row = new Array(n + 1);
      for (let j = 0; j <= n; j += 1) row[j] = j;
      for (let i = 1; i <= m; i += 1) {
        let prev = i;
        for (let j = 1; j <= n; j += 1) {
          const cost = a.charCodeAt(i - 1) === b.charCodeAt(j - 1) ? 0 : 1;
          const cur = Math.min(row[j] + 1, prev + 1, row[j - 1] + cost);
          row[j - 1] = prev;
          prev = cur;
        }
        row[n] = prev;
      }
      return row[n];
    }(key, candidate));
    if (distance < bestDistance) {
      bestDistance = distance;
      best = candidate;
    }
  }
  const hint = best && bestDistance <= Math.max(4, Math.floor(key.length / 3))
    ? ` Did you mean "${best}"?`
    : '';
  throw new Error(`Unknown named selector "${key}".${hint} Run "Then print css selectors" to see all ${keys.length} registered names.`);
}

/**
 * Assert a named selector is visible / hidden / attached / focused / enabled /
 * disabled / editable. Mirrors webship-js's raw-CSS `should be …` phrasing.
 *
 * Example #1: Then the "ai figma settings form" element should be visible
 * Example #2: Then the "ai figma open ai panel" element should be visible within 5 seconds
 * Example #3: Then the "ai figma settings form" element should be hidden
 */
Then(/^the "([^"]*)" element should be (visible|hidden|attached|focused|enabled|disabled|editable)(?: within (\d+) seconds?)?$/, async function (name, state, sec) {
  const sel = resolveName(this, name);
  const loc = this.page.locator(sel);
  const timeout = sec ? Number(sec) * 1000 : 10000;
  await attempt(async () => {
    if (state === 'visible' || state === 'attached') {
      await loc.first().waitFor({ state, timeout });
    }
    else if (state === 'hidden') {
      await loc.first().waitFor({ state: 'hidden', timeout });
    }
    else if (state === 'focused') {
      await this.page.waitForFunction(s => document.activeElement && document.activeElement.matches(s), sel, { timeout });
    }
    else {
      const fn = { enabled: 'isEnabled', disabled: 'isDisabled', editable: 'isEditable' }[state];
      const ok = await loc.first()[fn]();
      if (!ok) throw new Error(`"${name}" not ${state}`);
    }
  }, `Expected "${name}" (${sel}) to be ${state}`);
});

/**
 * Assert the count of elements matching a named selector.
 *
 * Example #1: Then the "ai figma settings form" element should have a count of 1
 * Example #2: Then the "ai figma test connection button" element should have a count of 1
 * Example #3: Then the "ai figma settings form" element should have a count of 0
 */
Then(/^the "([^"]*)" element should have a count of (\d+)(?: within (\d+) seconds?)?$/, async function (name, expected, sec) {
  const sel = resolveName(this, name);
  const target = Number(expected);
  const timeout = sec ? Number(sec) * 1000 : 10000;
  // Use Playwright's locator engine so selector extensions like
  // `:has-text('X')` (used for table-cell content matches) resolve.
  const loc = this.page.locator(sel);
  const deadline = Date.now() + timeout;
  let last = -1;
  await attempt(async () => {
    while (Date.now() < deadline) {
      last = await loc.count();
      if (last === target) return;
      await this.page.waitForTimeout(100);
    }
    throw new Error(`count was ${last}`);
  }, `Expected "${name}" (${sel}) count to be ${target}`);
});

/**
 * Assert the first element matching a named selector carries a CSS class.
 *
 * Example: Then the "ai figma settings form" element should have class "ai-figma-settings"
 */
Then(/^the "([^"]*)" element should have class "([^"]*)"(?: within (\d+) seconds?)?$/, async function (name, cls, sec) {
  const sel = resolveName(this, name);
  const timeout = sec ? Number(sec) * 1000 : 10000;
  await attempt(async () => {
    await this.page.waitForFunction(
      ([s, c]) => { const el = document.querySelector(s); return el && el.classList.contains(c); },
      [sel, cls],
      { timeout, polling: 100 },
    );
  }, `Expected "${name}" (${sel}) to have class "${cls}"`);
});

/**
 * Assert the first element matching a named selector contains the given text.
 *
 * Example #1: Then the "drupal page heading" element should contain text "AI Figma"
 * Example #2: Then the "drupal admin status messages" element should contain text "saved"
 */
Then(/^the "([^"]*)" element should contain text "([^"]*)"(?: within (\d+) seconds?)?$/, async function (name, text, sec) {
  const sel = resolveName(this, name);
  const timeout = sec ? Number(sec) * 1000 : 10000;
  await attempt(async () => {
    await this.page.waitForFunction(
      ([s, t]) => { const el = document.querySelector(s); return el && el.textContent.includes(t); },
      [sel, text],
      { timeout, polling: 100 },
    );
  }, `Expected "${name}" (${sel}) to contain text "${text}"`);
});

/**
 * Click the first element matching a named selector. Falls back to a JS
 * `.click()` after a failed Playwright actionability retry so a sticky
 * form-actions overlay does not stall the click.
 *
 * Example: When I click the "ai figma test connection button" element
 */
When(/^(?:I |we )?click(?: on)?(?: the)? "([^"]*)" element$/, async function (name) {
  const sel = resolveName(this, name);
  await attempt(async () => {
    const loc = this.page.locator(sel).first();
    await loc.waitFor({ state: 'visible', timeout: 10000 });
    try {
      await loc.click({ timeout: 4000 });
    }
    catch (e) {
      await this.page.evaluate((s) => {
        const el = document.querySelector(s);
        if (el) el.click();
      }, sel);
    }
  }, `Could not click the "${name}" element`);
});

/**
 * Resolve a form field locator by label, falling back to the label element
 * itself for inputs that are visually replaced by rich editors / widgets.
 */
function fieldLocator(page, label) {
  return page
    .locator('label.form-item__label, label.form-required, label')
    .filter({ hasText: new RegExp(`^\\s*${label.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\$&')}(\\s|$)`, 'i') })
    .first();
}

/**
 * Assert that a form field with the given label is visible on the page.
 *
 * Example #1: Then I should see a "Default Figma file key" field
 * Example #2: Then I should see a "Figma API base URL" field
 */
Then(/^(?:I |we )?should see a "([^"]*)" field$/, async function (label) {
  await attempt(async () => {
    const locator = fieldLocator(this.page, label);
    await locator.waitFor({ state: 'visible', timeout: 10000 });
  }, `Expected to find a field labeled "${label}"`);
});

/**
 * Assert that a form field with the given label (article "an") is visible.
 *
 * Example: Then I should see an "API base URL" field
 */
Then(/^(?:I |we )?should see an "([^"]*)" field$/, async function (label) {
  await attempt(async () => {
    const locator = fieldLocator(this.page, label);
    await locator.waitFor({ state: 'visible', timeout: 10000 });
  }, `Expected to find a field labeled "${label}"`);
});

/**
 * Assert that a button with the given text is visible on the page.
 *
 * Example #1: Then I should see the button "Save configuration"
 * Example #2: Then I should see the button "Test Figma connection"
 */
Then(/^(?:I |we )?should see the button "([^"]*)"$/, async function (text) {
  await attempt(async () => {
    const locator = this.page.getByRole('button', { name: text, exact: false }).first();
    await locator.waitFor({ state: 'visible', timeout: 10000 });
  }, `Expected to find a button with text "${text}"`);
});

// ---------------------------------------------------------------------------
// Live Canvas-AI demo vocabulary (used only by the @demo / @slow scenarios).
//
// These four steps drive the Drupal Canvas AI assistant panel: type a prompt
// into the named "Build me a ..." textbox, then poll the panel's response
// region for an answer. They reuse the same named-selector registry as the
// rest of this file (resolveName), so the brittle CSS never reaches a feature
// file. The polling steps carry an explicit, generous per-step timeout
// ({ timeout }) so a 60-140s live LLM build never trips the suite-wide 45s
// default in cucumber.js - without lowering that default for the green lane.
// ---------------------------------------------------------------------------

// A live LLM build can stream for well over two minutes; give the polling
// steps their own ceiling so the default 45s suite timeout stays untouched.
const DEMO_STEP_TIMEOUT = 200000;

/**
 * Type text into the textbox matched by a named selector (e.g. the Canvas AI
 * assistant "Build me a ..." input). Focuses the field so a following
 * `When I press the key "Enter"` submits the prompt. Mirrors the suite's
 * named-selector vocabulary (`I click the "<key>" element`) rather than
 * leaking the raw CSS into the feature file.
 *
 * Example #1: When I fill in the "ai figma assistant input" element with "Build me a hero"
 * Example #2: When I fill in the "ai figma assistant input" element with:
 *               """
 *               Build me a hero from @https://www.figma.com/design/...
 *               """
 */
When(/^(?:I |we )?fill in the "([^"]*)" element with "([^"]*)"$/, async function (name, value) {
  const sel = resolveName(this, name);
  await attempt(async () => {
    const loc = this.page.locator(sel).first();
    await loc.waitFor({ state: 'visible', timeout: 30000 });
    await loc.click();
    await loc.fill(value);
  }, `Could not fill the "${name}" element`);
});

When(/^(?:I |we )?fill in the "([^"]*)" element with:$/, async function (name, docstring) {
  const sel = resolveName(this, name);
  await attempt(async () => {
    const loc = this.page.locator(sel).first();
    await loc.waitFor({ state: 'visible', timeout: 30000 });
    await loc.click();
    await loc.fill(docstring);
  }, `Could not fill the "${name}" element`);
});

/**
 * Poll a named selector's text content until it is non-empty (trimmed) or the
 * timeout elapses. Used to assert the AI panel produced *some* response
 * without coupling to exact LLM wording.
 *
 * Example: Then the "ai figma assistant response" element should not be empty within 180 seconds
 */
Then(/^the "([^"]*)" element should not be empty(?: within (\d+) seconds?)?$/, { timeout: DEMO_STEP_TIMEOUT }, async function (name, sec) {
  const sel = resolveName(this, name);
  const timeout = sec ? Number(sec) * 1000 : 30000;
  await attempt(async () => {
    await this.page.waitForFunction(
      (s) => {
        const el = document.querySelector(s);
        return !!el && el.textContent.replace(/\s+/g, ' ').trim().length > 0;
      },
      sel,
      { timeout, polling: 500 },
    );
  }, `Expected "${name}" (${sel}) to contain some text`);
});

/**
 * Poll a named selector's text content until it matches a (case-insensitive)
 * JavaScript regular-expression source, or the timeout elapses. Tolerant by
 * design: the AI assistant's exact phrasing varies between runs, so a
 * scenario asserts a stable *token* (e.g. "match|reuse|component|score")
 * rather than a fixed sentence.
 *
 * Example #1: Then the "ai figma assistant response" element should contain text matching "component|figma" within 180 seconds
 * Example #2: Then the "ai figma assistant response" element should contain text matching "match|reuse|score" within 120 seconds
 */
Then(/^the "([^"]*)" element should contain text matching "([^"]*)"(?: within (\d+) seconds?)?$/, { timeout: DEMO_STEP_TIMEOUT }, async function (name, pattern, sec) {
  const sel = resolveName(this, name);
  const timeout = sec ? Number(sec) * 1000 : 30000;
  await attempt(async () => {
    await this.page.waitForFunction(
      ([s, p]) => {
        const el = document.querySelector(s);
        if (!el) return false;
        const text = el.textContent.replace(/\s+/g, ' ');
        try {
          return new RegExp(p, 'i').test(text);
        } catch (e) {
          return text.toLowerCase().includes(p.toLowerCase());
        }
      },
      [sel, pattern],
      { timeout, polling: 500 },
    );
  }, `Expected "${name}" (${sel}) to contain text matching /${pattern}/i`);
});
