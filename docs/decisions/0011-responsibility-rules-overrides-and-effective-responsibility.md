# 0011 — ResponsibilityRule, ResponsibilityOverride et responsabilité effective

Status: **ACCEPTED**
Decision authority: GitHub issue #42
Parent epic: #35
Materialization task: #43

## Context

Decision 0004 a fixé la précédence métier `override > rule > none`. Decisions
0009 et 0010 ont ensuite matérialisé le domaine Drupal-owned, `date_recur`, la
timeline effective d'`ActivitySeries`, `ActivityException` et
`EffectiveOccurrence`. Le troisième slice applique maintenant la responsabilité
sur l'occurrence déjà corrigée par les exceptions, sans persister un résultat
ordinaire de responsabilité et sans démarrer la préparation.

## Decision

```text
RESPONSIBILITY_RULE = explicit revisionable Drupal Content Entity
RESPONSIBILITY_OVERRIDE = explicit revisionable Drupal Content Entity
EFFECTIVE_RESPONSIBILITY = calculated / no domain entity
PRECEDENCE = active override > exactly one matching rule > none
AMBIGUOUS_RULE_MATCH = FAIL_CLOSED
RULE_SCOPE = ActivitySeries
RULE_RECURRENCE = date_recur responsibility applicability windows
RULE_MATCH_TIME = EffectiveOccurrence.effectiveUtcStart
OVERRIDE_TARGET_IDENTITY = series + series revision + original occurrence key
AUTO_RETARGET_OVERRIDE = NO
```

### Canonical calculation input

Responsibility is calculated only after the existing exception overlay:

```text
base recurrence
-> ActivityException cancel/reschedule
-> EffectiveOccurrence
-> EffectiveResponsibility
```

Recurring rule matching uses `EffectiveOccurrence.effectiveUtcStart`. A
reschedule may therefore change the responsibility rule that matches. The
resolver never reconstructs responsibility from an ordinal, raw series position
or external calendar.

### ResponsibilityRule

`ResponsibilityRule` is a fieldable revisionable Content Entity owned by the
existing `personal_secretary` module. It stores:

```text
ActivitySeries reference
responsible Person reference
recurrence = date_recur applicability windows
optional effective_until UTC cutoff
lifecycle_persisted_at revision audit timestamp
```

The rule is scoped to one `ActivitySeries`. Its responsible Person must belong to
the current Household referenced by that series. Governed creation fails closed
for a non-member, and calculation repeats the membership validation so
technically corrupted/directly-written data cannot be silently ignored.

The `date_recur` field is the only source-timezone truth. No second timezone
field is introduced.

Each generated responsibility window is interpreted as:

```text
[start, end)
```

and matches when the effective occurrence start is inside that half-open window.
Every window must have positive duration.

For a single target occurrence, rule expansion is always bounded around that
target. The lower bound is shifted backwards by the persisted rule-window
duration so a window that started before the target can still be detected. No
global or unbounded RRULE scan is part of responsibility calculation.

### Rule retirement

A current rule can be retired through a new Drupal revision with an explicit
canonical UTC `effective_until` cutoff. The cutoff must be after the rule DTSTART.
A second retirement of an already-retired current revision fails closed.

Rule applicability uses the target effective occurrence start:

```text
effective start < effective_until  => rule may match
effective start >= effective_until => rule cannot match
```

The prior rule revision remains loadable and keeps its own
`lifecycle_persisted_at`. Arbitrary in-place Person/RRULE editing is not part of
this slice; replacement is modeled as retiring the old rule and creating a new
one.

### Rule ambiguity

When no active override applies:

```text
0 matching rules  => none
1 matching rule   => assigned from rule
>1 matching rules => FAIL_CLOSED
```

No priority, specificity score, last-write-wins rule or AI arbitration exists.
Two overlapping rules remain ambiguous even if they reference the same Person.

### ResponsibilityOverride

`ResponsibilityOverride` is a second fieldable revisionable Content Entity. It
stores the exact audited occurrence identity plus creation/revision context:

```text
ActivitySeries reference
target series revision id
original occurrence key
original UTC start/end
original source-local start/end
source timezone snapshot
effective UTC start/end snapshot
effective source-local start/end snapshot
ActivityException UUID/revision snapshot when present
action = assign_person | clear_responsibility
responsible Person when assigning
status = active | withdrawn
lifecycle_persisted_at revision audit timestamp
```

Creation accepts a current `EffectiveOccurrence` and reprojects a bounded window
at its effective start. Series, original/effective temporal context, timezone and
ActivityException context must match the current projection exactly. Arbitrary,
historical, cross-series or user-constructed targets therefore fail closed.

Durable matching identity is exactly:

```text
ActivitySeries identity
+ target series revision id
+ original occurrence key
```

The remaining target fields are audit and creation/revision validation context.
An ordinary reschedule that preserves that original identity does not break an
existing override even though its stored effective-time snapshot becomes
historical. If later domain evolution changes the audited identity, the old
override is inert and is never automatically retargeted.

This slice does not add override orphan/reconciliation semantics.

### Override lifecycle

`assign_person` requires a responsible Person who belongs to the series
Household. `clear_responsibility` requires no Person and explicitly wins over a
matching recurring rule.

An active override can be superseded on the same immutable target through a new
revision, or withdrawn through a new revision. Superseding refreshes the current
effective/exception audit snapshot while preserving the original target
identity/context. Withdrawing sets `status = withdrawn`; the recurring rule can
then apply again. Historical revisions and lifecycle timestamps remain loadable.

At most one current active override may exist for one composite target. Duplicate
active entities fail closed.

### EffectiveResponsibility

`EffectiveResponsibility` is an immutable calculated value, not a Drupal entity
or domain table. It exposes:

```text
state = assigned | none
source = override | rule | none
responsible Person id/uuid when assigned
rule id/revision when source=rule
override id/revision when source=override
series UUID / series revision / original occurrence key
effective UTC/local start/end
```

The deterministic resolver applies:

```text
validate current EffectiveOccurrence
-> active exact-target override
-> assign_person => assigned / override
-> clear_responsibility => none / override
-> otherwise evaluate all recurring rule windows at effectiveUtcStart
-> exactly one match => assigned / rule
-> zero matches => none / none
-> multiple matches => FAIL_CLOSED
```

No side effect, Queue, ECA, calendar or AI owns this result.

### ActivityException interaction

Existing `ActivityException` identity, orphan/reconciliation and effective
projection semantics remain unchanged. A cancellation yields no
`EffectiveOccurrence`, therefore there is no ordinary responsibility result for
that cancelled occurrence. A reschedule is evaluated at its effective start and
can cross from one responsibility window to another. Exact-target overrides win
on rescheduled occurrences as defined above.

### Audit, access and data

Both responsibility entities reuse `DomainEntityAccessControlHandler` and the
restrictive `administer personal secretary domain` permission. Semantic rule and
override revisions receive a persisted revision-specific system timestamp.
Drupal User-to-Person actor attribution is not introduced.

Decision 0005 continues to apply. Repository and CI fixtures remain
`SYNTHETIC_PUBLIC`; real personal/family data, PREPROD/PROD movement and AI
provider usage are not authorized by this slice.

## Explicit exclusions

```text
PreparationRequirement
preparation eligibility
calendar / Google Calendar
Gmail
Drupal AI/provider
ECA / Queue responsibility truth
Household-wide ResponsibilityRule
participant-specific ResponsibilityRule
PREPROD / PROD
real data
custom RRULE engine
ordinary ActivityOccurrence entity/table
ordinary EffectiveResponsibility entity/table
automatic/fuzzy override retarget
override orphan/reconciliation lifecycle
Playwright
Figma/MCP
```
