# LIVE CANVAS-AI DEMO SCENARIO - opt-in, NOT part of the green CI lane.
#
# The LONG build: it asks the AI assistant to BUILD a Figma design section onto
# the page, reusing matching components. A full build streams for 60-140s and
# is the most provider-dependent of the four. Excluded from the default lane
# (cucumber.js: `not @demo and not @canvas-editor`). Run it with:
#
#   npx cucumber-js --config cucumber.js --tags @demo
#
# RESEND NOTE: a long build occasionally aborts an in-flight request and the
# panel shows a transient "Failed to fetch". That is a cancelled request, not a
# module bug - simply re-send the same prompt (or re-run this scenario) and it
# completes. The scenario asserts only stable, observable facts.
#
# TIMEOUTS: the suite-wide 45s cucumber timeout (cucumber.js) is untouched; the
# long wait is bounded by the custom polling step's own per-step timeout
# (DEMO_STEP_TIMEOUT, 200s) in ai-figma.steps.js. Raise the `within N seconds`
# number below if your provider is slower.

@ai-figma @demo @slow @canvas-editor
Feature: AI Figma demo - build a Figma design section onto the page
  As a site builder using Drupal Canvas
  I want the AI assistant to build a Figma section using my existing components
  So that the page is assembled from the real component library, not throwaway markup

  # Empty "AI Figma Demo" page (id 8) is the build target.
  Background:
    Given I am a logged in user with the "Webmaster" user
    And I am on "/canvas/editor/canvas_page/8"
    And the "ai figma open ai panel" element should be visible within 30 seconds
    When I click the "ai figma open ai panel" element
    Then the "ai figma assistant input" element should be visible within 30 seconds

  # Firm assertions: a response streamed into the panel, and no fatal error
  # dialog. The change-signal (placeholder gone) is the tolerant editor fact -
  # the empty-region placeholder copy can differ between Canvas releases, so if
  # it ever reads differently, swap it for the "review/changes" top-bar check
  # via the "ai figma editor review changes bar" named selector.
  Scenario: The assistant builds the section and the page is no longer empty
    When I fill in the "ai figma assistant input" element with:
      """
      Build this Figma design section on the page, reusing the matching existing Canvas components wherever you can and only creating new components when there is no match. @https://www.figma.com/design/RJkuWNHla1P8VYHa5z6dnL/VB---Approved-Stylesheet?node-id=9668-2799
      """
    And I press the key "Enter"
    Then the "ai figma assistant response" element should not be empty within 200 seconds
    And the "ai figma editor error dialog" element should have a count of 0
    And I should not see "Place items here"
