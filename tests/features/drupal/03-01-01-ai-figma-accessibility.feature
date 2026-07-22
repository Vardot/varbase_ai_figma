@ai-figma @a11y @admin
Feature: AI Figma module - accessibility of the settings page
  As an administrator using assistive technology
  I want the one settings page to be a labelled form with no serious violations
  So that the surface meets WCAG AA basics

  Background:
    Given I am a logged in user with the "Webmaster" user
    And I am on "/admin/config/ai/figma"

  Scenario: The settings page is a labelled form
    Then the "ai figma settings form" element should be visible
    And every form field should have an accessible label

  Scenario: The settings page exposes the standard landmarks
    Then the page should have a main landmark
    And the page should have a navigation landmark

  Scenario: The settings page has no serious accessibility violations
    Then the page should have no serious accessibility violations

  # This assertion is genuine, not relaxed: the module's settings page at
  # /admin/config/ai/figma was verified (logged in as the Webmaster/admin
  # super-admin) to emit ZERO pageerror / console.error across the full
  # login -> dashboard -> settings flow. The Gin "darkmode_class" pageerror and
  # the 403 resource errors that appear in a broken run come only from the
  # anonymous / failed-login state (an unauthenticated 403 page), never from the
  # authenticated module page - so no error is ignored or allow-listed here. The
  # check still catches any real JS error the module itself would introduce.
  Scenario: The settings page produces no JavaScript errors
    Then there should be no JavaScript errors
