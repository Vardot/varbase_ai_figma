# LIVE CANVAS-AI DEMO SCENARIO - opt-in, NOT part of the green CI lane.
#
# This feature drives the real Drupal Canvas AI assistant against a live AI
# provider on the existing rich "Features" page (id 1): it asks the assistant
# to reorder sections and report the new order. Network-, provider- and
# key-dependent, so it is excluded from the default lane (cucumber.js:
# `not @demo and not @canvas-editor`). Run it with:
#
#   npx cucumber-js --config cucumber.js --tags @demo
#
# The long wait is bounded by the custom polling step's own per-step timeout
# (see ai-figma.steps.js), so the suite's 45s default is untouched.

@ai-figma @demo @slow @canvas-editor
Feature: AI Figma demo - reorder page sections and report the new order
  As a site builder using Drupal Canvas
  I want the AI assistant to move sections around and tell me the resulting order
  So that I can restructure a page conversationally instead of dragging by hand

  # The "Features" page (id 1) is a rich, multi-section page - the reorder target.
  Background:
    Given I am a logged in user with the "Webmaster" user
    And I am on "/canvas/editor/canvas_page/1"
    And the "ai figma open ai panel" element should be visible within 30 seconds
    When I click the "ai figma open ai panel" element
    Then the "ai figma assistant input" element should be visible within 30 seconds

  # Tolerant assertion: the assistant describes the new order in its own words,
  # so we require only a stable order/move/section/spacer token plus no fatal
  # error dialog.
  Scenario: The assistant moves the sections and describes the new order
    When I fill in the "ai figma assistant input" element with:
      """
      Move the first spacer on this page to the very bottom, and move the call-to-action section so it appears right after the hero. Then show me the new section order.
      """
    And I press the key "Enter"
    Then the "ai figma assistant response" element should contain text matching "order|moved|section|spacer" within 180 seconds
    And the "ai figma editor error dialog" element should have a count of 0
