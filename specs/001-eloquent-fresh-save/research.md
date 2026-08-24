# Research: Eloquent Fresh Save DB005 Coverage

## Decision: Extend `EloquentUsageAnalyzer` Rather Than Add a Public Analyzer

Rationale: Existing Eloquent analysis already owns known-model resolution, table mapping, write usage emission, and conservative Eloquent create semantics. Fresh-instance `save()` is another Eloquent usage shape that produces the same downstream domain type, `WriteUsage`. Keeping the behavior in `EloquentUsageAnalyzer` avoids new pipeline wiring, service-provider wiring, and duplicate model resolution.

Alternatives considered: A focused `EloquentInstanceWriteAnalyzer` would isolate receiver-state logic, but for this feature it adds architecture without a separate public contract or pipeline boundary. It should be reconsidered only if future features add broader instance-write operations that make `EloquentUsageAnalyzer` materially harder to test or reason about.

## Decision: Represent Fresh `save()` as `WriteUsage` With Eloquent Unknown-Column Semantics

Rationale: `WriteUsage` already carries table, operation, optional columns, reason, file, and line. Existing DB005 uses `columns: null` as "write exists, final columns unknown" and emits `WARNING` / `UNKNOWN` for that case. Fresh Eloquent `save()` has the same uncertainty as Eloquent `create()` because final persisted columns may be changed by model defaults, mutators, casts, lifecycle hooks, observers, dirty tracking, timestamps, and other runtime behavior outside this analyzer's static boundary.

Alternatives considered: A new domain object such as `FreshModelSaveUsage` would encode provenance more explicitly, but it would require compatibility-rule changes and public-output review without improving DB005's needed behavior. Reusing operation/reason strings inside `WriteUsage` is the narrower domain change.

## Decision: Use `operation: eloquent_fresh_save`, `columns: null`, and `reason: eloquent_fresh_save_semantics`

Rationale: `RequiredColumnBreaksBaseWritesRule` currently treats `insert` and `insertGetId` as definite insert operations when known columns omit the new required column. It treats Eloquent `create*` operations as conservative warnings. A distinct operation string lets DB005 whitelist fresh `save()` into the Eloquent uncertain branch and prevents it from being confused with Query Builder `insert`.

Alternatives considered: Emitting operation `insert` with literal columns from constructor/assignments would be incorrect because it would flow through DB005's definite known-column branch and could become `BLOCKER` / `DEFINITE`. Emitting operation `create` would preserve warning semantics but blur source evidence in output and tests.

## Decision: Keep Persisted Columns Unknown Even When Literal Attributes Are Seen

Rationale: Literal constructor or assignment keys prove that some static attributes were provided, not that the final SQL insert columns are exactly those keys. The constitution's Evidence Before Certainty rule requires the analyzer to avoid definite claims when runtime Eloquent behavior can alter writes.

Alternatives considered: Carrying literal columns for fresh-save evidence would make DB005 able to suppress warnings when the added column appears in the static evidence, but that is broader than the approved spec and risks treating runtime behavior as proven.

## Decision: Track Only Local Receiver State Needed for Fresh vs Loaded vs Ambiguous

Rationale: The feature needs a narrow guard against false positives: a receiver statically known to come from supported retrieval/update paths must not be classified as a fresh insert. A local receiver-state table per parsed file can mark variables as `fresh`, `loaded`, or `ambiguous` based on direct assignments and supported calls. It does not need alias propagation, interprocedural analysis, or control-flow modeling.

Alternatives considered: A universal provenance engine was rejected because the spec explicitly excludes broad data-flow and ORM inference. Treating all `save()` calls as possible inserts was rejected because it would violate loaded-model protection and v0.1 trust.

## Decision: Derive Receiver State From One Ordered Provenance Pass Inside `EloquentUsageAnalyzer`

Rationale: The current analyzer discovers several AST node shapes through separate `NodeFinder` passes. That is acceptable for order-independent usage discovery, but receiver provenance is stateful: construction, reassignment, loaded retrieval, and `save()` must be evaluated in lexical/source order for the same local receiver. The fresh-save feature therefore requires a narrow ordered instance-provenance pass or helper inside `EloquentUsageAnalyzer`, either by traversing relevant statements in parser order or by first flattening relevant receiver events into one stable source-order stream before applying transitions.

The ordered pass must guarantee that `$receiver->save()` observes only evidence that appears before that save in the supported ordered scope. Later construction or reassignment cannot retroactively classify an earlier save. Unsupported reassignment after a fresh or loaded origin downgrades the receiver to `ambiguous`.

Alternatives considered: Reusing independent `NodeFinder` collections for `New_`, `Assign`, `StaticCall`, `MethodCall`, property writes, and array writes was rejected because those collections do not by themselves define a single program-order provenance history. A separate `EloquentInstanceWriteAnalyzer` was also rejected for this correction because ordering can be solved with a focused helper while preserving the approved architecture.

## Decision: Keep Positive Evidence Within a Straight-Line Boundary and Avoid CFG Analysis

Rationale: The supported positive case is intentionally narrow: direct `new KnownModel(...)`, optional supported same-receiver direct assignments, then direct `$receiver->save()` in valid source order. Fresh state must not be propagated across conditional branches whose execution cannot be proven, loops, `try`/`catch`/`finally`, `match`/`switch` alternatives, ternary/conditional expressions, closures/callbacks, helper/function/method boundaries, or unsupported nested control flow. If construction or ordering is only true on such a path, the receiver is ambiguous for this feature and no fresh-save DB005 evidence is emitted.

Alternatives considered: Building a CFG or path-sensitive analysis could recognize more cases, but that exceeds the approved v0.2 scope and increases false-positive and maintenance risk. Treating all in-file prior fresh constructions as active after a branch was rejected because it would infer execution paths the analyzer cannot prove.

## Decision: Leave Pipeline and Service Provider Wiring Unchanged Initially

Rationale: `ReleaseGuardAnalysisPipeline` already passes the model index into `EloquentUsageAnalyzer`, and `ReleaseGuardServiceProvider` constructs the pipeline with the default analyzer. Extending `EloquentUsageAnalyzer` preserves constructor compatibility and avoids new container bindings.

Alternatives considered: Injecting a new analyzer into the pipeline would be needed only if a separate public analyzer were chosen. That alternative was rejected for this scope.

## Decision: Make Testing Start With Analyzer and DB005 Rule Failures

Rationale: The main risks are false-positive evidence expansion and incorrect DB005 classification. Failing analyzer tests lock down the evidence boundary before production changes; DB005 rule tests lock down `WARNING` / `UNKNOWN`; integration and feature tests then verify pipeline and public contracts.

Alternatives considered: Starting with end-to-end tests alone would be too coarse to locate provenance and classification mistakes. Starting with implementation would violate the constitution's test-first rule.
