# Changelog

All notable changes to the Varbase AI Figma module are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0-rc1] - 2026-08-15
### Changed
- Release the module with the Varbase 11.0.0-rc1 suite. No functional changes since 1.0.0-beta1.
- Update the version badge to `1.0.0-rc1` in `README.md`.

## [1.0.0-beta1] - 2026-08-15
### Changed
- Switch the Varbase functional testing suite to Varbase E2E (Playwright + Cucumber-js) and update `@vardot/varbase-e2e` to the latest 2.x.
- Add the default `.gitlab` and `.github` issue and merge request templates.
- Document the module with a `CHANGELOG.md`, an `AGENTS.md`, and the Varbase pipeline, release, and Automated Functional Testing badges in the README.

## [1.0.0-alpha2] - 2026-07-26
### Fixed
- Raise the AI Context global items cap before seeding so context items are never silently dropped.

## [1.0.0-alpha1] - 2026-07-22
### Added
- Initial release of the Varbase AI Figma module: turns a Figma design into a Drupal Canvas page built from the site's own components, both from the **Figma to Drupal** admin form and as an AI Agent tool the Canvas AI assistant can call, talking to the Figma REST API from server-side PHP.

### Fixed
- Fix `page_edit`'s stale component classifier and add a provider fallback to `AiAssistant`.

[Unreleased]: https://git.drupalcode.org/project/varbase_ai_figma/-/compare/1.0.0-rc1...1.0.x
[1.0.0-rc1]: https://git.drupalcode.org/project/varbase_ai_figma/-/compare/1.0.0-beta1...1.0.0-rc1
[1.0.0-beta1]: https://git.drupalcode.org/project/varbase_ai_figma/-/compare/1.0.0-alpha2...1.0.0-beta1
[1.0.0-alpha2]: https://git.drupalcode.org/project/varbase_ai_figma/-/compare/1.0.0-alpha1...1.0.0-alpha2
[1.0.0-alpha1]: https://git.drupalcode.org/project/varbase_ai_figma/-/tags/1.0.0-alpha1
