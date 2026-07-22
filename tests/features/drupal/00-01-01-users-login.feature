@ai-figma @admin
Feature: Login for every configured user
  As a site administrator
  I want every user defined in cucumber.shared.js worldParameters.users to be
  able to log in
  So that the suite has known-good fixtures for every role before any
  role-specific scenario runs

  Scenario: Webmaster can log in and provision the rest of the testing users
    Given I am a logged in user with the "Webmaster" user
    Then I the page should not have PHP errors
    When I add testing users
     And I navigate to "/admin/people"
    Then I should see "content_editor_user"
     And I should see "authenticated_user"
     And I the page should not have PHP errors

  Scenario: Content editor can log in
    Given I am a logged in user with the "Content editor" user
    Then I the page should not have PHP errors

  Scenario: Authenticated user can log in
    Given I am a logged in user with the "Authenticated user" user
    Then I the page should not have PHP errors
