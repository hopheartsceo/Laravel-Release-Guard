# Data Model: Eloquent Fresh Save DB005 Coverage

## Fresh Model Instance Evidence

Represents ordered static proof that a local variable receiver was directly constructed as a known Eloquent model and later saved within the supported straight-line evidence boundary.

Fields:

- `receiver`: local variable name, for example `$user`.
- `modelClass`: known model class resolved through `ModelIndex`.
- `table`: known table from `ModelDescriptor`; must be non-null.
- `constructorAttributes`: literal string attribute keys from a constructor array, or empty when the constructor has no payload.
- `assignedAttributes`: literal string attribute keys from supported same-receiver direct property or attribute-array writes.
- `stateLine`: source line of the most recent ordered receiver-state event.
- `saveLine`: source line of the supported `$receiver->save()` call.
- `file`: base application file path.

Validation rules:

- Receiver must be assigned directly from `new KnownModel(...)`.
- Constructor payload must be absent or a literal associative array with literal string keys.
- Save must be a direct `$receiver->save()` method call.
- Construction, optional supported assignments, and `save()` must appear in deterministic lexical/source order for the same local receiver.
- Evidence must remain inside the supported straight-line boundary; conditional, looped, exception, alternative, closure/callback, helper/function/method, or unsupported nested-control evidence cannot establish fresh state for a later outside `save()`.
- Attributes are evidence only; they do not become proven persisted columns for DB005.

State transition:

- `untracked -> fresh` after supported direct construction.
- `fresh -> fresh` after supported same-receiver assignments.
- `fresh -> ambiguous` after unsupported reassignment or unsupported alias-dependent mutation.
- `fresh -> write evidence emitted` when direct same-receiver `save()` appears.
- Future construction after a `save()` does not affect the earlier `save()`.

## Loaded Model Evidence

Represents static proof that a local receiver came from an existing-record retrieval/update path and must not be treated as a fresh insert.

Fields:

- `receiver`: local variable name.
- `modelClass`: known model class when resolvable.
- `table`: known table when resolvable.
- `originOperation`: retrieval/update origin such as `find`, `findOrFail`, `first`, `firstOrFail`, `sole`, or supported query-root chain ending in a retrieval method.
- `stateLine`: source line of the most recent ordered receiver-state event.
- `file`: base application file path.
- `line`: assignment line where loaded origin was observed.

Validation rules:

- Receiver must be assigned from a supported known-model retrieval expression.
- Later same-receiver `save()` must not emit fresh-save write evidence.
- Loaded state is established only by ordered evidence before the observed `save()`.

State transition:

- `untracked -> loaded` after supported retrieval assignment.
- `loaded -> loaded` after supported same-receiver assignments.
- `loaded -> ambiguous` after unsupported reassignment.
- `loaded -> no fresh-save evidence` when direct same-receiver `save()` appears.

## Ambiguous Receiver Provenance

Represents any receiver origin that cannot be proven fresh within the approved static evidence boundary.

Fields:

- `receiver`: local variable name when available.
- `reason`: unsupported evidence reason, such as alias, helper origin, dynamic class, factory, relationship, dependency injection, container resolution, unsupported chain, or control-flow uncertainty.
- `file`: base application file path.
- `line`: source line where ambiguity is observed.

Validation rules:

- Ambiguous receivers must not emit fresh-save DB005 write evidence.
- Existing independently justified v0.1 findings remain valid.
- Unsupported control-flow or path-sensitive evidence makes the receiver ambiguous for this feature when the analyzer cannot prove the ordered straight-line sequence.

State transition:

- `untracked -> ambiguous` after unsupported assignment or origin.
- `fresh|loaded -> ambiguous` after unsupported reassignment.
- `ambiguous -> no fresh-save evidence` on `save()`.

## Fresh Save Write Usage

Represents the downstream DB005 evidence emitted after supported fresh-instance `save()`.

Fields:

- `table`: known model table.
- `operation`: `eloquent_fresh_save`.
- `columns`: `null`.
- `reason`: `eloquent_fresh_save_semantics`.
- `file`: source file containing the `save()`.
- `line`: source line of the `save()`.

Validation rules:

- Must be emitted only for `fresh` receiver state.
- Must not be emitted for `loaded` or `ambiguous` receiver state.
- Must be emitted only when one ordered provenance stream or traversal establishes prior same-receiver fresh evidence before the `save()`.
- Must not be emitted using future evidence, unordered `NodeFinder` collections, or path evidence outside the supported straight-line boundary.
- Must not carry literal columns because DB005 cannot prove final persisted columns.

## DB005 Finding

Represents the compatibility result when a candidate migration adds a required column without a database default and a previous-release write path may omit it.

Fields:

- `code`: `DB005`.
- `severity`: `WARNING` for fresh-save evidence.
- `confidence`: `UNKNOWN` for fresh-save evidence.
- `table`: changed table.
- `column`: added required column.
- `usage`: `Fresh Save Write Usage`.
- `change`: added column schema change.

Validation rules:

- Fresh-save evidence must enter the Eloquent uncertain branch.
- Fresh-save evidence must never enter the Query Builder definite insert branch.
- Fresh-save-only results must not make `AnalysisResult::hasDefiniteBlocker()` true.
