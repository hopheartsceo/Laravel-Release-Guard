# Public Analysis Contract: Eloquent Fresh Save DB005 Coverage

## Scope

This feature changes analysis coverage only. It does not change command names, options, configuration keys, output formats, rule codes, severity names, confidence names, or exit-code meanings.

## CLI Contract

The existing command remains:

```text
release-guard:check {--against= : Base git revision to compare against} {--paths=* : Application paths to scan} {--migrations=* : Migration paths to scan} {--format=console : Output format: console or json}
```

Exit-code behavior remains:

- `0`: no `BLOCKER` / `DEFINITE` finding exists, including runs with only fresh-save DB005 `WARNING` / `UNKNOWN` findings.
- `1`: at least one `BLOCKER` / `DEFINITE` finding exists.
- `2`: command usage or analysis error.

## Finding Contract

Fresh-instance Eloquent `save()` risk for DB005 must appear as an ordinary DB005 finding using existing public fields:

```json
{
  "code": "DB005",
  "severity": "warning",
  "confidence": "unknown",
  "table": "users",
  "column": "country_code",
  "usage": {
    "table": "users",
    "operation": "eloquent_fresh_save",
    "columns": null,
    "reason": "eloquent_fresh_save_semantics",
    "file": "app/Services/UserCreator.php",
    "line": 12
  }
}
```

The exact JSON envelope and console renderer shape remain the existing v0.1 contract. No new top-level JSON fields or finding codes are introduced.

## Compatibility Requirements

- DB001-DB006 codes remain unchanged.
- Severity vocabulary remains unchanged.
- Confidence vocabulary remains unchanged.
- Query Builder `insert` and `insertGetId` behavior remains unchanged.
- Existing Eloquent `create`, `forceCreate`, `createQuietly`, and `forceCreateQuietly` behavior remains `WARNING` / `UNKNOWN`.
- Fresh `save()` is not a definite insert and must not produce a failing exit code by itself.
