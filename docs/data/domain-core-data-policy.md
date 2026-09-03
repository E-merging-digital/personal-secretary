# Domain core data policy — Person, Household, ActivitySeries, ActivityException, ResponsibilityRule, ResponsibilityOverride, PreparationRequirement

Authority: Decisions 0005, 0009, 0010, 0011, 0012; Epic #35 / Tasks #37, #40, #43 and #49.

This repository is public. All examples and automated-test values for these
entities are invented and classified:

```text
FIXTURE_CLASSIFICATION = SYNTHETIC_PUBLIC
```

No real family-derived, anonymized or pseudonymized dataset is authorized as a
repository fixture.

## Person

```text
DATA_CLASSIFICATION = PERSONAL for eventual real records
PII_FIELDS = id, uuid, name
SENSITIVE_FIELDS = none introduced by #37
PROD_TO_PREPROD_POLICY = FORBIDDEN absent future explicit authority
PREPROD_TO_DEV_POLICY = FORBIDDEN absent future explicit authority
RETENTION_POLICY = DEFERRED / NOT_AUTHORIZED by #37
LOGGING_POLICY = MINIMIZED; no Person payload or name by default
AI_PROVIDER_POLICY = NOT_AUTHORIZED IN #37
```

The current entity intentionally contains no email, phone, address, date of
birth, health data, documents, credentials or external-provider identifiers.

## Household

Household membership discloses a family/household relationship and is therefore
classified conservatively above an isolated Person label.

```text
DATA_CLASSIFICATION = HIGHLY_SENSITIVE for eventual real records
PII_FIELDS = id, uuid, name, member Person references
SENSITIVE_FIELDS = household membership / relationship graph
PROD_TO_PREPROD_POLICY = FORBIDDEN absent future explicit authority
PREPROD_TO_DEV_POLICY = FORBIDDEN absent future explicit authority
RETENTION_POLICY = DEFERRED / NOT_AUTHORIZED by #37
LOGGING_POLICY = MINIMIZED; no member list or household payload by default
AI_PROVIDER_POLICY = NOT_AUTHORIZED IN #37
```

## ActivitySeries

A recurring activity attached to a Household can reveal routine and family
context. Its real-data classification is therefore conservative.

```text
DATA_CLASSIFICATION = HIGHLY_SENSITIVE for eventual real records
PII_FIELDS = id, uuid, label, Household reference
SENSITIVE_FIELDS = recurrence start/end, RRULE, source timezone, household linkage, effective-from revision timeline
PROD_TO_PREPROD_POLICY = FORBIDDEN absent future explicit authority
PREPROD_TO_DEV_POLICY = FORBIDDEN absent future explicit authority
RETENTION_POLICY = DEFERRED / NOT_AUTHORIZED by #37/#40
LOGGING_POLICY = MINIMIZED; no recurrence/family payload by default
AI_PROVIDER_POLICY = NOT_AUTHORIZED IN #37/#40
```

## ActivityException

An exception exposes a specific family routine occurrence and its explicit
cancellation or rescheduling history, so eventual real records are classified
conservatively.

```text
DATA_CLASSIFICATION = HIGHLY_SENSITIVE for eventual records
PII_FIELDS = id, uuid, ActivitySeries reference
SENSITIVE_FIELDS = target revision/key, original UTC/local times, source timezone snapshot, action/status, revision lifecycle persisted-at timestamp, rescheduled UTC times
PROD_TO_PREPROD_POLICY = FORBIDDEN absent future explicit authority
PREPROD_TO_DEV_POLICY = FORBIDDEN absent future explicit authority
RETENTION_POLICY = DEFERRED / NOT_AUTHORIZED by #40
LOGGING_POLICY = MINIMIZED; no exception target/time/audit payload by default
AI_PROVIDER_POLICY = NOT_AUTHORIZED IN #40
```

Exception source-local/timezone fields are immutable audit snapshots of a
calculated target. They do not become a second recurrence-timezone truth.

`lifecycle_persisted_at` is revision-specific lifecycle audit metadata. It records
when an ActivityException revision was persisted; it is not an occurrence time,
reschedule time, ActivitySeries effective-from boundary or recurrence DTSTART.
Historical exception revisions retain their own stored value.

## ResponsibilityRule

A recurring responsibility rule reveals who is expected to handle a family
routine and when. Eventual real records are therefore highly sensitive.

```text
DATA_CLASSIFICATION = HIGHLY_SENSITIVE for eventual real records
PII_FIELDS = id, uuid, ActivitySeries reference, responsible Person reference
SENSITIVE_FIELDS = responsible Person assignment, recurring applicability start/end/RRULE/source timezone, effective-until cutoff, revision lifecycle persisted-at timestamp
PROD_TO_PREPROD_POLICY = FORBIDDEN absent future explicit authority
PREPROD_TO_DEV_POLICY = FORBIDDEN absent future explicit authority
RETENTION_POLICY = DEFERRED / NOT_AUTHORIZED by #43
LOGGING_POLICY = MINIMIZED; no responsibility assignment/window payload by default
AI_PROVIDER_POLICY = NOT_AUTHORIZED IN #43
```

The `date_recur` recurrence item's timezone remains the sole canonical source
timezone for rule applicability windows. No separate ResponsibilityRule timezone
truth is introduced.

## ResponsibilityOverride

An override records an explicit responsibility decision for one audited family
routine occurrence, including original/effective timing context. Eventual real
records are highly sensitive.

```text
DATA_CLASSIFICATION = HIGHLY_SENSITIVE for eventual real records
PII_FIELDS = id, uuid, ActivitySeries reference, responsible Person reference when assigning
SENSITIVE_FIELDS = target series revision/key, original/effective UTC/local times, source timezone snapshot, ActivityException UUID/revision snapshot, action/status, revision lifecycle persisted-at timestamp
PROD_TO_PREPROD_POLICY = FORBIDDEN absent future explicit authority
PREPROD_TO_DEV_POLICY = FORBIDDEN absent future explicit authority
RETENTION_POLICY = DEFERRED / NOT_AUTHORIZED by #43
LOGGING_POLICY = MINIMIZED; no override target/responsibility/time/audit payload by default
AI_PROVIDER_POLICY = NOT_AUTHORIZED IN #43
```

Override original/effective/timezone/exception fields are audit and validation
snapshots. Matching remains based on series identity, target series revision and
original occurrence key; no automatic/fuzzy retargeting is authorized.

## PreparationRequirement

A preparation requirement reveals a household routine's reusable preparation
instruction and timing. Eventual real records are therefore classified
conservatively.

```text
DATA_CLASSIFICATION = HIGHLY_SENSITIVE for eventual real records
PII_FIELDS = id, uuid, label, ActivitySeries reference
SENSITIVE_FIELDS = preparation instruction, lead time, effective-from/effective-until applicability, revision lifecycle persisted-at timestamp
PROD_TO_PREPROD_POLICY = FORBIDDEN absent future explicit authority
PREPROD_TO_DEV_POLICY = FORBIDDEN absent future explicit authority
RETENTION_POLICY = DEFERRED / NOT_AUTHORIZED by #49
LOGGING_POLICY = MINIMIZED; no preparation instruction/timing payload by default
AI_PROVIDER_POLICY = NOT_AUTHORIZED IN #49
```

Ordinary `PreparationEligibility` results are calculated values, not persisted
domain records. Their responsible Person comes from the existing calculated
`EffectiveResponsibility` and is not copied into a durable preparation row.

## Environment and egress invariant

```text
PROD_TO_PREPROD = FORBIDDEN absent future explicit authority
PREPROD_TO_DEV = FORBIDDEN absent future explicit authority
LOGGING = MINIMIZED / NO FAMILY PAYLOAD BY DEFAULT
AI_PROVIDER_USAGE = NOT_AUTHORIZED IN #37/#40/#43/#49
```

```text
REAL_PERSONAL_DATA = NONE
REAL_FAMILY_DATA = NONE
REAL_CHILD_DATA = NONE
REAL_EMAIL = NONE
REAL_DOCUMENT = NONE
REAL_FINANCE = NONE
REAL_CREDENTIAL = NONE
```

No retention duration is invented here. A future real-data authorization must
set and justify retention, sanitization, environment and egress controls before
such data is introduced.
