# LIVE CANVAS-AI DEMO SCENARIO - opt-in, NOT part of the green CI lane.
#
# This feature drives the real Drupal Canvas AI assistant against a live AI
# provider: it types a prompt and waits for a streamed LLM build (60-140s).
# It is therefore network-, provider- and API-key-dependent and is excluded
# from the default lane (see cucumber.js: `not @demo and not @canvas-editor`).
# Run it explicitly against a site that has the modules enabled AND a provider
# key configured:
#
#   npx cucumber-js --config cucumber.js --tags @demo
#   LAUNCH_URL=https://v11x00test1.ddev.site npx cucumber-js --config cucumber.js --tags @demo
#
# TIMEOUTS: the suite-wide cucumber timeout stays at 45s (cucumber.js) for the
# green lane. The long wait lives in the custom polling step
# (`... should contain text matching "..." within N seconds`), which carries
# its own generous per-step timeout (DEMO_STEP_TIMEOUT, 200s) in
# tests/step-definitions/ai-figma.steps.js - so this demo never trips, and the
# fast suite's reliability is never lowered. If your provider is slower, raise
# the `within N seconds` number on the assertion below (and, if needed, bump
# DEMO_STEP_TIMEOUT).

@ai-figma @demo @slow @canvas-editor
Feature: AI Figma demo - create a reusable Canvas component from a Figma component
  As a site builder using Drupal Canvas
  I want to ask the AI assistant to turn a Figma component into a reusable Canvas component
  So that my design system maps onto the theme's own components and props

  # Empty "AI Figma Demo" page (id 8) is the build target. The Canvas editor is
  # a single-page app, so the panel control only appears after the app boots -
  # hence the generous `within 30 seconds` waits.
  Background:
    Given I am a logged in user with the "Webmaster" user
    And I am on "/canvas/editor/canvas_page/8"
    And the "ai figma open ai panel" element should be visible within 30 seconds
    When I click the "ai figma open ai panel" element
    Then the "ai figma assistant input" element should be visible within 30 seconds

  # Realistic, non-flaky: we assert the panel produced a relevant response
  # (a stable token, not exact LLM wording) and that no fatal editor error
  # dialog surfaced - never an exact component name or sentence.
  Scenario: The assistant turns a Figma component into a reusable Canvas component
    When I fill in the "ai figma assistant input" element with:
      """
      Create a reusable Drupal Canvas component from this Figma component, mapping its design tokens onto my theme's existing components and props rather than hard-coding values. @https://www.figma.com/design/RJkuWNHla1P8VYHa5z6dnL/VB---Approved-Stylesheet?node-id=9668-2799
      """
    And I press the key "Enter"
    Then the "ai figma assistant response" element should contain text matching "component|figma" within 180 seconds
    And the "ai figma editor error dialog" element should have a count of 0
