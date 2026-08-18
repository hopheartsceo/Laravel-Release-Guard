# Changelog

All notable changes to Laravel Release Guard will be documented in this file.

The project follows Semantic Versioning.

## [0.1.0] - 2026-08-18

### Added

- Initial public release of Laravel Release Guard.
- Static database compatibility analysis for Laravel rolling and zero-downtime deployments.
- Git-based comparison between a previous application revision and candidate working-tree migrations.
- `DB001` — dropped columns still referenced by the previous application.
- `DB002` — renamed columns still referenced by the previous application.
- `DB003` — dropped tables still referenced by the previous application.
- `DB004` — renamed tables still referenced by the previous application.
- `DB005` — newly required columns that supported previous write paths cannot satisfy.
- `DB006` — migration operations that cannot be analyzed safely.
- Static analysis for supported Laravel Query Builder and Eloquent database usage patterns.
- Conservative handling of Eloquent model creation semantics and dynamic database usage.
- `DEFINITE`, `PROBABLE`, and `UNKNOWN` confidence levels.
- `BLOCKER`, `WARNING`, and `INFO` severity levels.
- Console and JSON output formats.
- CI-oriented exit codes:
  - `0` when no definite incompatibility is detected within the analyzed scope.
  - `1` when at least one definite blocker is detected.
  - `2` for CLI, configuration, Git, or analyzer errors.
- Safe Git revision verification, including dash-prefixed revision handling.
- Repository-bound working-tree file access with traversal, absolute-path, and symlink-escape protection.
- Concise Git error reporting that avoids exposing raw process command output.
- Configuration for application paths, migration paths, and individual database rules.
- Laravel package auto-discovery.
- Support for PHP 8.1+ and Laravel 10, 11, 12, and 13.

### Validation

- 24 canonical v0.1 database compatibility scenarios.
- 199 automated tests.
- 673 assertions.
- CI coverage across Laravel 10, 11, 12, and 13.

### Scope

v0.1 focuses specifically on static database compatibility during rolling and zero-downtime Laravel deployments.

It does not claim to prove universal deployment safety and does not execute migrations or application code.

The project intentionally prefers `UNKNOWN` over invented certainty.
