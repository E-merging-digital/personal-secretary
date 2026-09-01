# 0004 — Responsabilité, activité, récurrence, exception et préparation

Status: **ACCEPTED**
Decision authority: GitHub issue #18
Parent epic: #16
Evidence audit: #20
Materialization task: #25

## Context

Le premier vertical doit représenter des activités récurrentes, leurs exceptions,
la responsabilité effective de l'utilisateur et les préparations nécessaires sans
transformer chaque occurrence calculable en ligne durable ni chaque préparation
en événement calendrier.

L'audit USE EXISTING FIRST a confirmé qu'un moteur RRULE custom n'est pas
justifié, mais qu'aucune primitive auditée ne porte à elle seule les règles de
responsabilité et de préparation propres à Personal Secretary.

## Decision

### Frontières durables du domaine

```text
Person
Household
ResponsibilityRule
ResponsibilityOverride
ActivitySeries
ActivityException
PreparationRequirement
```

Leur mécanique de persistance Drupal exacte (`node` bundles, Content Entities
custom ou autre primitive adaptée) reste différée au bootstrap/spike technique.

### Récurrence

```text
RECURRENCE = EXTEND EXISTING
PREFERRED_PRIMITIVE = date_recur / RRULE
CUSTOM_RRULE_ENGINE = NO
DATE_RECUR_RUNTIME_SPIKE = REQUIRED BEFORE DEPENDENCY IS FINALIZED
```

`date_recur` est le candidat préféré pour représenter une règle RRULE et calculer
les occurrences de base sans imposer une entité métier durable par occurrence.
Cette préférence n'est pas une dépendance finale : un futur spike runtime doit
prouver le contrat API courant et le scénario synthétique de récurrence,
fuseau/DST, annulation, replanification et modification de série avant adoption
irrévocable.

Construire un moteur RRULE custom est interdit sans nouveau gap précis et prouvé.
Recurring Events n'est pas retenu comme modèle canonique parce que son modèle
matérialise les occurrences ordinaires comme entités. Smart Date reste un
fallback à réévaluer si le spike `date_recur` échoue.

### Occurrences et exceptions

```text
ordinary ActivityOccurrence = calculated by default
ActivityException = explicit durable/auditable cancel or reschedule semantics
```

Une occurrence ordinaire reste calculée tant qu'aucune exigence métier ne
justifie sa matérialisation. Une exception explicite peut cibler une occurrence,
exprimer une annulation ou replanification et porter les métadonnées d'audit
nécessaires sans obliger à persister toutes les occurrences normales.

### Responsabilité effective

La responsabilité effective est un calcul métier déterministe.

```text
explicit ResponsibilityOverride
>
recurring ResponsibilityRule
>
no responsibility
```

Aucun moteur d'automation, agent IA ou calendrier externe ne remplace ce calcul.

### Flux de calcul

```text
base occurrences from recurrence primitive
-> apply explicit auditable ActivityException (cancel/reschedule)
-> calculate effective responsibility
-> derive preparation eligibility
-> project calendar only when eligible and user time is actually occupied
```

### Préparations, rappels et tâches

```text
Preparation / Reminder / Task = derived
materialize only when needed
```

Une `PreparationRequirement` est une exigence métier réutilisable et auditable.
La préparation concrète n'est matérialisée que lorsqu'une occurrence pertinente
et la responsabilité effective la rendent utile. Une préparation peut exister
sans projection calendrier.

### Orchestration

Core Queue/Cron constitue la primitive de base pour les side effects asynchrones.
ECA peut être utilisé lorsqu'une orchestration apporte une valeur réelle.

ECA, Queue ou tout moteur de workflow ne doivent pas posséder :

```text
responsibility truth
recurrence truth
exception precedence
authorization
calendar projection eligibility
```

Ils consomment des décisions prises par les services métier déterministes.

## CUSTOM_GAPS

Le custom est borné aux gaps métier démontrés :

1. aucune primitive auditée ne combine récurrence avec responsabilité familiale
   alternée et override explicite selon la précédence décidée ;
2. les modules de récurrence n'expriment pas la règle produit « préparer/projeter
   uniquement lorsque l'utilisateur est effectivement responsable » ;
3. les exigences de préparation sont des objets métier indépendants d'un
   événement calendrier ;
4. annulation/replanification explicites doivent porter une sémantique métier
   auditable sans matérialiser toutes les occurrences ordinaires.

Ces gaps justifient des Domain/Application Services et des sémantiques métier
bornées, pas un moteur générique de récurrence custom.

## Consequences

- Core fournit les primitives Drupal de base ; la persistance exacte reste
  différée.
- `date_recur` reste le candidat préféré mais n'est pas final avant preuve runtime.
- Les occurrences ordinaires sont calculées par défaut.
- Les exceptions et overrides explicites sont durables et auditables.
- La responsabilité effective reste déterministe.
- Les préparations/tâches sont dérivées et matérialisées seulement si utiles.
- Le calendrier reste une projection filtrée, jamais la vérité du domaine.
