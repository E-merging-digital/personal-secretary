# Date Recur runtime spike — Stage A

Disposition: `CANDIDATE_PENDING_PROJECT_LEAD`

Authority: Epic #32 / Task #33 / Decision 0004.

## Scope

This spike evaluates `drupal/date_recur` as the preferred backend recurrence
primitive. It does not adopt the dependency finally, implement Personal
Secretary domain entities/services, or define a final exception persistence
schema.

All fixtures are synthetic.

## Candidate and runtime

- Candidate upstream: stable, Drupal Security Team-covered `date_recur` 3.9.x.
- Exact resolved version: **pending GitHub-hosted Composer materialization**.
- Runtime stack: **pending exact-head GitHub-hosted DDEV proof**.
- API exercised by the repository harness:
  - `Drupal\date_recur\DateRecurHelper::create()`;
  - `DateRecurHelperInterface::isInfinite()`;
  - `DateRecurHelperInterface::getOccurrences()`;
  - `Drupal\date_recur\DateRange::getStart()` / `getEnd()`;
  - field-item `getHelper()` for the persistence boundary.

## Runtime findings

The authoritative results in this section must be populated only from the real
GitHub-hosted DDEV run on the final Stage-A candidate.

- weekly ordering and duration: pending;
- Europe/Brussels spring DST: pending;
- Europe/Brussels autumn DST: pending;
- bounded infinite recurrence and fail-safe: pending;
- bounded expansion smoke: pending;
- field/rule storage and technical occurrence table: pending;
- cancellation overlay: pending;
- reschedule overlay: pending;
- series-edit recalculation and target stability: pending.

## Target-key question

The harness compares:

1. ordinal/index;
2. original UTC occurrence start;
3. original source-local datetime plus timezone;
4. series identity plus an original occurrence key.

Final recommendation remains pending runtime evidence. No persistence schema is
finalized in Stage A.

## Known execution limitations

Codex Cloud source authoring was invoked once for #33. Its observed environment
still has no durable Git remote/push route and no Docker/DDEV proof surface.
GitHub-hosted Composer/DDEV therefore remains the authoritative runtime and
dependency-resolution route.

## Product boundary

No `Person`, `Household`, `ResponsibilityRule`, `ResponsibilityOverride`,
production `ActivitySeries`, production `ActivityException`,
`PreparationRequirement`, calendar integration, ECA, Drupal AI/provider,
Canvas, Playwright, Figma/MCP, PREPROD/PROD/TFA, or custom RRULE engine is
implemented by this spike.

The Project Lead must independently review the final exact HEAD and decide
`ADOPT_DATE_RECUR`, `REJECT_DATE_RECUR`, or `CHANGES_REQUIRED`.
