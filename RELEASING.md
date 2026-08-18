# Releasing Laravel Release Guard

This document defines the release process for Laravel Release Guard.

## Release Principles

- Never tag a release from an unverified working tree.
- Never publish a release while CI is failing.
- Release notes must match implemented behavior.
- Do not claim universal deployment safety.
- Preserve the analyzer principle: **Prefer UNKNOWN to invented certainty.**
- The exact successful-analysis wording is:
  `No incompatible changes detected within the analyzed scope.`

## v0.1.0 Release Checklist

### 1. Repository State

- [ ] `master` is clean and synchronized with `origin/master`.
- [ ] All release-readiness documentation is merged into `master`.
- [ ] No temporary release branches remain after merge.
- [ ] `composer validate --strict` passes.
- [ ] `composer test` passes.
- [ ] `git diff --check` passes.

### 2. Compatibility Matrix

Confirm GitHub Actions passes for:

- [ ] PHP 8.1 / Laravel 10
- [ ] PHP 8.2 / Laravel 11
- [ ] PHP 8.4 / Laravel 12
- [ ] PHP 8.3 / Laravel 13

### 3. Documentation

- [ ] README installation command is correct.
- [ ] README command examples match the actual Artisan signature.
- [ ] README documents console and JSON output.
- [ ] README documents exit codes `0`, `1`, and `2`.
- [ ] README documents `DB001` through `DB006`.
- [ ] README explains `DEFINITE`, `PROBABLE`, and `UNKNOWN`.
- [ ] README explains v0.1 limitations.
- [ ] CHANGELOG contains the final `0.1.0` release entry.
- [ ] License and package metadata are correct.

### 4. Package Registry Preparation — Blocking

Before tagging the release:

- [ ] Register `hopheartsceo/laravel-release-guard` on Packagist.
- [ ] Confirm Packagist reads the repository metadata successfully.
- [ ] Configure automatic Packagist updates from GitHub if available.
- [ ] Confirm the package is visible under the exact Composer name.

A stable `^0.1` installation cannot be verified until the `v0.1.0` tag exists. That check belongs to post-release verification below.

### 5. Pre-Tag Verification

From a clean `master`:

```bash
composer validate --strict
composer test
git diff --check
git status --short
```

Expected:

- Composer metadata is valid.
- The complete test suite passes.
- No whitespace errors are reported.
- The working tree is clean.

Confirm the commit that will be tagged:

```bash
git rev-parse HEAD
```

### 6. Tag

Create an annotated semantic-version tag:

```bash
git tag -a v0.1.0 -m "Laravel Release Guard v0.1.0"
git push origin v0.1.0
```

Do not move or recreate the tag after publication.

### 7. GitHub Release

Create a GitHub release from `v0.1.0`.

Suggested title:

```text
v0.1.0 — Database Rolling-Deployment Compatibility
```

The release notes should summarize:

- Database compatibility analysis for rolling / zero-downtime deployments.
- Rules `DB001` through `DB006`.
- Conservative `UNKNOWN` handling.
- Console and JSON output.
- Exit-code contract.
- Laravel 10–13 support.
- Git and working-tree safety boundaries.

### 8. Post-Release Verification

- [ ] GitHub release points to the expected tag and commit.
- [ ] GitHub Actions is green for the tagged code.
- [ ] Packagist shows `v0.1.0`.
- [ ] Clean Composer installation succeeds using:

```bash
composer require --dev hopheartsceo/laravel-release-guard:^0.1
```

- [ ] `php artisan release-guard:check --help` works.
- [ ] A safe fixture exits `0`.
- [ ] A definite blocker fixture exits `1`.
- [ ] An invalid revision exits `2`.
- [ ] JSON output parses successfully.

### 9. Cleanup

- [ ] Delete the merged release-readiness branch locally.
- [ ] Delete the merged release-readiness branch remotely.
- [ ] Fetch with prune.
- [ ] Confirm local `master` tracks `origin/master`.

## Release Stop Conditions

Stop the release immediately if any of the following occurs:

- CI fails on a supported Laravel version.
- Composer validation fails.
- The package cannot be installed from Packagist.
- The tag points to the wrong commit.
- Exit-code behavior differs from the documented contract.
- A known false `DEFINITE` blocker remains unresolved.
- A known definite incompatibility is incorrectly reported as safe within supported scope.
