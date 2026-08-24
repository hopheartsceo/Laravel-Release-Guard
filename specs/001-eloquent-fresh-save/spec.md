# Feature Specification: Eloquent Fresh Save DB005 Coverage

**Feature Branch**: `feat/v0.2-eloquent-fresh-save`

**Created**: 2026-08-24

**Status**: Draft

**Input**: User description: "Laravel Release Guard v0.2 should conservatively broaden DB005 coverage to recognize supported fresh-instance Eloquent save() write paths."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Warn on supported fresh-instance save insert risk (Priority: P1)

A maintainer preparing a rolling or zero-downtime Laravel deployment needs DB005 to recognize that the previous release may insert a row by constructing a fresh model instance and later calling `save()`. When the candidate migration adds a newly required column without a database default, the maintainer should receive a non-blocking DB005 review warning instead of no signal.

**Why this priority**: This is the first v0.2 capability and closes a known gap in the current DB005 write-path coverage without weakening the project's conservative confidence model.

**Independent Test**: Can be tested by comparing a base application that uses a fresh model instance followed by `save()` against a candidate migration that adds a required column to that model's table, then verifying a DB005 `WARNING` / `UNKNOWN` finding and no definite blocker.

**Acceptance Scenarios**:

1. **Given** the base application constructs a known model with a literal attribute array and then calls `save()` on that same fresh instance, **When** the candidate migration adds a non-nullable column without a database default to the model table and the attribute evidence does not prove the new column is persisted, **Then** DB005 reports a `WARNING` with `UNKNOWN` confidence and the analysis remains non-blocking.
2. **Given** the base application constructs a known model with no constructor payload, assigns supported literal attributes before calling `save()` on that same fresh instance, **When** the candidate migration adds a non-nullable column without a database default to the model table and the assignment evidence does not prove the new column is persisted, **Then** DB005 reports a `WARNING` with `UNKNOWN` confidence and the analysis remains non-blocking.
3. **Given** the base application uses an already supported Eloquent model creation path such as `create()`, **When** the same required-column risk is present, **Then** existing DB005 `WARNING` / `UNKNOWN` behavior is preserved.

---

### User Story 2 - Avoid insert findings for loaded-model save paths (Priority: P2)

A maintainer needs the analyzer to avoid treating every `save()` call as a possible insert. When static evidence shows the receiver came from an existing-record retrieval or update flow, the `save()` call must not become a fresh-instance DB005 insert finding merely because `save()` appears.

**Why this priority**: False insert claims on loaded models would violate the v0.1 trust model and could turn ordinary update flows into noisy compatibility findings.

**Independent Test**: Can be tested by comparing a base application that retrieves an existing model and calls `save()` after assignment against a candidate migration that adds a required column, then verifying no fresh-instance DB005 insert finding is produced.

**Acceptance Scenarios**:

1. **Given** the base application obtains a model through an existing-record retrieval path and later calls `save()` on that loaded model, **When** a required column is added to the table, **Then** DB005 does not classify that `save()` as a fresh-instance insert path.
2. **Given** the base application uses an existing supported loaded-model update flow, **When** the flow includes `save()` or update-like behavior, **Then** current non-insert handling remains unchanged.

---

### User Story 3 - Keep ambiguous provenance outside fresh-save DB005 (Priority: P3)

A maintainer needs unsupported or ambiguous Eloquent receiver provenance to remain conservative. If the analyzer cannot statically prove that a `save()` receiver is a fresh model instance, it must not invent a fresh-instance insert claim.

**Why this priority**: The constitution requires unknown evidence to remain non-blocking, especially around runtime-dependent Eloquent behavior.

**Independent Test**: Can be tested with base applications that use dynamic model construction, receiver aliases outside the supported evidence boundary, unsupported control flow, or uncertain assignment paths, then verifying `save()` alone does not produce a fresh-instance DB005 insert finding. Findings independently justified by existing v0.1 rules remain unchanged.

**Acceptance Scenarios**:

1. **Given** the base application calls `save()` on a receiver whose origin is ambiguous, dynamically constructed, or outside the supported evidence boundary, **When** a required column is added to a potentially related table, **Then** `save()` alone does not produce any fresh-instance DB005 insert finding for that receiver.
2. **Given** static evidence proves a fresh model instance but cannot prove the final persisted columns because model defaults, events, mutators, casts, observers, boot hooks, or other runtime behavior may alter the insert, **When** DB005 reports the risk, **Then** the finding is `WARNING` severity with `UNKNOWN` confidence.
3. **Given** the v0.1 validation corpus scenarios are run after this feature is implemented, **When** their expected outcomes are evaluated, **Then** existing pass, block, and unknown outcomes remain compatible with v0.1 behavior.

### Edge Cases

- A fresh model is constructed with a supported literal attribute array and then saved after additional supported assignments; the analyzer may recognize the path, but DB005 must remain `WARNING` / `UNKNOWN` unless persisted columns are proven within the documented scope.
- A fresh model is constructed without arguments, attributes are assigned through supported direct property or attribute-style writes on that same local receiver, and the same receiver is saved; the analyzer may recognize this as a possible insert path.
- A `save()` receiver comes from existing-record retrieval, query result, relationship lookup, `find`-style path, or another supported loaded-model pattern; it must not be classified as a fresh-instance insert path even if assignments occur before `save()`.
- Receiver provenance crosses unsupported aliases, dynamic variables, complex control flow, factory helpers, dependency injection, helper functions, container resolution, or dynamic model class names; it must not be upgraded into a fresh-instance insert claim.
- Candidate added columns that are nullable or have a database default remain outside DB005 breaking-write findings, matching existing behavior.
- Existing Query Builder insert handling, Eloquent `create` handling, and unknown dynamic payload handling retain their v0.1 classifications.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The analyzer MUST recognize a supported fresh-instance Eloquent `save()` path as a possible insert path when static evidence shows a known model instance was newly constructed and the same receiver is later saved.
- **FR-002**: Supported fresh-instance evidence MUST include a known model constructed with a literal attribute array and followed by `save()` on that same local receiver.
- **FR-003**: Supported fresh-instance evidence MUST include a known model constructed with no constructor payload, supported direct property or attribute-style assignments on that same local receiver, and a later `save()` on that same receiver.
- **FR-004**: For DB005, a recognized fresh-instance `save()` insert path whose final persisted columns cannot be proven MUST produce `WARNING` severity and `UNKNOWN` confidence when it is relevant to a newly added required column without a database default.
- **FR-005**: A DB005 `WARNING` / `UNKNOWN` finding from fresh-instance `save()` MUST remain non-blocking and preserve exit code `0` under the existing v0.1 exit-code contract.
- **FR-006**: The analyzer MUST NOT classify `save()` on a receiver statically established by a supported existing-record retrieval or update path as a fresh-instance insert path.
- **FR-007**: The analyzer MUST NOT upgrade ambiguous receiver provenance, dynamic model construction, unsupported aliasing, unsupported control flow, or unproven freshness into any fresh-instance DB005 insert finding solely because that receiver calls `save()`.
- **FR-008**: The analyzer MUST NOT execute Laravel application code, model events, observers, boot hooks, migrations, database queries, or the old application revision to determine fresh-instance `save()` behavior.
- **FR-009**: Existing DB001 through DB006 rule codes, severity vocabulary, confidence vocabulary, CLI output shape, JSON output shape, configuration keys, and exit-code meanings MUST remain compatible with v0.1.0 unless a later specification explicitly changes them.
- **FR-010**: Existing Query Builder write analysis, Eloquent `create` handling, dynamic payload handling, nullable added-column handling, defaulted added-column handling, and the v0.1 validation corpus MUST remain compatible with the released v0.1.0 baseline at commit `80bd17da2ef34b511689c287a13944b8da6ea1a7`.
- **FR-011**: Regression coverage for the eventual implementation MUST begin with failing tests for positive supported fresh-instance `save()` scenarios, conservative `WARNING` / `UNKNOWN` DB005 behavior, loaded-model `save()` non-insert scenarios, ambiguous provenance remaining non-blocking, and preservation of v0.1 validation corpus behavior.
- **FR-012**: The specification does not require any particular internal analyzer class, data-flow architecture, or implementation strategy; architecture choices belong to the planning phase.
- **FR-013**: This feature's new Eloquent instance-write capability is limited to `save()` and MUST NOT silently add coverage for `saveOrFail()`, `push()`, relationship saves, factories, helper-created models, arbitrary aliases, or general ORM semantics.
- **FR-014**: Existing DB005 findings independently justified by v0.1 Query Builder inserts, Eloquent `create` operations, or existing unknown-payload rules MUST remain eligible for their current classifications even when unrelated ambiguous `save()` calls are present.

### Key Entities *(include if feature involves data)*

- **Fresh Model Instance Evidence**: Static evidence that a known model receiver was created as a new instance in the previous-release application code and later receives `save()`.
- **Loaded Model Evidence**: Static evidence that a model receiver came from an existing-record retrieval or update path, meaning `save()` must not be treated as a fresh insert for DB005.
- **Ambiguous Receiver Provenance**: Any `save()` receiver origin that cannot be proven fresh within the supported static evidence boundary.
- **DB005 Finding**: A compatibility finding for a newly required database column that supported previous-release write paths may not satisfy.
- **v0.1 Compatibility Baseline**: The released behavior at commit `80bd17da2ef34b511689c287a13944b8da6ea1a7`, including public output, exit behavior, rule semantics, and validation corpus outcomes.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: At least two supported fresh-instance `save()` scenarios produce DB005 `WARNING` / `UNKNOWN` findings when compared with a candidate migration adding a required column without a database default.
- **SC-002**: At least two loaded-model `save()` scenarios produce no fresh-instance DB005 insert finding.
- **SC-003**: At least two ambiguous or unsupported `save()` provenance scenarios produce no fresh-instance DB005 insert finding solely from the ambiguous `save()` call.
- **SC-004**: A run containing only DB005 `WARNING` / `UNKNOWN` findings exits with code `0` and preserves the existing machine-readable and human-readable result contract.
- **SC-005**: All 24 canonical v0.1 validation corpus scenarios retain their existing pass, block, or unknown outcomes.
- **SC-006**: The complete feature regression set demonstrates that no fresh-instance `save()` scenario is promoted to `BLOCKER` / `DEFINITE` without proof of final persisted columns.

## Assumptions

- The actor is a Laravel Release Guard maintainer or contributor preparing the v0.2 compatibility-analysis feature.
- This feature extends DB005 only for fresh-instance Eloquent `save()` insert paths; it is not a general Eloquent data-flow engine.
- The supported fresh-instance evidence boundary is intentionally local and narrow: direct construction of a known model receiver, optional supported direct assignments on that same receiver, and `save()` on that same receiver.
- Supported direct assignments mean same-receiver property writes and same-receiver attribute array writes with literal attribute names; method-based mass assignment, helper-mediated assignment, aliases, and control-flow-dependent assignment remain outside this feature.
- The relevant candidate schema change is an added required column with no database default; nullable or defaulted added columns keep existing behavior.
- Because Eloquent runtime behavior can alter persisted columns, recognized fresh-instance `save()` paths default to conservative `WARNING` / `UNKNOWN` outcomes unless a future specification defines a narrower definite-evidence case.
- No repository evidence reviewed contradicts the supplied assumptions. Current repository behavior already treats Eloquent `create()` as `WARNING` / `UNKNOWN`, preserves only `BLOCKER` / `DEFINITE` as CI-failing, and anchors v0.1.0 at commit `80bd17da2ef34b511689c287a13944b8da6ea1a7`.

## Non-Goals

- This feature does not implement universal alias analysis, arbitrary control-flow inference, broad ORM semantics, or runtime Laravel behavior modeling.
- This feature does not add coverage for `saveOrFail()`, `push()`, relationship saves, factories, helper-created models, dependency-injected models, container-resolved models, arbitrary aliases, or dynamic model class names.
- This feature does not require executing application code, migrations, model events, observers, database queries, or the old application revision.
- This feature does not change DB001, DB002, DB003, DB004, DB006, public CLI output, JSON output, configuration, package metadata, or exit-code semantics.
- This feature does not require a specific internal class or architecture such as an instance-write analyzer.
- This feature does not claim to prove universal deployment safety beyond the documented static analysis scope.
