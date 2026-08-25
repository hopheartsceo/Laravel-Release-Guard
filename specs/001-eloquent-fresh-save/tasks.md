---
description: "Task list for Eloquent Fresh Save DB005 Coverage"
---

# Tasks: Eloquent Fresh Save DB005 Coverage

**Input**: Design documents from `/specs/001-eloquent-fresh-save/`

**Prerequisites**: `spec.md`, `plan.md`, `research.md`, `data-model.md`, `quickstart.md`, `contracts/public-analysis-contract.md`, `checklists/requirements-quality.md`, `.specify/memory/constitution.md`

**Tests**: Required by FR-011, the approved plan, and the constitution. Missing broadened behavior must begin with failing regression tests, while conservative non-regression boundaries must be captured first as baseline characterization/safety tests before production implementation.

**Organization**: Tasks are grouped by the approved implementation phases while preserving user-story traceability with `[US1]`, `[US2]`, and `[US3]` labels.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel because it touches different files and has no dependency on incomplete tasks
- **[Story]**: User story coverage from `spec.md`
- Each task names exact file path(s), dependencies where sequencing matters, and observable completion criteria

---

## Phase A: Tests First - RED Feature Tests + Baseline Safety Characterization

**Purpose**: Encode positive fresh-save and DB005 missing behavior as RED tests, and encode already-safe negative loaded, ambiguous, ordering, control-flow, and exclusion boundaries as GREEN baseline characterization locks before any production changes.

**Independent Test Criteria**: Running the focused PHPUnit commands in `quickstart.md` shows positive fresh-save analyzer tests fail for missing fresh-save detection, the DB005 classification test fails for missing `eloquent_fresh_save` handling, and negative safety tests pass on current v0.1 behavior where applicable.

### Analyzer RED Feature Tests

- [X] T001 [US1] Add failing direct fresh construction then `save()` analyzer test in `tests/Unit/Analysis/Application/EloquentUsageAnalyzerTest.php`; done when the test expects one `WriteUsage` with operation `eloquent_fresh_save` and fails before implementation.
- [X] T002 [US1] Add failing literal constructor attribute array then `save()` analyzer test in `tests/Unit/Analysis/Application/EloquentUsageAnalyzerTest.php`; done when `columns` is asserted `null`, reason is `eloquent_fresh_save_semantics`, and the test fails before implementation.
- [X] T003 [US1] Add failing empty constructor then same-receiver property assignment then `save()` analyzer test in `tests/Unit/Analysis/Application/EloquentUsageAnalyzerTest.php`; done when the test fails until property assignment preserves fresh state.
- [X] T004 [US1] Add failing empty constructor then literal attribute-array assignment then `save()` analyzer test in `tests/Unit/Analysis/Application/EloquentUsageAnalyzerTest.php`; done when the test fails until array assignment preserves fresh state.
- [X] T005 [US1] Add failing constructor payload plus additional supported property and attribute-array assignments then `save()` analyzer test in `tests/Unit/Analysis/Application/EloquentUsageAnalyzerTest.php`; done when one exact fresh-save `WriteUsage` is expected and fails before implementation.
- [X] T011 [US1] Add failing valid straight-line ordered path test asserting exact table, operation, `columns: null`, reason, file, and save line in `tests/Unit/Analysis/Application/EloquentUsageAnalyzerTest.php`; done when only prior ordered same-receiver evidence is accepted.

### Analyzer Baseline Safety Characterization Tests

- [X] T006 [US3] Add fresh construction then unsupported reassignment then `save()` analyzer safety test in `tests/Unit/Analysis/Application/EloquentUsageAnalyzerTest.php`; done when the test expects no fresh-save evidence, passes on current v0.1 behavior where applicable, and protects stale fresh-state invalidation after implementation.
- [X] T007 [US2] Add loaded retrieval then assignment then `save()` analyzer safety tests for direct and query-root retrieval shapes in `tests/Unit/Analysis/Application/EloquentUsageAnalyzerTest.php`; done when both expect no fresh-save evidence, pass on current v0.1 behavior where applicable, and continue passing after implementation.
- [X] T008 [US3] Add ambiguous-origin then `save()` analyzer safety test in `tests/Unit/Analysis/Application/EloquentUsageAnalyzerTest.php`; done when dynamic/helper-origin provenance expects no fresh-save evidence, passes on current v0.1 behavior where applicable, and continues passing after implementation.
- [X] T009 [US3] Add source-order regression safety test where `save()` appears before later `new KnownModel(...)` in `tests/Unit/Analysis/Application/EloquentUsageAnalyzerTest.php`; done when earlier `save()` expects no future-derived fresh-save evidence, passes on current v0.1 behavior where applicable, and continues passing after implementation.
- [X] T010 [US3] Add unsafe control-flow safety test where fresh construction occurs inside an unsupported conditional/path and `save()` is outside the boundary in `tests/Unit/Analysis/Application/EloquentUsageAnalyzerTest.php`; done when no fresh-save evidence is expected, passes on current v0.1 behavior where applicable, and continues passing after implementation.
- [X] T012 [US3] Add exclusion safety tests for alias, helper, factory, relationship, dynamic class, dependency injection, container resolution, `saveOrFail()`, and `push()` in `tests/Unit/Analysis/Application/EloquentUsageAnalyzerTest.php`; done when every excluded case expects no fresh-save write usage, passes on current v0.1 behavior where applicable, and continues passing after implementation.

### DB005 RED Test

- [X] T013 [P] [US1] Add failing DB005 rule test for a `WriteUsage` with operation `eloquent_fresh_save`, `columns: null`, and reason `eloquent_fresh_save_semantics` in `tests/Unit/Compatibility/Rules/RequiredColumnBreaksBaseWritesRuleTest.php`; done when it expects `WARNING` / `UNKNOWN`, not `BLOCKER` / `DEFINITE`, and fails before rule implementation.

**Checkpoint**: Positive fresh-save tests T001-T005 and T011 are RED for missing analyzer behavior; DB005 fresh-save classification test T013 is RED; negative loaded, ambiguous, ordering, control-flow, reassignment, and exclusion tests T006-T010 and T012 are GREEN characterization locks on the current baseline where applicable; no production code is changed until these gates are established.

---

## Phase B: GREEN - Minimal Analyzer Implementation

**Purpose**: Extend only `EloquentUsageAnalyzer` enough to satisfy Phase A analyzer tests while preserving narrow static analysis.

**Independent Test Criteria**: `vendor/bin/phpunit tests/Unit/Analysis/Application/EloquentUsageAnalyzerTest.php` passes for fresh, loaded, ambiguous, ordering, and exclusion cases.

### Implementation for Analyzer Behavior

- [X] T014 [US1] Implement a private deterministic ordered receiver-event traversal/helper inside `src/Analysis/Application/EloquentUsageAnalyzer.php` (depends on T001-T012); done when analyzer RED feature tests T001-T005 and T011 have been observed failing, analyzer baseline safety tests T006-T010 and T012 have been observed passing where applicable, provenance events are processed in lexical/source order from one stream, and no unrelated `NodeFinder` pass order is assumed.
- [X] T015 [US1] Implement direct known-model `new KnownModel(...)` assignment recognition inside `src/Analysis/Application/EloquentUsageAnalyzer.php` (depends on T014); done when no-payload and literal associative constructor arrays move the same local receiver to `fresh`.
- [X] T016 [US1] Implement supported same-receiver property and literal attribute-array assignment handling inside `src/Analysis/Application/EloquentUsageAnalyzer.php` (depends on T015); done when fresh receivers stay fresh and literal attribute evidence does not become persisted `columns`.
- [X] T017 [US2] Implement narrow loaded-origin recognition for approved retrieval shapes inside `src/Analysis/Application/EloquentUsageAnalyzer.php` (depends on T014); done when supported `find`, `findOrFail`, `first`, `firstOrFail`, `sole`, and query-root retrieval chains move the same local receiver to `loaded`.
- [X] T018 [US3] Implement ambiguous downgrade for unsupported reassignment, alias/helper/dynamic/factory/relationship/DI/container origins, and unsafe control-flow/path boundaries inside `src/Analysis/Application/EloquentUsageAnalyzer.php` (depends on T014-T017); done when stale fresh or loaded state is not preserved after unsupported evidence.
- [X] T019 [US1] Emit fresh-save `WriteUsage` only for direct same-receiver `save()` while state is `fresh` inside `src/Analysis/Application/EloquentUsageAnalyzer.php` (depends on T014-T018); done when emitted usage has operation `eloquent_fresh_save`, `columns: null`, reason `eloquent_fresh_save_semantics`, and the `save()` line.
- [X] T020 [US3] Run focused analyzer verification with `vendor/bin/phpunit tests/Unit/Analysis/Application/EloquentUsageAnalyzerTest.php` (depends on T014-T019); done when the focused analyzer test file passes and confirms no future-evidence leakage, no broad control-flow inference, and no excluded operation support.

**Checkpoint**: Analyzer emits fresh-save evidence only within the approved straight-line same-local-receiver boundary.

---

## Phase C: GREEN - DB005 Rule Integration

**Purpose**: Classify fresh-save evidence as Eloquent uncertain DB005 risk without changing Query Builder definite insert semantics.

**Independent Test Criteria**: DB005 focused rule tests pass and prove fresh-save is `WARNING` / `UNKNOWN` and non-definite.

### Implementation for DB005

- [X] T021 [US1] Add `eloquent_fresh_save` to the Eloquent uncertain branch in `src/Compatibility/Rules/RequiredColumnBreaksBaseWritesRule.php` (depends on T013 and T019); done when fresh-save `columns: null` produces existing DB005 `WARNING` / `UNKNOWN` vocabulary.
- [X] T022 [US1] Protect Query Builder definite insert classification in `tests/Unit/Compatibility/Rules/RequiredColumnBreaksBaseWritesInsertVariantsTest.php` (depends on T021); done when `insert` and `insertGetId` known-column cases remain `BLOCKER` / `DEFINITE` and fresh-save never enters that branch.
- [X] T023 [US1] Run focused DB005 verification with `vendor/bin/phpunit tests/Unit/Compatibility/Rules/RequiredColumnBreaksBaseWritesRuleTest.php tests/Unit/Compatibility/Rules/RequiredColumnBreaksBaseWritesInsertVariantsTest.php` (depends on T021-T022); done when both DB005 rule test files pass.

**Checkpoint**: Fresh-save downstream representation is recognized by DB005 as non-blocking uncertain Eloquent evidence only.

---

## Phase D: Integration and Public-Contract Regression Tests

**Purpose**: Verify end-to-end behavior and public contracts without changing JSON, console, configuration, code vocabulary, or v0.1 semantics.

**Independent Test Criteria**: Integration and feature tests prove fresh-save warnings appear when supported, disappear when loaded/ambiguous, exit `0`, and leave public contracts unchanged.

### Integration Tests

- [X] T024 [US1] Add fresh-save plus required-added-column integration test in `tests/Integration/EloquentCompatibilityScenariosTest.php` (depends on T020 and T023); done when DB005 `WARNING` / `UNKNOWN` is asserted for a supported fresh-save path.
- [X] T025 [US1] Add fresh-save-only `AnalysisResult` non-blocker integration assertion in `tests/Integration/EloquentReleaseGuardAnalysisPipelineTest.php` (depends on T024); done when `hasDefiniteBlocker()` is false for fresh-save-only DB005 warnings.
- [X] T026 [US2] Add loaded-model `save()` integration regression in `tests/Integration/EloquentCompatibilityScenariosTest.php` (depends on T020 and T023); done when no fresh-save DB005 finding is produced for supported loaded retrieval paths.
- [X] T027 [US3] Add ambiguous-provenance `save()` integration regression in `tests/Integration/EloquentCompatibilityScenariosTest.php` (depends on T020 and T023); done when no fresh-save DB005 finding is produced for ambiguous or unsupported receiver provenance.
- [X] T028 [US1] Preserve existing Eloquent `create`, `forceCreate`, `createQuietly`, and `forceCreateQuietly` behavior in `tests/Integration/EloquentCompatibilityScenariosTest.php` (depends on T024); done when each remains DB005 `WARNING` / `UNKNOWN`.
- [X] T029 [US1] Preserve Query Builder `insert` and `insertGetId` classifications in `tests/Feature/RequiredColumnBreaksBaseWritesIntegrationTest.php` (depends on T023); done when known-column definite and unknown-payload uncertain classifications remain unchanged.

### Public Contract Tests

- [X] T030 [US1] Add command exit-code regression for fresh-save-only DB005 warning in `tests/Feature/CheckReleaseCompatibilityCommandTest.php` (depends on T024-T025); done when command exit code is `0`.
- [X] T031 [US1] Add JSON contract regression for fresh-save DB005 output in `tests/Feature/CheckReleaseCompatibilityCommandTest.php` (depends on T030); done when the existing JSON envelope is unchanged, code remains DB005, severity remains WARNING, confidence remains UNKNOWN, usage.operation is `eloquent_fresh_save`, and the existing public usage shape remains unchanged with only its currently public fields such as file, line, and operation; do not add public `columns` or `reason` fields, because internal `columns: null` and reason `eloquent_fresh_save_semantics` are protected by lower-level tests rather than public JSON serialization.
- [X] T032 [US1] Add console contract regression for fresh-save DB005 output and exact success-message preservation in `tests/Feature/CheckReleaseCompatibilityCommandTest.php` (depends on T030); done when console shape remains compatible and `No incompatible changes detected within the analyzed scope.` is unchanged where applicable.
- [X] T033 [US3] Add configuration and rule-code vocabulary regression in `tests/Feature/CompatibilityEngineRegistrationTest.php` and `tests/Feature/CheckReleaseCompatibilityCommandTest.php` (depends on T030-T032); done when DB001-DB006 codes, severity/confidence names, and configuration behavior are unchanged.

**Checkpoint**: End-to-end and public-contract behavior matches `contracts/public-analysis-contract.md`.

---

## Phase E: v0.1 Regression, Full Suite, and Release Gate

**Purpose**: Validate compatibility baseline, focused feature coverage, complete test suite, Composer metadata validity, and Laravel support matrix readiness.

**Independent Test Criteria**: All listed commands pass locally where executable; Laravel 10-13 support matrix is recorded as a release gate when it cannot be run on the local machine.

### Validation Tasks

- [X] T034 [US3] Run v0.1 corpus verification with `vendor/bin/phpunit tests/Integration/V01ValidationCorpusTest.php` (depends on T024-T033); done when all 24 canonical scenarios preserve existing pass, block, and unknown outcomes.
- [X] T035 [P] [US1] Run focused analyzer tests with `vendor/bin/phpunit tests/Unit/Analysis/Application/EloquentUsageAnalyzerTest.php` (depends on T020); done when the analyzer suite passes after integration changes.
- [X] T036 [P] [US1] Run focused DB005 tests with `vendor/bin/phpunit tests/Unit/Compatibility/Rules/RequiredColumnBreaksBaseWritesRuleTest.php tests/Unit/Compatibility/Rules/RequiredColumnBreaksBaseWritesInsertVariantsTest.php` (depends on T023); done when DB005 fresh-save and Query Builder classifications pass together.
- [X] T037 [US1] Run Eloquent integration tests with `vendor/bin/phpunit tests/Integration/EloquentCompatibilityScenariosTest.php tests/Integration/EloquentReleaseGuardAnalysisPipelineTest.php` (depends on T024-T028); done when fresh, loaded, ambiguous, and existing Eloquent create paths pass.
- [X] T038 [US1] Run command and public-contract tests with `vendor/bin/phpunit tests/Feature/CheckReleaseCompatibilityCommandTest.php tests/Feature/CompatibilityEngineRegistrationTest.php tests/Feature/RequiredColumnBreaksBaseWritesIntegrationTest.php` (depends on T029-T033); done when exit codes, JSON, console, config, DB001-DB006, and Query Builder regressions pass.
- [X] T039 Run full repository test suite with `composer test` using scripts in `composer.json` (depends on T034-T038); done when all tests pass.
- [X] T040 Run Composer metadata validation with `composer validate --strict` against `composer.json` (depends on T039); done when Composer reports strict validation success.
- [X] T041 Verify Laravel 10-13 support-matrix status in `.github/workflows/tests.yml` as a final release gate (depends on T039-T040); done when matrix status is captured without treating local inability to run the whole matrix as an implementation prerequisite.
- [X] T042 [P] Review release documentation impact in `README.md` and `CHANGELOG.md` after behavior is verified (depends on T039-T041); done when maintainers decide whether documentation updates are needed for v0.2 without blocking implementation prerequisites.

**Checkpoint**: Feature behavior is verified, public contracts are stable, and release gates are identified.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase A**: No dependencies; must complete before production implementation.
- **Phase B**: Depends on analyzer tests T001-T012 being authored, with T001-T005 and T011 observed RED and T006-T010 and T012 observed GREEN as baseline safety characterization tests where applicable.
- **Phase C**: Depends on DB005 RED test T013 and analyzer emission task T019.
- **Phase D**: Depends on focused analyzer and DB005 verification T020 and T023.
- **Phase E**: Depends on Phase D integration and contract regressions T024-T033.

### User Story Dependencies

- **US1 (P1)**: Starts with RED analyzer and DB005 tests T001-T005, T011, T013 plus baseline analyzer safety gates T006-T010 and T012 before production implementation; MVP implementation is T014-T023 plus fresh-save integration/contract tasks T024-T025 and T030-T032.
- **US2 (P2)**: Depends on ordered receiver-state foundation T014; loaded-origin tests T007 must precede implementation task T017 and integration task T026.
- **US3 (P3)**: Depends on ordered receiver-state foundation T014; ambiguity and exclusion tests T006, T008-T010, T012 must precede implementation task T018 and integration task T027.

### First Production-Code Gate

- **First production-code task**: T014 in `src/Analysis/Application/EloquentUsageAnalyzer.php`.
- **Blocking prerequisites**: T001, T002, T003, T004, T005, T006, T007, T008, T009, T010, T011, and T012 must be authored before T014 starts.
- **RED group required before T014**: T001-T005 and T011 must exist and be observed failing for the intended missing fresh-save behavior.
- **Safety group required before T014**: T006-T010 and T012 must exist and be observed passing as baseline characterization/safety tests on current v0.1 behavior where applicable, and must continue passing after fresh-save support is added.

### Ordering Safety Gates

- **Later construction cannot classify earlier save**: T009, T014, T020.
- **Unsupported reassignment invalidates stale fresh state**: T006, T018, T020.
- **One deterministic ordered traversal/event stream**: T011, T014, T020.
- **Unsafe control flow does not leak fresh state**: T010, T018, T020.

### BLOCKER / DEFINITE Protection Gates

- **Fresh-save DB005 is `WARNING` / `UNKNOWN`**: T013 must be written and observed failing before T021 modifies `src/Compatibility/Rules/RequiredColumnBreaksBaseWritesRule.php`; T013 does not block T014. Follow-on verification is T021, T023, T024.
- **Fresh-save-only analysis has no definite blocker and exits `0`**: T025, T030.
- **Query Builder definite insert classification remains unchanged**: T022, T029, T036, T038.

### v0.1 Compatibility Gates

- **Eloquent create behavior remains `WARNING` / `UNKNOWN`**: T028, T037.
- **Query Builder behavior remains unchanged**: T022, T029, T036, T038.
- **Public JSON, console, config, codes, vocabulary, and success message remain stable**: T030-T033, T038.
- **All 24 canonical v0.1 scenarios remain preserved**: T034.

### Parallel Opportunities

- T013 can run in parallel with analyzer pre-implementation tests T001-T012 because it edits `tests/Unit/Compatibility/Rules/RequiredColumnBreaksBaseWritesRuleTest.php`.
- T035 and T036 can run in parallel after their dependencies because they execute different focused test suites.
- T042 is marked parallelizable because it is review-only after release gates are known and must not block implementation prerequisites.

---

## Implementation Strategy

### MVP First (US1)

1. Complete Phase A RED tests for supported fresh-save cases and DB005 classification, plus GREEN baseline safety characterization tests for already-safe negative boundaries.
2. Complete Phase B minimal analyzer tasks T014-T020.
3. Complete Phase C DB005 integration tasks T021-T023.
4. Validate US1 independently with T024-T025 and T030-T032.

### Incremental Delivery

1. Add US1 supported fresh-save warning behavior without changing public contracts.
2. Add US2 loaded-model protection and verify no fresh-save DB005 finding.
3. Add US3 ambiguity, ordering, reassignment, control-flow, and exclusion protections.
4. Run v0.1 corpus and full-suite release gates.

### Constitution Alignment

- **Evidence Before Certainty**: Fresh-save evidence keeps `columns: null` and DB005 remains `WARNING` / `UNKNOWN`.
- **v0.1 Compatibility Baseline**: T028-T034 and T038 preserve existing rules, public contracts, and corpus outcomes.
- **Test-First Compatibility Rules**: Missing broadened behavior begins with failing regression tests: T001-T005 and T011 before analyzer production task T014, and T013 before DB005 production task T021. Conservative non-regression boundaries T006-T010 and T012 are captured before implementation as characterization/safety tests that pass on the current baseline where applicable. Both RED feature tests and GREEN safety locks are required before production changes.
- **Public Contracts Are Stable**: T030-T033 protect JSON, console, exit-code, configuration, code, and vocabulary stability.
- **Narrow Static Analysis**: T014-T018 require local ordered same-receiver tracking only, with no CFG, runtime Laravel execution, broad alias analysis, DI/container provenance, or universal Eloquent semantics.

---

## Notes

- Do not implement `saveOrFail()`, `push()`, relationship persistence, factories, helpers, arbitrary aliases, interprocedural analysis, dependency injection provenance, container resolution, dynamic model classes, CFG/path-sensitive analysis, or universal Eloquent semantics.
- Do not alter `ModelIndexService.php` unless a failing test proves current known-model resolution cannot support the approved direct-new case.
- Do not introduce a public `EloquentInstanceWriteAnalyzer`; prefer private helpers/value structures inside `EloquentUsageAnalyzer.php` unless tests prove a public domain type is necessary.
- README and CHANGELOG work is review-only after verification and must not be treated as an implementation prerequisite.
