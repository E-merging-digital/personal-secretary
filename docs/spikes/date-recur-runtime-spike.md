# Date Recur runtime spike — Stage A

Disposition: `CANDIDATE_PENDING_PROJECT_LEAD`

Authority: Epic #32 / Task #33 / Decision 0004.

## Scope

This spike evaluates `drupal/date_recur` as the preferred backend recurrence
primitive. It does not adopt the dependency finally, implement Personal
Secretary domain entities/services, or define a final exception persistence
schema.

All fixtures and runtime data are synthetic.

## Candidate and tested runtime

The upstream project was reloaded before finalization. `date_recur` 3.9.3 remains
a stable release covered by the Drupal Security Team and compatible with Drupal
`^11`.

```text
DATE_RECUR_RESOLVED_VERSION = 3.9.3
RRULE_BACKEND = rlanvin/php-rrule 2.6.0
DRUPAL = 11.4.5
DDEV = 1.25.4
PHP = 8.5.9
DATABASE = MariaDB 11.8.9
DRUSH = 13.7.6
```

The real GitHub-hosted DDEV runtime exercised:

- `Drupal\date_recur\DateRecurHelper::create()`;
- `DateRecurHelperInterface::isInfinite()`;
- `DateRecurHelperInterface::getOccurrences()`;
- `Drupal\date_recur\DateRange::getStart()` / `getEnd()`;
- field-item `getHelper()` for the persistence boundary.

The resolved candidate is a production Composer dependency. No alternative
recurrence package or UI module was added.

## Weekly RRULE

A synthetic weekly series was generated and its occurrences inspected.

```text
WEEKLY_RRULE = PASS
CHRONOLOGICAL_OCCURRENCES = PASS
DURATION_PRESERVED = PASS / 2700 seconds
```

The tested rule generated five chronologically ordered occurrences and preserved
a 45-minute duration for every occurrence.

## Europe/Brussels DST

The recurrence source timezone was explicitly `Europe/Brussels`, while the CI
process default timezone was `Etc/UTC`. The generated local times prove that the
recurrence source timezone governed the calculation rather than the process
timezone.

### Spring transition — 2026-03-29

Observed local starts include:

```text
2026-03-22 18:00 +01:00
2026-03-29 18:00 +02:00
2026-04-05 18:00 +02:00
```

The corresponding transition changes the UTC start from 17:00Z before DST to
16:00Z after DST while preserving the intended 18:00 source-local wall clock.

```text
SPRING_DST_LOCAL_TIME_STABLE = PASS
SPRING_DST_UTC_OFFSET_TRANSITION = PASS / +01:00 -> +02:00
```

### Autumn transition — 2026-10-25

Observed local starts include:

```text
2026-10-18 18:00 +02:00
2026-10-25 18:00 +01:00
2026-11-01 18:00 +01:00
```

The corresponding transition changes the UTC start from 16:00Z before the
transition to 17:00Z after it while preserving the intended 18:00 source-local
wall clock.

```text
AUTUMN_DST_LOCAL_TIME_STABLE = PASS
AUTUMN_DST_UTC_OFFSET_TRANSITION = PASS / +02:00 -> +01:00
```

## Infinite recurrence safety and bounded expansion

The synthetic infinite rule was `FREQ=WEEKLY;INTERVAL=1` with no `COUNT` or
`UNTIL`.

```text
INFINITE_RULE_DETECTED = PASS
BOUNDED_GENERATION = PASS
UNBOUNDED_GENERATION_PATH = NOT_USED
```

An unsafe request without a date or count limit failed closed with:

```text
InvalidArgumentException: An infinite rule must have a date or count limit.
```

A bounded smoke expanded 1000 occurrences in approximately `0.014282 s` in the
observed GitHub-hosted runtime. No material resource or execution pathology was
observed. This is an observation only and is not a future hard performance
threshold.

## Persistence boundary

The runtime fixture observed the following durable field values:

```text
value
end_value
rrule
timezone
infinite
```

The persisted synthetic rule was `FREQ=WEEKLY;INTERVAL=1;COUNT=4` with timezone
`Europe/Brussels`. Four occurrences were calculated through the recurrence API.

`date_recur` also maintained the technical occurrence table:

```text
date_recur__node__field_date_recur_spike
```

Four technical occurrence rows were observed for the four generated
occurrences.

```text
ordinary occurrence Drupal entity created = NO
ORDINARY_OCCURRENCE_DOMAIN_ENTITY_REQUIRED = NO
```

These technical occurrence rows/cache/index entries are implementation
projections for recurrence/query support. They are **not Personal Secretary
domain truth** and do not require ordinary occurrences to become durable domain
entities. The Decision 0004 boundary — ordinary occurrences calculated by
default — remains compatible with the observed runtime.

## Synthetic exception-overlay feasibility

The harness applied external deterministic test-only overlays to generated base
occurrences without changing the source RRULE.

```text
CANCEL_OVERLAY = PASS
RESCHEDULE_OVERLAY = PASS
BASE_RRULE_UNCHANGED = PASS
```

This proves feasibility only. No production `ActivityException`,
`ActivitySeries` service, or domain overlay implementation is introduced by
this Task.

## Occurrence target-key evaluation

The runtime compared ordinal/index targeting, original UTC occurrence start,
source-local datetime plus timezone, and a future series identity plus original
occurrence key shape.

Ordinal/index is not sufficiently stable. In the observed edit scenario, the
same occurrence target moved from zero-based ordinal `2` to ordinal `3` after an
earlier `DTSTART` was inserted.

The original UTC occurrence instant survived that insertion case. However, after
a semantic recurrence edit such as changing `BYDAY`, the previous occurrence
instant may disappear entirely from the regenerated base series.

Stage-A recommendation:

```text
series identity
+ original occurrence key
+ original UTC instant
+ original source-local datetime
+ source timezone
+ series revision/version/effective-from context
```

This is an evidence-based targeting recommendation, not a final persistence
schema. The source-local representation preserves wall-clock/DST intent; the UTC
instant remains unambiguous for an occurrence that still exists; the series
identity and future revision context are needed to make edits auditable.

## Series-edit behavior

The runtime proved that changing source series semantics regenerates the base
occurrence set.

```text
SERIES_EDIT_RECALCULATES_BASE = PASS
TARGET_STABILITY_OBSERVED = ordinal changed after earlier DTSTART insertion; original UTC key survived that insertion but disappeared after semantic BYDAY edit
ORPHAN_EXCEPTION_RISK = FOUND
```

Future `ActivitySeries` design should therefore evaluate:

```text
revision/version
effective_from
auditable semantic series edits
explicit orphan-exception handling
```

Those mechanisms are recommendations for a later bounded domain Task and are not
implemented here.

## Execution and validation boundary

Codex source authoring was invoked once for Task #33. Its observed environment
had no durable Git remote/push route and no Docker/DDEV proof surface. The real
Composer resolution and runtime proof were therefore performed on the
GitHub-hosted DDEV capability.

The durable read-only `.github/workflows/drupal.yml` invokes `scripts/verify`,
which executes this repository-owned spike harness during development and
production rebuild validation. The temporary repository-write materialization
workflow is not part of the final Stage-A candidate.

## Product and data boundary

No real personal, family, child, email, document, finance, credential, PREPROD or
PROD data was used.

No `Person`, `Household`, `ResponsibilityRule`, `ResponsibilityOverride`,
production `ActivitySeries`, production `ActivityException`,
`PreparationRequirement`, calendar integration, ECA, Drupal AI/provider,
Canvas, Playwright, Figma/MCP, PREPROD/PROD/TFA, or custom RRULE engine is
implemented by this spike.

Decision 0004 remains unchanged. The Project Lead must independently review the
final exact HEAD and decide `ADOPT_DATE_RECUR`, `REJECT_DATE_RECUR`, or
`CHANGES_REQUIRED`.
