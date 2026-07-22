@ai-figma @canvas
Feature: The Canvas AI assistant can see what the site ships, and decide what to reuse
  As a site builder using Drupal Canvas
  I want the assistant to resolve a design against the components, patterns,
  blocks and views this site already has
  So that it reuses what exists instead of inventing a component that is already
  sitting in the library

  # These scenarios assert that the decision tools are actually wired and that
  # the agent has been taught to use them. They read admin pages only: nothing
  # here calls an LLM, so they pass in CI with no provider key configured.
  #
  # They sat outside the default lane until 2026-07-19 — which is exactly why
  # nobody caught the assistant answering without calling these tools. Keep them
  # in the default lane.
  #
  # Version note: the agent edit form and the context item list both moved path in
  # later AI Agents / AI Context releases. As of AI Agents 1.3.x the agent base
  # path is "/admin/config/ai/tools-automation/agents" (it used to be
  # "/admin/config/ai/agents"), so the edit form is
  # "/admin/config/ai/tools-automation/agents/{ai_agent}/edit/form". The AI
  # Context item collection is "/admin/config/ai/context/items". These paths
  # match the version this module is tested against; move them together if that
  # dependency moves.

  Background:
    Given I am a logged in user with the "Webmaster" user

  Scenario: The assistant is given the tool that shows it everything reusable
    Given I am on "/admin/config/ai/tools-automation/agents/canvas_ai_orchestrator/edit/form"
    Then I should see "See what we already have"
    And I the page should not have PHP errors

  Scenario: The assistant is given the tool that decides reuse, adapt or create
    Given I am on "/admin/config/ai/tools-automation/agents/canvas_ai_orchestrator/edit/form"
    Then I should see "Decide what to reuse and what to build"
    And I the page should not have PHP errors

  # The tools are named for the job they do, not for the code behind them, so a
  # project manager or a client can open this page and understand what the
  # assistant is allowed to do on their site.
  Scenario: The assistant's tools are named in plain language
    Given I am on "/admin/config/ai/tools-automation/agents/canvas_ai_orchestrator/edit/form"
    Then I should see "Read the design"
    And I should see "Build the page"
    And I should see "Save a section so it can be reused"
    And I should see "Check a page for problems"
    And I the page should not have PHP errors

  # Choosing the right thing is only half of a faithful build; the agent also has
  # to be told to choose before it builds. That instruction is an AI Context item
  # shipped in config, so it can be read and edited by an administrator.
  Scenario: The agent is taught to resolve a design before it builds anything
    Given I am on "/admin/config/ai/context/items"
    Then I should see "Figma Design Resolution Protocol"
    And I the page should not have PHP errors
