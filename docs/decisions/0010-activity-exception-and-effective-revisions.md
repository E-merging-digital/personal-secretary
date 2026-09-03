# 0010 — ActivityException et révisions effectives d'ActivitySeries

Status: **ACCEPTED**
Decision authority: GitHub issue #39
Parent epic: #35
Materialization task: #40

## Context

Decision 0004 a établi que les occurrences ordinaires restent calculées, que les
exceptions explicites doivent être auditables et qu'une modification sémantique
de série peut rendre une cible historique orpheline. Decision 0009 a ensuite
matérialisé `ActivitySeries` comme Content Entity revisionable sans prétendre
résoudre la timeline effective, les exceptions ou leur réconciliation.

Ce deuxième slice ferme ce gap sans créer d'`ActivityOccurrence` métier durable
et sans rouvrir l'adoption de `date_recur`.

## Decision

```text
ACTIVITY_EXCEPTION = explicit revisionable Drupal Content Entity
ORDINARY_ACTIVITY_OCCURRENCE = calculated / no domain entity
SERIES_REVISION_EFFECTIVE_FROM = explicit required semantic
EFFECTIVE_FROM_CANONICAL = UTC instant
REVISION_TIMELINE = append-only / strictly increasing effective-from
AUTO_RETARGET_EXCEPTION = NO
ORPHAN_EXCEPTION = explicit durable status
RECONCILIATION = explicit / audited through a new ActivityException revision
ACTIVITY_EXCEPTION_REVISION_AUDIT_TIME = persisted revision-specific system timestamp
```

### Effective-from des révisions de série

Chaque révision sémantique d'`ActivitySeries` porte `effective_from`.

La révision initiale utilise l'instant DTSTART de la récurrence. Toute nouvelle
révision exige une borne explicite du caller, stockée comme instant UTC, et cette
borne doit être strictement postérieure à celle de la dernière révision.

Une révision R gouverne les départs d'occurrence dans :

```text
[R.effective_from, next_revision.effective_from)
```

La dernière révision gouverne à partir de sa borne sans fin supérieure.

`effective_from` n'est pas une timezone de récurrence. La vérité du fuseau source
reste exclusivement la propriété `timezone` du champ `date_recur`.

Les révisions historiques restent immuables, chargeables et explicitement
projectables. La projection explicite d'une révision historique ne requiert pas
qu'elle soit latest/default.

### Timeline effective

Le service de timeline résout toutes les révisions persistées dans leur ordre
append-only, vérifie leurs bornes strictement croissantes puis réutilise
`OccurrenceProjectionService` à l'intérieur de chaque intervalle effectif.

Une projection produit effective exige toujours une fenêtre complète. Une limite
positive peut uniquement tronquer la sortie finale ; elle ne remplace jamais la
fenêtre et ne justifie aucune expansion RRULE non bornée.

### ActivityException

`ActivityException` est une Content Entity fieldable et revisionable possédée par
le module `personal_secretary`. Elle stocke une cible auditée :

```text
ActivitySeries
target series revision id
original occurrence key
original UTC start/end
original source-local start/end
source timezone snapshot
action = cancel | reschedule
status = active | orphaned
lifecycle_persisted_at = system time when this exception revision was persisted
rescheduled UTC start/end when reschedule
```

`lifecycle_persisted_at` est un champ Drupal `timestamp` revisionable. Chaque
révision initiale active, transition `orphaned` et réconciliation `active` reçoit
l'heure système courante immédiatement avant sa persistance. Les révisions
historiques conservent leur propre valeur. Cette donnée décrit le moment de la
transition de lifecycle de l'exception ; elle ne représente ni l'occurrence
originale, ni l'horaire reschedulé, ni `ActivitySeries.effective_from`, ni DTSTART.

Le snapshot source-local/fuseau est une trace d'audit de la cible ; il ne remplace
jamais la timezone canonique de la série.

Une exception ne peut être créée que depuis un `BaseOccurrence` qui appartient à
la timeline de base actuellement effective. Série, révision gouvernante, clé et
contexte temporel doivent tous correspondre. Une révision historique qui peut
générer arbitrairement le même instant ne suffit pas.

Un seul `ActivityException` actif peut cibler le composite :

```text
series + target revision id + original occurrence key
```

Le ciblage ordinal-only n'existe pas dans l'API de mutation.

### Cancel et reschedule

`cancel` supprime la cible de la projection effective sans modifier la RRULE ni
une révision historique.

`reschedule` conserve la cible originale et stocke un nouvel intervalle UTC
explicite avec `end > start`. Dans ce slice, le fuseau sémantique reste celui de
la cible ; un reschedule cross-timezone échoue en fail closed.

La projection effective applique dans cet ordre :

```text
effective revision timeline
-> base occurrences
-> active ActivityException overlay
-> effective-window filtering
-> deterministic sort
-> optional final limit
```

Un reschedule dont la cible originale est hors fenêtre peut être inclus si son
nouvel instant entre dans la fenêtre grâce à la ligne d'exception durable. Un
reschedule déplacé hors fenêtre est exclu. Aucune expansion non bornée n'est
utilisée pour obtenir cette propriété.

### Orphelins et réconciliation

Lorsqu'une nouvelle révision sémantique de série est enregistrée, toute exception
active visant une révision plus ancienne à un instant situé à ou après la nouvelle
borne devient `orphaned`. La transition est persistée comme une nouvelle révision
de l'exception ; sa révision active précédente reste chargeable.

Une cible antérieure à la nouvelle borne reste active.

Même si la nouvelle révision produit le même instant UTC :

```text
SAME_INSTANT_IN_NEW_REVISION != SAME_AUDITED_TARGET
AUTO_RETARGET = NO
```

Une exception orpheline n'altère pas la projection effective.

La réconciliation est une mutation explicite : elle exige la révision courante
orpheline, une nouvelle cible de base effectivement gouvernée par la même série,
crée une nouvelle révision d'`ActivityException`, remplace le snapshot de cible et
repasse son statut à `active`. Les réconciliations cross-series, fuzzy, AI ou
nearest-date sont interdites.

### Mutation, audit et accès

Les mutations produit convergent vers les services domaine/applicatifs existants.
La création d'une nouvelle révision de série et l'orphelinage nécessaire sont
réalisés dans la même transaction déterministe avant succès.

Aucune Queue, ECA ou IA ne possède cette vérité.

`ActivityException` réutilise la permission restrictive
`administer personal secretary domain`. Les révisions Drupal conservent les états
successifs active/orphaned/reconciled avec leur timestamp de persistance propre,
sans introduire de relation Drupal User → Person pour l'attribution d'acteur.

## Data et exclusions

Decision 0005 continue de s'appliquer. Les fixtures publiques/CI restent
`SYNTHETIC_PUBLIC`; aucun flux PROD/PREPROD ni fournisseur IA n'est autorisé.

Ce slice n'introduit pas :

```text
ResponsibilityRule
ResponsibilityOverride
effective responsibility
PreparationRequirement
calendar / Google Calendar
Gmail
Drupal AI/provider
ECA / Queue side effects
PREPROD / PROD
real data
custom RRULE engine
ordinary ActivityOccurrence entity/table
```
