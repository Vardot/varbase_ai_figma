@ai-figma @admin
Feature: AI Figma module - the single configuration page
  As a site builder
  I want one settings page to point the module at my Figma token and file
  So that the Canvas AI assistant can read my designs - with no extra admin pages

  # AI Figma ships exactly one config page at /admin/config/ai/figma: the
  # Figma token (Key), the default file key, the API base URL and a
  # "Test Figma connection" action. Everything else runs through the Drupal
  # Canvas AI assistant, so there is deliberately NO builder, nodes or layout
  # admin page. The named selectors live in tests/selectors/ai-figma.json.

  Background:
    Given I am a logged in user with the "Webmaster" user
    And I am on "/admin/config/ai/figma"

  # "no module errors" is asserted via the form's own invalid-field signal
  # (a field flagged aria-invalid="true"), NOT the page-level ".messages--error"
  # block: Drupal merges every same-type message into a single error container,
  # and uid 1 always carries the standing global "security updates available"
  # admin nag there - an environmental message, never an AI Figma error. The
  # form-scoped signal is 0 when the module's form is healthy and 1 on a real
  # validation failure, so it tests the module, not the site's update status.
  Scenario: The settings form is reachable for administrators
    Then the "ai figma settings form" element should be visible
    And the "drupal page heading" element should contain text "AI Figma"
    And the "ai figma settings form errors" element should have a count of 0

  Scenario: The page exposes the Figma token Key, default file and API base fields
    Then the "ai figma settings figma token key" element should be visible
    And the "ai figma settings default file key" element should be visible
    And the "ai figma settings api base" element should be visible
    And I should see a "Figma token" field
    And I should see a "Default Figma file key" field
    And I should see a "Figma API base URL" field

  Scenario: The page exposes a Test Figma connection action
    Then the "ai figma test connection button" element should be visible
    And I should see the button "Test Figma connection"

  Scenario: The page exposes a Save configuration action
    Then I should see the button "Save configuration"

  Scenario: There is no separate layout admin page
    Given I am on "/admin/config/ai/figma/layout"
    Then the "ai figma settings form" element should have a count of 0
    And I should not see "Test Figma connection"

  Scenario: There is no separate builder admin page
    Given I am on "/admin/config/ai/figma/figma-to-drupal"
    Then the "ai figma settings form" element should have a count of 0

  Scenario: The settings link is discoverable from the AI configuration section
    Given I am on "/admin/config/ai"
    Then the "ai figma admin services link" element should have a count of 1

  # See the note above: the success status message proves the save round-trip,
  # and the form-scoped invalid-field count (0) proves the save raised no
  # validation error - without tripping over the global security-update nag.
  Scenario: Saving the form persists the configuration with no errors
    Given I enable only the default AI Figma settings
    Then the "drupal admin status messages" element should contain text "The configuration options have been saved."
    And the "ai figma settings form errors" element should have a count of 0
