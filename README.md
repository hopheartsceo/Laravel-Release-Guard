# Laravel Release Guard

Laravel Release Guard is a static deployment compatibility analyzer for Laravel applications.

It checks supported database changes for incompatibilities between the release you are preparing and the application version that is already running during a rolling or zero-downtime deployment.

It does not execute migrations, boot the previous application revision, or run application endpoints.

Instead, it compares the previous Git revision with the candidate working tree and reports evidence-backed compatibility findings.

## Why

During a rolling deployment, the old and new application versions may run at the same time.

A migration that is valid for the new code can still break requests handled by the previous release.

For example:

```php
Schema::table('users', function (Blueprint $table) {
    $table->dropColumn('phone');
});
```

while the currently deployed release still contains:

```php
User::query()
    ->where('phone', $phone)
    ->first();
```

Laravel Release Guard detects this compatibility problem before deployment.

## Installation

Install the package as a development / CI dependency:

```bash
composer require --dev hopheartsceo/laravel-release-guard
```

Laravel package discovery registers the service provider automatically.

## Quick Start

Compare the candidate working tree against the revision currently deployed:

```bash
php artisan release-guard:check --against=origin/main
```

You can use any Git revision that resolves to a commit:

```bash
php artisan release-guard:check --against=HEAD~1
php artisan release-guard:check --against=v1.2.3
php artisan release-guard:check --against=origin/master
```

For CI or other machine consumers:

```bash
php artisan release-guard:check \
    --against=origin/main \
    --format=json
```

## How It Works

```text
Git base revision
        |
        v
Base Laravel database usage index
        |
        +----------------------+
                               |
Candidate migration changes   |
        |                      |
        v                      v
       Schema delta --> Compatibility engine
                               |
                               v
                     Evidence-backed findings
                               |
                               v
                      Console / JSON / CI exit
```

Laravel Release Guard analyzes the previous application revision directly from Git without checking it out.

Candidate migrations come from changes in the current working tree relative to the selected base revision, including supported untracked migration files.

## Database Rules

| Code | Rule | Purpose |
|---|---|---|
| `DB001` | Dropped column still referenced | Detects a removed column still used by the previous application |
| `DB002` | Renamed column still referenced | Detects the previous application still using the old column name |
| `DB003` | Dropped table still referenced | Detects a removed table still used by the previous application |
| `DB004` | Renamed table still referenced | Detects the previous application still using the old table name |
| `DB005` | Required column breaks base writes | Detects newly required columns that supported previous write paths cannot satisfy |
| `DB006` | Unanalyzable migration operation | Reports migration operations that cannot be classified safely |

Rules can be individually disabled in configuration.

## Findings

Every finding has two important dimensions:

### Severity

- `BLOCKER` — deployment compatibility problem
- `WARNING` — requires review
- `INFO` — informational result

### Confidence

- `DEFINITE` — supported static evidence establishes the result
- `PROBABLE` — strong evidence exists but certainty is limited
- `UNKNOWN` — the analyzer intentionally cannot prove the result

Laravel Release Guard prefers `UNKNOWN` over fabricated certainty.

Only a `BLOCKER` with `DEFINITE` confidence fails compatibility CI.

## Exit Codes

The command uses a stable CI-oriented exit-code contract:

| Exit code | Meaning |
|---|---|
| `0` | No definite incompatibility was detected within the analyzed scope |
| `1` | At least one definite blocker was detected |
| `2` | CLI, configuration, Git, or analyzer error |

Exit code `0` does **not** mean that a deployment has been proven universally safe.

The precise guarantee is:

> No incompatible changes detected within the analyzed scope.

## Console Output

A successful analysis can look like:

```text
Laravel Release Guard
Base revision: <commit-sha>
Base application files: 42
Candidate migration files: 1

No incompatible changes detected within the analyzed scope.
```

A detected compatibility problem can look like:

```text
[BLOCKER][DEFINITE][DB001] users.phone — app/Services/UserLookup.php:27
```

## JSON Output

Use:

```bash
php artisan release-guard:check \
    --against=origin/main \
    --format=json
```

The result is machine-readable:

```json
{
    "status": "no_definite_incompatibility_detected",
    "base_revision": "<commit-sha>",
    "base_application_files": 42,
    "candidate_migration_files": 1,
    "findings": []
}
```

When a definite blocker exists, `status` becomes:

```json
{
    "status": "incompatible"
}
```

Analyzer or Git failures also remain machine-readable when JSON output is requested:

```json
{
    "status": "error",
    "error": "Analysis failed: ..."
}
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish \
    --tag=release-guard-config
```

The default configuration is:

```php
return [
    'paths' => [
        'application' => [
            'app',
        ],

        'migrations' => [
            'database/migrations',
        ],
    ],

    'confidence' => [
        'fail_on' => 'definite',
    ],

    'rules' => [
        'DB001' => true,
        'DB002' => true,
        'DB003' => true,
        'DB004' => true,
        'DB005' => true,
        'DB006' => true,
    ],
];
```

Application and migration paths can be adjusted for non-standard project layouts.

## Conservative Analysis

Laravel Release Guard does not assume that dynamic Laravel behavior is deterministic.

For example, a direct Query Builder insert with a statically known payload can provide strong evidence about the columns being written.

An Eloquent call such as:

```php
User::create([
    'name' => $name,
    'email' => $email,
]);
```

does not necessarily reveal the final SQL insert columns.

Mass-assignment configuration, model defaults, events, mutators, and other model behavior can affect persistence.

For cases like this, Laravel Release Guard intentionally reports uncertainty instead of promoting incomplete evidence to a definite blocker.

For `DB005`, supported write paths include direct Query Builder `insert` / `insertGetId`, the existing Eloquent `create` family, and a narrow fresh-instance Eloquent `save()` pattern:

```php
$user = new User([
    'name' => $name,
    'email' => $email,
]);

$user->save();
```

Fresh-instance `save()` coverage is intentionally limited to direct construction of a known model, optional supported assignments on the same receiver, and a later direct `save()` on that receiver. It is reported as `WARNING` / `UNKNOWN`, remains non-blocking by itself, and does not change Query Builder insert classification.

Loaded-model saves, ambiguous receiver provenance, aliases, helper-created models, factories, relationship saves, dependency injection, container resolution, dynamic model classes, `saveOrFail()`, and `push()` are not treated as fresh inserts.

The same principle applies to dynamic table names, dynamic column names, dynamic write payloads, and unsupported raw migration operations.

## Analysis Scope

Laravel Release Guard focuses on database compatibility during rolling and zero-downtime Laravel deployments.

It statically analyzes supported Laravel migration operations and supported application database usage patterns.

It is not intended to prove every possible deployment risk.

In particular, it does not execute application code or migrations, and it does not claim to model every runtime, infrastructure, operational, locking, data-backfill, queue, cache, or serialized-payload compatibility concern.

These are separate deployment concerns and may be addressed by future analysis capabilities.

## Git Safety

Laravel Release Guard treats Git input as untrusted analyzer input.

Revision arguments are verified as commits before object lookup.

Working-tree files are restricted to the repository boundary, including protection against traversal, absolute-path access, and symlink escape.

Git failures are exposed through concise analyzer errors rather than raw process command output.

## CI Example

A minimal GitHub Actions step can run:

```yaml
- name: Check rolling deployment compatibility
  run: |
    git fetch origin main
    php artisan release-guard:check --against=origin/main
```

The command naturally fails the CI job only when a definite blocker is detected or when analysis itself cannot complete.

## Requirements

- PHP 8.1 or later
- Laravel 10, 11, 12, or 13
- Git available in the execution environment

## Development

Install dependencies:

```bash
composer install
```

Run the complete test suite:

```bash
composer test
```

## Design Principle

The project follows one central rule:

> Prefer UNKNOWN to invented certainty.

A static deployment analyzer is useful only when teams can trust the distinction between what it knows and what it cannot prove.

## License

Laravel Release Guard is open-source software licensed under the MIT License.
