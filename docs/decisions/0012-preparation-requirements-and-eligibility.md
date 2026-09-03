# 0012 — PreparationRequirement et éligibilité de préparation

Status: **ACCEPTED**
Decision authority: GitHub issue #48
Parent epic: #35
Materialization task: #49

## Context

Le noyau interne sait déjà projeter une `EffectiveOccurrence` après
`ActivityException` puis calculer une `EffectiveResponsibility` déterministe selon
`override > rule > none`. Le dernier slice interne doit répondre sans nouvelle
projection externe à une question produit simple : quoi préparer, par quelle
Person responsable, et quand.

## Decision

```text
PREPARATION_REQUIREMENT = explicit revisionable Drupal Content Entity
REQUIREMENT_SCOPE = ActivitySeries
PREPARATION_ELIGIBILITY = calculated / no domain entity
RESPONSIBILITY_INPUT = existing EffectiveResponsibility
ELIGIBLE_ONLY_IF = EffectiveResponsibility.state == assigned
RESPONSIBLE_PERSON = EffectiveResponsibility assigned Person
REQUIREMENT_MATCH_TIME = EffectiveOccurrence.effectiveUtcStart
RESCHEDULE = use effective occurrence time
CANCELLED_OCCURRENCE = no preparation eligibility result
CLEAR_OR_NO_RESPONSIBILITY = no eligible preparation
MULTIPLE_REQUIREMENTS = additive
DERIVED_PREPARATION_PERSISTENCE = NONE
```

### PreparationRequirement

`PreparationRequirement` est une Content Entity Drupal fieldable et revisionable
portée par le module `personal_secretary`. Elle stocke uniquement :

```text
ActivitySeries reference
label = short preparation instruction
lead_time_seconds >= 0
effective_from = required canonical UTC instant
effective_until = optional canonical UTC instant
lifecycle_persisted_at = required revision audit timestamp
```

Aucune récurrence supplémentaire n'est introduite. Une exigence s'applique à une
occurrence lorsque :

```text
effective_from <= EffectiveOccurrence.effectiveUtcStart
AND
(effective_until is null OR EffectiveOccurrence.effectiveUtcStart < effective_until)
```

### Lifecycle

La frontière de mutation gouvernée fournit uniquement :

```text
createPreparationRequirement(...)
retirePreparationRequirement(...)
```

La retraite crée une nouvelle révision, conserve la révision précédente,
rafraîchit `lifecycle_persisted_at` et exige `effective_until > effective_from`.
Une exigence déjà bornée/retirée ne peut pas être retirée une seconde fois.
Les modifications arbitraires en place de scope, label ou lead time ne font pas
partie de ce slice ; un remplacement s'exprime par retraite + nouvelle exigence.

### Éligibilité calculée

Le service déterministe consomme exactement :

```text
ActivitySeries + current EffectiveOccurrence
-> existing EffectiveResponsibilityService
-> applicable PreparationRequirement(s)
-> zero or more immutable PreparationEligibility values
```

Si la responsabilité effective est `none`, y compris via un override `CLEAR`, le
résultat est vide. Le service n'invente jamais une responsabilité et propage tout
échec fail-closed du resolver existant.

Pour chaque exigence applicable avec responsabilité `assigned`, le résultat
calculé conserve au minimum :

```text
PreparationRequirement id + revision + label
ActivitySeries uuid + effective series revision
original occurrence key
responsible Person id/uuid
effective UTC/local occurrence start/end
lead_time_seconds
due_at_utc = EffectiveOccurrence.effectiveUtcStart - lead_time_seconds
```

Plusieurs exigences applicables sont additives et produisent plusieurs résultats
calculés ; elles ne constituent pas une ambiguïté.

### ActivityException interaction

La préparation est dérivée après la projection effective :

```text
base recurrence
-> ActivityException cancel/reschedule
-> EffectiveOccurrence
-> EffectiveResponsibility
-> PreparationEligibility
```

Une replanification utilise donc l'heure effective pour l'applicabilité de
l'exigence et le calcul de `due_at_utc`. Une annulation ne produit pas
`EffectiveOccurrence`, donc aucun résultat de préparation ordinaire n'existe pour
cette occurrence.

### Persistance, accès et données

Seule l'exigence réutilisable est persistée. Les résultats ordinaires de
préparation restent des valeurs calculées : aucun `PreparationOccurrence`,
`Reminder`, `Task` ou row d'éligibilité ordinaire n'est créé.

`PreparationRequirement` réutilise `DomainEntityAccessControlHandler`, la
permission restrictive `administer personal secretary domain` et la convention
de timestamp de lifecycle déjà adoptée. Aucun mapping Drupal User -> Person n'est
introduit.

Decision 0005 continue de s'appliquer. Le dépôt et les tests restent
`SYNTHETIC_PUBLIC`; aucune donnée personnelle/familiale réelle, aucun flux
PREPROD/PROD et aucun provider IA ne sont autorisés par ce slice.

## Exclusions explicites

```text
calendar projection/integration
Google Calendar
Gmail
Drupal AI/provider
ECA/Queue preparation truth
persisted PreparationOccurrence
persisted Reminder
persisted Task
notifications
Drupal User -> Person
PREPROD / PROD
real personal/family data
new recurrence primitive
custom RRULE engine
```
