@ai-figma @canvas
Feature: AI Figma module - tools available to the Canvas AI assistant
  As a site builder using Drupal Canvas
  I want the AI Figma tools to be available to the Canvas AI assistant
  So that I can build pages from a Figma design through the editor, not a bespoke form

  # AI Figma deliberately ships no builder UI of its own: its AI Agent tools
  # (read Figma design context, build Canvas pages from the theme's own
  # components) are surfaced to the Drupal Canvas AI assistant. These scenarios
  # assert UI presence only - they never trigger a live LLM build, so they pass
  # in CI that has no provider key configured. The Canvas editor selectors live
  # in tests/selectors/ai-figma.json (button[aria-label='Open AI Panel'] and the
  # "Build me a ..." assistant input).

  Background:
    Given I am a logged in user with the "Webmaster" user

  # Verified clean when authenticated: the settings page emits zero JS errors
  # for the logged-in super-admin. (Any darkmode_class / 403 noise only shows up
  # on the unauthenticated 403 page of a broken login run - not the module page,
  # so nothing is ignored here.) See 03-01-01 for the same note in full.
  Scenario: The settings page produces no JavaScript errors
    Given I am on "/admin/config/ai/figma"
    Then the "ai figma settings form" element should be visible
    And there should be no JavaScript errors

  # Opt-in: the Canvas AI settings surface belongs to canvas_ai, which is not a
  # hard dependency; on a provider-less / canvas_ai-less site it 404s a
  # sub-resource. Run with --tags @canvas-editor when canvas_ai is present.
  @canvas-editor
  Scenario: The Canvas AI assistant surface is reachable for the builder
    Given I am on "/admin/config/ai/canvas-ai-settings"
    Then the "drupal page heading" element should be visible
    And there should be no JavaScript errors

  # Opt-in: needs a Canvas page to exist on the target site (its editor is a
  # single-page app). Run with --tags @canvas-editor when a canvas_page is
  # present; the always-on scenarios above cover provider-less CI.
  @canvas-editor
  Scenario: The Canvas editor exposes the AI panel control and assistant input
    Given I am on "/canvas/editor/canvas_page/1"
    Then the "ai figma open ai panel" element should be visible within 30 seconds
    When I click the "ai figma open ai panel" element
    Then the "ai figma assistant input" element should be visible within 30 seconds
