# LIVE CANVAS-AI DEMO SCENARIO - opt-in, NOT part of the green CI lane.
#
# The reliable, fast demo: it asks the AI assistant to MATCH a Figma section to
# the existing Canvas component library (reuse / extend / create, with a match
# score) - a read-and-reason answer, so it returns far quicker than a full
# build. Still a live AI provider call, so it is excluded from the default lane
# (cucumber.js: `not @demo and not @canvas-editor`). Run it with:
#
#   npx cucumber-js --config cucumber.js --tags @demo
#
# Network/provider dependent. The long wait is bounded inside the custom
# polling step's own per-step timeout (see ai-figma.steps.js), so the suite's
# 45s default is untouched.

@ai-figma @demo @canvas-editor
Feature: AI Figma demo - match a Figma section to existing Canvas components
  As a site builder using Drupal Canvas
  I want the AI assistant to compare a Figma section against my component library
  So that I know what to reuse, extend, or create before any page is built

  Background:
    Given I am a logged in user with the "Webmaster" user
    And I am on "/canvas/editor/canvas_page/8"
    And the "ai figma open ai panel" element should be visible within 30 seconds
    When I click the "ai figma open ai panel" element
    Then the "ai figma assistant input" element should be visible within 30 seconds

  # Tolerant assertion: the assistant's exact phrasing varies, so we only
  # require a stable matching/reuse/score token in the response, plus no fatal
  # error dialog.
  Scenario: The assistant reports how the Figma section maps to existing components
    When I fill in the "ai figma assistant input" element with:
      """
      Match this Figma section to my existing Canvas components and tell me, for each part, whether to reuse, extend, or create a new component - with a match score for each. @https://www.figma.com/design/RJkuWNHla1P8VYHa5z6dnL/VB---Approved-Stylesheet?node-id=9668-2799
      """
    And I press the key "Enter"
    Then the "ai figma assistant response" element should contain text matching "match|reuse|component|score" within 120 seconds
    And the "ai figma editor error dialog" element should have a count of 0
