<!--
Sync Impact Report
Version change: template scaffold -> 1.0.0
Modified principles:
- Template principle 1 -> I. Evidence Before Certainty
- Template principle 2 -> II. v0.1 Compatibility Baseline
- Template principle 3 -> III. Test-First Compatibility Rules
- Template principle 4 -> IV. Public Contracts Are Stable
- Template principle 5 -> V. Narrow Static Analysis
Added sections:
- Release Scope and Support Matrix
- Development Workflow and Quality Gates
Removed sections:
- None
Follow-up TODOs:
- None
-->
# Laravel Release Guard Constitution

## Core Principles

### I. Evidence Before Certainty

Laravel Release Guard MUST report definite breaking-change findings only when supported
static evidence establishes the result within the documented analysis scope. When evidence
is incomplete, dynamic, or unsupported, rules MUST prefer `UNKNOWN` confidence or
`WARNING` severity over a false `BLOCKER` / `DEFINITE` claim. A known false definite
blocker is a release stop condition because it weakens user trust in CI results.

Rationale: The project is useful only when teams can distinguish what the analyzer knows
from what it cannot prove.

### II. v0.1 Compatibility Baseline

The released `v0.1.0` behavior is the compatibility baseline. The baseline commit is
`80bd17da2ef34b511689c287a13944b8da6ea1a7`, released with 199 tests and 673
assertions. New work MUST preserve the documented v0.1 CLI command, JSON shape,
finding codes `DB001` through `DB006`, severity and confidence vocabulary, exit codes,
configuration keys, and package support unless a versioned change explicitly justifies
the contract change.

Rationale: Laravel Release Guard is intended for CI use; undocumented output or exit-code
drift can break consumers even when analysis internals still function.

### III. Test-First Compatibility Rules

New compatibility rules, broadened detection behavior, and changes to confidence or
severity classification MUST begin with failing regression tests that encode the intended
evidence boundary. Tests MUST cover both the positive detection case and the conservative
non-blocking case when uncertainty is possible. Existing v0.1 validation scenarios MUST
remain protected from regression.

Rationale: The analyzer's risk is misclassification, so rule behavior must be specified
in executable examples before implementation changes.

### IV. Public Contracts Are Stable

Console output, JSON output, exit-code semantics, rule codes, configuration names, package
metadata, and supported Laravel/PHP constraints are public contracts. A contract change
MUST be documented in the changelog and README, reflected in tests, and versioned according
to semantic versioning. The success message
`No incompatible changes detected within the analyzed scope.` MUST remain exact unless a
versioned contract change replaces it.

Rationale: CI systems and release procedures depend on stable machine-readable and
human-readable behavior.

### V. Narrow Static Analysis

Implementation MUST stay simple, static, and narrowly scoped to evidence available from
the selected Git base revision and candidate working tree. The project MUST NOT execute
application code, run migrations, boot the previous application revision, or model
speculative Laravel runtime behavior as fact. Analyzer additions SHOULD reuse the existing
pipeline, domain objects, rule model, and PHP parser approach unless a narrower alternative
cannot represent the evidence safely.

Rationale: Conservative static analysis is the product boundary; speculative framework
modeling creates false certainty and unnecessary maintenance cost.

## Release Scope and Support Matrix

Laravel Release Guard analyzes database compatibility risks for Laravel rolling and
zero-downtime deployments. It does not claim universal deployment safety and does not cover
runtime, infrastructure, operational, locking, data-backfill, queue, cache, or serialized
payload risks unless future documented rules add explicit support.

The supported CI matrix is Laravel 10, 11, 12, and 13 with PHP versions compatible with
the package constraints. A release MUST NOT be tagged while the supported matrix is failing.
Composer validation, PHP syntax checks, and the complete test suite are required release
gates.

Evidence for new rule behavior MUST come from one or more of:

- existing repository documentation, tests, and release history;
- reduced fixtures that model real Laravel code paths;
- completed evidence sweeps across real repositories;
- upstream Laravel behavior documented by authoritative sources.

Unsupported or insufficiently evidenced behavior MUST remain non-blocking.

## Development Workflow and Quality Gates

Changes MUST preserve the repository's existing architecture unless the current design
cannot support the required evidence boundary. New analyzer behavior MUST be regression
tested at the narrowest useful level and, when it affects end-to-end analysis or public
contracts, at integration or feature level.

Before release or merge of compatibility behavior, maintainers MUST review:

- whether definite blockers are backed by static evidence;
- whether uncertain scenarios remain `UNKNOWN`, `WARNING`, or otherwise non-blocking;
- whether public CLI, JSON, exit-code, configuration, and package contracts remain stable;
- whether the Laravel 10-13 CI matrix, `composer validate --strict`, syntax checks, and
  `composer test` pass;
- whether documentation and changelog entries match implemented behavior.

## Governance

This constitution supersedes informal project practices for compatibility analysis,
release risk classification, public contracts, testing, and release gates. Specifications,
plans, tasks, reviews, and releases MUST demonstrate compliance with these principles.

Amendments MUST be made in `.specify/memory/constitution.md` only, include a Sync Impact
Report, and explain any change that weakens a previous rule. Amendments MUST be reviewed
against the v0.1.0 baseline and current release documentation before related feature work
is implemented.

Versioning follows semantic versioning:

- MAJOR: removes or redefines a core principle, weakens baseline compatibility protection,
  or allows previously prohibited public-contract breakage.
- MINOR: adds a principle or materially expands governance obligations.
- PATCH: clarifies wording, fixes errors, or reorganizes text without changing obligations.

Compliance review is required for every feature specification and implementation plan.
When a proposed change conflicts with this constitution, the constitution MUST be amended
first or the proposal MUST be revised.

**Version**: 1.0.0 | **Ratified**: 2026-08-24 | **Last Amended**: 2026-08-24
