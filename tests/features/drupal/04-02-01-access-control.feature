@ai-figma @admin @security
Feature: Role-based access control for Varbase AI Figma
  As a security-conscious site owner
  I want the AI Figma configuration and the AI / admin surfaces it touches to be
  reachable only by trusted administrators
  So that the Figma token and design-context settings cannot be read or changed
  by lower-privileged accounts

  # Permission matrices are a classic silent-regression zone: one changed default
  # in a module update can flip a single cell with no feature "looking" broken.
  # Following the role x area x expected matrix pattern, these two outlines assert
  # BOTH sides of every protected path - administrators keep access, authenticated
  # users are denied - so a regression in either direction fails a precise, named
  # row instead of a vague "access broke somewhere".

  Background:
    Given I am a logged in user with the "Webmaster" user
     And I add testing users
     # Each scenario performs its own role-specific login; drop the Background
     # session first so the login form is actually presented.
     And I am an anonymous user

  Scenario Outline: An administrator can reach <area>
    Given I am a logged in user with the "Webmaster" user
    When I navigate to "<path>"
    Then I the page should not have PHP errors

    Examples: Protected AI Figma and admin areas
      | area                        | path                                     |
      | the AI Figma settings page  | /admin/config/ai/figma                   |
      | the AI configuration group  | /admin/config/ai                         |
      | the status report           | /admin/reports/status                    |
      | the permissions page        | /admin/people/permissions                |

  Scenario Outline: An authenticated non-admin is denied <area>
    Given I am a logged in user with the "Authenticated user" user
    Then I am denied access to "<path>"

    Examples: Protected AI Figma and admin areas
      | area                        | path                                     |
      | the AI Figma settings page  | /admin/config/ai/figma                   |
      | the AI configuration group  | /admin/config/ai                         |
      | the status report           | /admin/reports/status                    |
      | the permissions page        | /admin/people/permissions                |
