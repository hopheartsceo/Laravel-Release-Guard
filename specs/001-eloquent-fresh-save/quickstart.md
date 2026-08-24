# Quickstart: Validate Eloquent Fresh Save DB005 Coverage

## Prerequisites

- Dependencies installed with Composer.
- Current branch: `feat/v0.2-eloquent-fresh-save`.
- Base compatibility reference available as commit `80bd17da2ef34b511689c287a13944b8da6ea1a7`.

## Test-First Validation Sequence

1. Add analyzer RED tests, analyzer baseline safety characterization tests, and the DB005 RED rule test before production changes:

```bash
vendor/bin/phpunit tests/Unit/Analysis/Application/EloquentUsageAnalyzerTest.php
vendor/bin/phpunit tests/Unit/Compatibility/Rules/RequiredColumnBreaksBaseWritesRuleTest.php
vendor/bin/phpunit tests/Unit/Compatibility/Rules/RequiredColumnBreaksBaseWritesInsertVariantsTest.php
```

Expected initial result: positive fresh-save analyzer tests fail for missing feature behavior, and the DB005 `eloquent_fresh_save` classification test fails because DB005 does not yet recognize that operation. Loaded, ambiguous, unsupported reassignment, future-evidence, unsafe control-flow, and exclusion safety tests pass on current v0.1 behavior where applicable; do not manufacture failures for these baseline safety tests.

2. Implement minimal analyzer and DB005 behavior, then rerun the focused unit tests:

```bash
vendor/bin/phpunit tests/Unit/Analysis/Application/EloquentUsageAnalyzerTest.php
vendor/bin/phpunit tests/Unit/Compatibility/Rules/RequiredColumnBreaksBaseWritesRuleTest.php
vendor/bin/phpunit tests/Unit/Compatibility/Rules/RequiredColumnBreaksBaseWritesInsertVariantsTest.php
```

Expected result: analyzer RED tests now pass because supported fresh `save()` emits `WriteUsage` with `operation: eloquent_fresh_save`, `columns: null`, and `reason: eloquent_fresh_save_semantics`; baseline safety tests continue passing because only ordered evidence before the `save()` is considered and loaded, ambiguous, unsupported reassignment, future-evidence, unsafe control-flow, and exclusion cases emit no fresh-save write usage; DB005 classifies fresh-save evidence as `WARNING` / `UNKNOWN`.

3. Add and run integration coverage:

```bash
vendor/bin/phpunit tests/Integration/EloquentCompatibilityScenariosTest.php
vendor/bin/phpunit tests/Integration/EloquentReleaseGuardAnalysisPipelineTest.php
```

Expected result: supported fresh-instance `save()` with a candidate required column produces DB005 `WARNING` / `UNKNOWN`; loaded-model and ambiguous-provenance saves do not produce fresh-save DB005 findings.

4. Verify public contracts and v0.1 corpus:

```bash
vendor/bin/phpunit tests/Feature/CheckReleaseCompatibilityCommandTest.php
vendor/bin/phpunit tests/Integration/V01ValidationCorpusTest.php
```

Expected result: fresh-save-only DB005 warnings exit `0`; JSON and console shape remain compatible; all 24 v0.1 validation corpus scenarios keep their pass, block, or unknown outcomes.

5. Run the complete suite:

```bash
composer test
```

Expected result: all tests pass. Before release-finalization, also run `composer validate --strict` and the supported Laravel 10-13 matrix.

## Required Scenario Coverage

- Fresh construction followed by direct `save()` emits fresh-save evidence.
- Literal constructor attributes plus `save()`.
- Empty constructor plus same-receiver property writes plus `save()`.
- Empty constructor plus same-receiver literal attribute-array writes plus `save()`.
- Constructor attributes plus additional supported assignments.
- Fresh construction followed by unsupported reassignment and then `save()` emits no fresh-save evidence.
- Loaded model plus `save()` does not become fresh insert evidence.
- Ambiguous provenance produces no fresh-save DB005 finding.
- `save()` before later fresh construction is not classified using future evidence.
- Fresh construction on an unsupported or conditional path with `save()` outside the safe straight-line evidence boundary emits no fresh-save evidence.
- Fresh construction plus supported same-receiver assignments plus `save()` in valid source order emits exactly the intended fresh-save evidence.
- Excluded `saveOrFail()`, `push()`, relationship, factory, helper, alias, dynamic model, DI, and container cases remain unsupported.
- DB005 fresh-save classification is `WARNING` / `UNKNOWN`.
- Fresh-save-only findings exit `0`.
- Existing Eloquent `create()` behavior is preserved.
- Query Builder write behavior is preserved.
- All 24 v0.1 validation corpus scenarios are preserved.

## Ordered Provenance Validation Notes

- Receiver state must be derived from one deterministic lexical/source-order event stream or traversal inside `EloquentUsageAnalyzer`.
- Do not validate this feature with independent `NodeFinder` collections whose separate discovery order is assumed to represent program order.
- Supported positive evidence is limited to a straight-line same-receiver sequence: direct `new KnownModel(...)`, optional supported direct assignments, then later direct `save()`.
- Do not propagate fresh state across conditional branches whose execution cannot be proven, loops, `try`/`catch`/`finally`, `match`/`switch` alternatives, ternary/conditional expressions, closures/callbacks, helper/function/method boundaries, or unsupported nested control flow.
