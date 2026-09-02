# 0009 — Persistance du noyau métier du premier vertical

Status: **ACCEPTED**  
Decision authority: GitHub issue #36  
Parent epic: #35  
Materialization task: #37

## Context

Les décisions précédentes ont établi Drupal comme source de vérité produit,
`date_recur` / RRULE comme primitive de récurrence et les occurrences ordinaires
comme calculées par défaut. Le premier slice métier doit désormais matérialiser
les objets minimaux qui possèdent cette vérité sans détourner `node`, `user`,
taxonomy ou une projection technique de leur fonction.

## Decision

```text
DOMAIN_PERSISTENCE = Drupal Core fieldable Content Entity API
CUSTOM_MODULE = personal_secretary

PERSON = explicit domain Content Entity
HOUSEHOLD = explicit domain Content Entity
ACTIVITY_SERIES = explicit revisionable domain Content Entity

PERSON != DRUPAL_USER
ORDINARY_ACTIVITY_OCCURRENCE = CALCULATED

RECURRENCE = date_recur / RRULE
SOURCE_TIMEZONE = EXPLICIT / CANONICAL
INFINITE_RECURRENCE = ALWAYS BOUNDED
ORDINAL_ONLY_TARGETING = PROHIBITED
```

### Drupal Core persistence boundary

The `personal_secretary` custom module owns the first domain entities. They use
Drupal Core `ContentEntityBase` and base fields directly; no Node bundle,
Taxonomy vocabulary, Paragraphs model, Drupal User substitution, generic custom
persistence framework, or new persistence contrib dependency is introduced.

`Person` contains only stable entity identity, UUID and a human-readable name.
It has no Drupal User reference in this slice. A future User-to-Person relation
requires separate authority.

`Household` contains stable identity, UUID, name and structured entity references
to `Person` members. Membership is domain state rather than a role, free-text
name or external participant list.

`ActivitySeries` contains stable identity, UUID, name, a Household reference and
a `date_recur` recurrence field. Participant references are not added because
this slice does not demonstrate a requirement distinct from Household
membership.

### ActivitySeries revisions

`ActivitySeries` is revisionable from its first schema. Its semantic base fields
are revisionable and governed mutation code creates a new Drupal revision when
recurrence semantics are updated.

The revision identifier is exposed to bounded occurrence projections as audit
context. This decision does **not** claim to implement effective-from semantics,
historical occurrence reconstruction, semantic edit reconciliation or orphaned
`ActivityException` handling. Those remain a later bounded slice.

### Canonical source timezone

The canonical recurrence timezone is the `timezone` property stored by the
`date_recur` field item itself. Start/end values are persisted in Drupal's
expected UTC representation while the source timezone remains explicit in the
same recurrence item.

```text
DUPLICATED_TIMEZONE_TRUTH = NO
```

No independently editable Personal Secretary timezone field is introduced.
Future code must consume the `date_recur` timezone property as the source-timezone
truth unless a separately reviewed blocker proves this contract untenable.

### Calculated occurrences

The module provides a deterministic read/application service that accepts one
persisted `ActivitySeries` plus an explicit bounded window and/or positive limit
and returns calculated base-occurrence value objects.

No product API enumerates an infinite recurrence without a bound. A request
without a complete window and without a positive limit fails closed.

Each projected occurrence carries conceptually:

```text
series UUID
series revision ID
original occurrence key derived from the original UTC start instant
UTC start/end
source-local start/end
source timezone
```

The original key is recurrence-derived, never the occurrence's transient list
ordinal. No `ActivityOccurrence` Content Entity or domain database table is
created. Technical `date_recur` occurrence/index/cache rows remain implementation
projections and never become Personal Secretary domain truth.

### Mutation and access boundary

Product writes introduced by this slice converge through the small
`DomainMutationService`. It validates referenced Persons/Household, recurrence
shape, duration and the single canonical source timezone before using Drupal's
Entity API. This is not a command bus, workflow engine or agent tool layer.

The entities use a dedicated restrictive permission,
`administer personal secretary domain`, for create/update/delete access. No
public administration form or frontend write route is introduced in this slice.
Anonymous and ordinary unauthorized writes therefore remain denied.

## Consequences

- Drupal-owned fieldable Content Entities now hold the first real domain state.
- Person and Household remain deliberately minimal.
- ActivitySeries revision context exists before future exception semantics.
- `date_recur` remains the recurrence engine; no alternative is re-evaluated.
- Source timezone has one canonical persisted truth.
- Ordinary occurrences remain calculated and bounded.
- `ActivityException`, responsibility, preparation and calendar projection remain
  outside this decision/task.
