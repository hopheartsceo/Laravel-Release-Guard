# Implementation Plan: Eloquent Fresh Save DB005 Coverage

**Branch**: `feat/v0.2-eloquent-fresh-save` | **Date**: 2026-08-24 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/001-eloquent-fresh-save/spec.md`

## Summary

Broaden DB005 coverage so supported fresh-instance Eloquent `save()` paths are recognized as possible inserts when the candidate release adds a required column without a database default. The implementation will use the existing model index, PHP parser, application snapshot, and DB005 rule model. The chosen architecture is a minimal extension of `EloquentUsageAnalyzer` with focused private instance-provenance logic, producing `WriteUsage` evidence for recognized fresh `save()` calls. Fresh `save()` evidence will be represented with an Eloquent-specific operation and `columns: null` so DB005 classifies it as `WARNING` / `UNKNOWN`, never as the Query Builder known-column `insert` path.

## Technical Context

**Language/Version**: PHP 8.1+ package code, constrained by `composer.json`; supports Laravel 10, 11, 12, and 13 through Illuminate components.

**Primary Dependencies**: `nikic/php-parser` v5 for static AST analysis, Illuminate Console/Filesystem/Support, Symfony Process, PHPUnit/Testbench for tests.

**Storage**: N/A. The analyzer is static and reads Git/base/candidate source files; it must not connect to or mutate a database.

**Testing**: PHPUnit via `composer test`; focused unit tests for analyzers and rules, integration tests around `ReleaseGuardAnalysisPipeline`, feature tests for CLI exit/output contracts where public behavior is affected.

**Target Platform**: Composer library and Laravel console package intended for CI usage on PHP versions compatible with Laravel 10-13.

**Project Type**: Library/CLI package with static analysis pipeline.

**Performance Goals**: Preserve existing file-by-file AST analysis behavior. The new scan should remain linear in parsed AST size for each source file and avoid cross-file data-flow or runtime bootstrapping.

**Constraints**: Planning-only turn; no production/test/config/README/CHANGELOG/composer/constitution changes. Eventual implementation must not execute Laravel code, migrations, model events, database queries, or the old app revision.

**Scale/Scope**: Narrow v0.2 feature: direct construction of a known model into one local receiver, supported same-receiver assignments, and later `save()` on that same receiver within a deterministic source-order evidence boundary. All 24 v0.1 validation corpus scenarios remain protected.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Evidence Before Certainty**: PASS. Fresh `save()` cannot prove final persisted columns because Eloquent runtime behavior may alter inserts, and fresh provenance is accepted only when ordered static evidence appears before the `save()`, so DB005 must emit `WARNING` / `UNKNOWN`, not `BLOCKER` / `DEFINITE`.
- **v0.1 Compatibility Baseline**: PASS. Plan preserves the v0.1.0 baseline at `80bd17da2ef34b511689c287a13944b8da6ea1a7`, including DB001-DB006 codes, JSON shape, console output, config, exit-code meanings, Query Builder behavior, and existing Eloquent `create()` behavior.
- **Test-First Compatibility Rules**: PASS. Implementation phases begin with failing analyzer/domain tests before production changes, including source-order, reassignment, loaded, ambiguous, and boundary-negative cases, then DB005 integration and public-contract regression tests.
- **Public Contracts Are Stable**: PASS. No public output, JSON, configuration, severity/confidence vocabulary, package metadata, or exit-code changes are planned.
- **Narrow Static Analysis**: PASS. The design reuses existing parser, model index, application snapshot, pipeline, and DB005 rule; it requires one ordered same-file receiver-provenance pass rather than broad data-flow or CFG analysis, and excludes aliases, helper-mediated behavior, arbitrary control-flow inference, DI/container resolution, dynamic model classes, factories, relationship saves, `saveOrFail()`, and `push()`.

## Project Structure

### Documentation (this feature)

```text
specs/001-eloquent-fresh-save/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── public-analysis-contract.md
└── tasks.md             # Phase 2 output, not created by speckit-plan
```

### Source Code (repository root)

```text
src/
├── Analysis/
│   ├── Application/
│   │   ├── EloquentUsageAnalyzer.php
│   │   └── ModelIndexService.php
│   └── Release/
│       └── ReleaseGuardAnalysisPipeline.php
├── Compatibility/
│   └── Rules/
│       └── RequiredColumnBreaksBaseWritesRule.php
├── Console/
│   ├── CheckReleaseCompatibilityCommand.php
│   └── Rendering/
└── Domain/
    └── Application/
        └── WriteUsage.php

tests/
├── Unit/
│   ├── Analysis/Application/
│   │   └── EloquentUsageAnalyzerTest.php
│   └── Compatibility/Rules/
│       ├── RequiredColumnBreaksBaseWritesRuleTest.php
│       └── RequiredColumnBreaksBaseWritesInsertVariantsTest.php
├── Integration/
│   ├── EloquentCompatibilityScenariosTest.php
│   ├── EloquentReleaseGuardAnalysisPipelineTest.php
│   └── V01ValidationCorpusTest.php
└── Feature/
    └── CheckReleaseCompatibilityCommandTest.php
```

**Structure Decision**: Keep the feature inside the existing application-analysis and DB005 compatibility-rule structure. Do not introduce a new top-level analyzer pipeline unless implementation discovers that `EloquentUsageAnalyzer` cannot remain cohesive with private helper extraction.

## Architecture Decision

**Chosen**: Extend `EloquentUsageAnalyzer` with focused same-file, same-local-receiver instance-write analysis.

Rationale:

- Cohesion: The analyzer already maps known Eloquent models to `ColumnUsage`, `TableUsage`, and `WriteUsage`, and already owns the `MODEL_CREATE_METHODS` conservative-write semantics.
- Pipeline fit: `ReleaseGuardAnalysisPipeline` already builds `ModelIndex` once and runs `EloquentUsageAnalyzer` per base application file, so no new pipeline wiring or service-provider contract is required for the narrow scope.
- False-positive risk: Keeping the logic inside the Eloquent analyzer allows instance `save()` recognition to reuse the existing known-model/table checks and avoid treating generic method calls as ORM writes.
- Testability: Existing `EloquentUsageAnalyzerTest` can start with failing unit cases for recognized, loaded, ambiguous, and excluded `save()` patterns. Existing DB005 rule tests can pin the intended severity/confidence branch.
- Avoiding unnecessary architecture: A separate public analyzer would add constructor dependencies, pipeline orchestration, and service-provider considerations without a distinct domain boundary for this feature.

**Rejected**: Introduce a focused `EloquentInstanceWriteAnalyzer`.

Reason rejected:

- It would reduce file size pressure in `EloquentUsageAnalyzer`, but the first implementation would still need the same `ModelIndex`, AST parser, local name resolution assumptions, `WriteUsage` emission, and DB005 rule vocabulary.
- It would create a second Eloquent analysis entry point for one narrow behavior, increasing wiring and test surface before there is evidence that instance-write analysis will grow beyond `save()`.
- It risks duplicating model resolution and usage-emission conventions currently centralized in `EloquentUsageAnalyzer`.

Implementation may extract private helper methods or small private value structures inside `EloquentUsageAnalyzer`. A new public analyzer class should be deferred until a later feature requires multiple instance-write operations or broader provenance.

## Ordered Provenance Strategy

`EloquentUsageAnalyzer` must derive receiver state from a deterministic lexical/source-order view of relevant AST events. The implementation must not independently collect `New_`, `Assign`, `StaticCall`, `MethodCall`, property-write, or array-write nodes through unrelated `NodeFinder` passes and then assume each pass's discovery order is program order.

The eventual implementation may choose the exact mechanism, but it must provide one narrow ordered instance-provenance pass or helper inside `EloquentUsageAnalyzer`. Acceptable implementation shapes include:

- a single traversal that visits relevant statements and receiver events in parser order;
- a helper that flattens relevant same-scope AST events into a stable source-order stream before applying receiver state transitions.

For this feature, a `save()` observes only receiver evidence that appears earlier in the supported ordered scope. Later evidence must never affect an earlier `save()`. In particular, a later `new KnownModel(...)` assignment must not retroactively classify an earlier `$receiver->save()` as fresh.

## Static Evidence Boundary

Supported fresh evidence:

- A direct `new KnownModel([...])` expression assigned to a local variable receiver.
- Constructor payload is a literal associative attribute array with literal string keys, or there is no constructor payload.
- Supported assignments on the same local receiver before `save()`:
  - direct property writes such as `$user->email = $email`;
  - literal attribute-array writes such as `$user['email'] = $email`.
- A later direct `$sameReceiver->save()` call in the same parsed source file.
- Constructor attributes plus additional supported same-receiver assignments may be recognized, but persisted columns remain unknown for DB005.
- Evidence must occur in that order for the same local receiver inside the supported straight-line boundary.

Unsupported evidence:

- Receiver aliases or cross-variable tracking.
- Arbitrary control-flow inference across branches, loops, closures, functions, or methods.
- Helper-mediated construction or assignment.
- Method-based mass assignment such as `fill()`, `setAttribute()`, or `forceFill()`.
- Dependency injection, container resolution, dynamic model classes, factories, relationship saves, `saveOrFail()`, `push()`, and general ORM/data-flow analysis.
- Fresh construction on a conditional, looped, exception, alternative, closure, callback, helper, function, method, or otherwise unsupported path when the later `save()` is outside that proven straight-line path.

Ambiguous or unsupported provenance produces no fresh-save DB005 finding unless another existing v0.1 rule independently justifies a finding.

The supported positive feature is deliberately straight-line: direct `new KnownModel(...)`, optional supported direct assignments on the same receiver, then direct `save()` on that receiver. Do not infer fresh state across conditional branches whose execution cannot be proven, loops, `try`/`catch`/`finally`, `match`/`switch` alternatives, ternary/conditional expressions, closures/callbacks, helper/function/method boundaries, or unsupported nested control flow. When the relevant ordering or path is not statically safe within this boundary, mark the receiver ambiguous for this feature and emit no fresh-save DB005 evidence. Do not build a CFG for this feature.

## Internal Representation

Recognized fresh `save()` evidence should emit:

```text
WriteUsage(
    table: known model table,
    operation: 'eloquent_fresh_save',
    columns: null,
    reason: 'eloquent_fresh_save_semantics',
    file: source file,
    line: save() line
)
```

This reuses the existing `WriteUsage` vocabulary because the domain already represents "a write path to a table whose final columns may be unknown" via `columns: null` and `reason`. A new domain object is not necessary unless implementation needs to expose richer evidence in public output, which this feature does not require.

DB005 must add `'eloquent_fresh_save'` to its Eloquent-uncertain insert operation set, not to its definite Query Builder insert set. This prevents accidental `BLOCKER` / `DEFINITE` classification because DB005's definite branch only runs for known-column `insert` and `insertGetId` operations after `columns !== null` and the added required column is absent.

## Loaded-Model Protection

Do not treat every `save()` as insert evidence. Track only a narrow local receiver state for supported patterns:

- `fresh`: local receiver assigned directly from `new KnownModel(...)`.
- `loaded`: local receiver assigned from supported existing-record retrieval/update roots such as `KnownModel::find(...)`, `findOrFail(...)`, `first()`, `firstOrFail()`, `sole()`, or `KnownModel::query()->...->first()/find()/sole()` chains.
- `ambiguous`: any other origin, alias, dynamic class, helper return, relationship lookup, factory, container/DI source, unsupported method chain, or unsupported reassignment.

Only `fresh` receivers may emit `eloquent_fresh_save` on `$receiver->save()`. `loaded` and `ambiguous` receivers must emit no fresh-save write. Reassignment of a tracked receiver to an unsupported expression should downgrade it to `ambiguous` rather than preserve stale freshness.

This is intentionally not a universal provenance engine; it is a small receiver-state table scoped to each parsed file and limited to assignments and method calls whose AST forms are explicitly supported.

The receiver-state table must be updated in source order with these semantics:

- `new KnownModel(...) -> fresh`.
- Supported same-receiver assignment while fresh stays `fresh`.
- Unsupported reassignment after fresh construction becomes `ambiguous`.
- Supported loaded retrieval becomes `loaded`.
- Supported same-receiver assignment while loaded stays `loaded`.
- Unsupported reassignment after loaded retrieval becomes `ambiguous`.
- `save()` observes only the current receiver state established by earlier ordered evidence in the supported scope.

## Implementation Phases

### Phase A: Failing Analyzer/Domain Tests

- Add failing analyzer tests proving fresh construction followed by direct `save()` emits fresh-save evidence.
- Add failing analyzer tests for literal constructor attributes plus `save()`.
- Add failing analyzer tests for empty constructor plus same-receiver property writes plus `save()`.
- Add failing analyzer tests for same-receiver literal attribute-array writes plus `save()`.
- Add failing analyzer tests for constructor attributes plus additional supported assignments.
- Add failing analyzer tests proving fresh construction followed by unsupported reassignment and then `save()` emits no fresh-save evidence.
- Add failing analyzer tests proving loaded model plus `save()` does not emit fresh-save write usage.
- Add failing analyzer tests proving ambiguous origin plus `save()` emits no fresh-save write usage.
- Add failing analyzer tests proving `save()` before a later fresh construction is not classified using future evidence.
- Add failing analyzer tests proving fresh construction on an unsupported or conditional path with `save()` outside the safe straight-line evidence boundary emits no fresh-save evidence.
- Add failing analyzer tests proving fresh construction plus supported same-receiver assignments plus `save()` in valid source order emits exactly the intended fresh-save evidence.
- Add failing analyzer tests proving helper/alias/dynamic/factory/relationship/saveOrFail/push cases emit no fresh-save write usage.
- Add DB005 unit test proving `operation: eloquent_fresh_save`, `columns: null`, and `reason: eloquent_fresh_save_semantics` produce `WARNING` / `UNKNOWN`.

### Phase B: Minimal Analyzer Behavior

- Implement private same-receiver fresh/load/ambiguous tracking inside `EloquentUsageAnalyzer` using one deterministic ordered event stream or traversal for relevant receiver events.
- Emit `WriteUsage` only for supported fresh receiver `save()` calls.
- Keep `columns: null` regardless of literal constructor or assignment attributes because final persisted columns remain affected by Eloquent runtime behavior.
- Treat unsafe ordering, unsupported nested control flow, and unsupported reassignment as ambiguous for this feature.
- Avoid changes to `ModelIndexService` unless a failing test proves current known-model resolution cannot support direct `new KnownModel(...)`.

### Phase C: DB005 Integration Tests

- Add integration coverage showing fresh `save()` plus required added column produces one DB005 `WARNING` / `UNKNOWN` finding.
- Verify the analysis result has no definite blocker for fresh-save-only risk.
- Verify loaded and ambiguous `save()` scenarios produce no fresh-save DB005 finding.

### Phase D: Regression/Public-Contract Tests

- Preserve existing Eloquent `create()` behavior as `WARNING` / `UNKNOWN`.
- Preserve Query Builder `insert`/`insertGetId` behavior, including known-column `BLOCKER` / `DEFINITE` and unknown-payload `WARNING` / `UNKNOWN`.
- Preserve console and JSON output shape and exit code `0` for fresh-save-only DB005 warnings.
- Preserve rule codes DB001-DB006, severity/confidence vocabulary, configuration behavior, and the success message.

### Phase E: Complete Suite and Laravel Matrix

- Run targeted PHPUnit tests for analyzer, DB005, integration, and command behavior.
- Run `composer test`.
- Before release-finalization, run the supported Laravel 10-13 matrix and `composer validate --strict`.
- Keep README/CHANGELOG out of prerequisite implementation tasks; only add release-finalization documentation later if externally visible behavior documentation is required for v0.2.

## Post-Design Constitution Check

- **Evidence Before Certainty**: PASS. Fresh-save findings are intentionally unknown-confidence warnings and are emitted only when ordered same-receiver static evidence precedes the `save()` inside the supported boundary.
- **v0.1 Compatibility Baseline**: PASS. Existing behavior is pinned by v0.1 corpus and explicit Query Builder/Eloquent-create regression tests.
- **Test-First Compatibility Rules**: PASS. Phase A starts with failing tests for positive source-order evidence, reassignment downgrades, loaded/ambiguous negatives, future-evidence negatives, unsupported-boundary negatives, and DB005 representation before production changes; Phase D protects public contracts.
- **Public Contracts Are Stable**: PASS. No public shape, code, config, vocabulary, package, or exit-code changes are designed.
- **Narrow Static Analysis**: PASS. The evidence boundary is local, same-receiver, ordered, AST-only, and straight-line; it excludes broad ORM/data-flow inference and explicitly avoids CFG construction.

## Complexity Tracking

No constitution violations or justified complexity exceptions.
