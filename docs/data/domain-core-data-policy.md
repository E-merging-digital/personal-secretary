# Domain core data policy — Person, Household, ActivitySeries

Authority: Decision 0005, Decision 0009, Epic #35 / Task #37.

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
SENSITIVE_FIELDS = recurrence start/end, RRULE, source timezone, household linkage
PROD_TO_PREPROD_POLICY = FORBIDDEN absent future explicit authority
PREPROD_TO_DEV_POLICY = FORBIDDEN absent future explicit authority
RETENTION_POLICY = DEFERRED / NOT_AUTHORIZED by #37
LOGGING_POLICY = MINIMIZED; no recurrence/family payload by default
AI_PROVIDER_POLICY = NOT_AUTHORIZED IN #37
```

## Environment and egress invariant

```text
PROD_TO_PREPROD = FORBIDDEN absent future explicit authority
PREPROD_TO_DEV = FORBIDDEN absent future explicit authority
LOGGING = MINIMIZED / NO FAMILY PAYLOAD BY DEFAULT
AI_PROVIDER_USAGE = NOT_AUTHORIZED IN #37
```

No retention duration is invented here. A future real-data authorization must
set and justify retention, sanitization, environment and egress controls before
such data is introduced.
