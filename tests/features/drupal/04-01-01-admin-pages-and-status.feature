@ai-figma @admin
Feature: Varbase AI Figma module admin pages load correctly
  As a site administrator
  I want the module admin surfaces and the status report to be reachable
  So that I can confirm Varbase AI Figma is correctly installed and raises no
  PHP errors

  # Varbase AI Figma owns no engine of its own: it customizes the general
  # ai_figma module for the Bootstrap 5.3 vartheme_bs5 theme and reuses the one
  # ai_figma.settings page at /admin/config/ai/figma. These scenarios assert the
  # shared admin surfaces load cleanly with both modules enabled.

  Background:
    Given I am a logged in user with the "Webmaster" user

  Scenario: Both modules are enabled on the modules page
    When I navigate to "/admin/modules"
    Then I should see "AI Figma"
     And I should see "Varbase AI Figma"
     And I the page should not have PHP errors

  Scenario: The shared AI Figma settings page is reachable
    When I navigate to "/admin/config/ai/figma"
    Then the "ai figma settings form" element should be visible
     And the "drupal page heading" element should contain text "AI Figma"
     And I the page should not have PHP errors

  Scenario: The status report lists the AI Figma requirement with no PHP errors
    When I navigate to "/admin/reports/status"
    Then the "drupal page heading" element should contain text "Status report"
     And I should see "AI Figma"
     And I the page should not have PHP errors

  # Deliberately asserts the stable AI configuration group, not the agents
  # listing: that listing's path moved between AI Agents versions, so pinning it
  # tested the dependency's URL rather than this module's behaviour.
  Scenario: The AI configuration group loads with no PHP errors
    When I navigate to "/admin/config/ai"
    Then the "drupal page heading" element should be visible
     And I the page should not have PHP errors
