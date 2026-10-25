# AGENTS.md

Guidance for AI coding agents working on the Varbase AI Figma module.

## Before you start

Read the project history and context first:

- **Documentation** lives on the Varbase docs site: [docs.varbase.vardot.com](https://docs.varbase.vardot.com/developers/understanding-varbase/varbase-ai-recipes/varbase-ai-figma-base). Keep `README.md` short and link there; add no `docs/` folder.
- **`CHANGELOG.md`** — what changed in each release, newest first. Read it before
  proposing changes so you follow the established direction and versioning.
- **Merge request comments and history** on
  [git.drupalcode.org/project/varbase_ai_figma](https://git.drupalcode.org/project/varbase_ai_figma/-/merge_requests)
  — the reasoning behind recent changes lives in the MR discussions.
- **Issue comments** on
  [drupal.org/project/issues/varbase_ai_figma](https://www.drupal.org/project/issues/varbase_ai_figma)
  — the Problem/Motivation and Proposed resolution for each change.

## When you make a change

- Add an entry under `## [Unreleased]` in `CHANGELOG.md`.
- Keep commit messages in the Drupal commit-type format:
  `{type}: #{issueID} Summary` (see https://www.drupal.org/node/3586390).
- Never bump the version or tag a release without explicit maintainer approval.
- This is a Drupal module; keep its default configuration in `config/install` with
  matching `config/schema`, and keep the Figma REST API calls server-side in `src/`.
