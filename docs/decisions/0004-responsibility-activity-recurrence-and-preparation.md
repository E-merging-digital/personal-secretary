# 0004 — Responsabilité, activité, récurrence, exception et préparation

Status: **ACCEPTED**
Decision authority: GitHub issue #18
Parent epic: #16
Evidence audit: #20
Materialization task: #25
Recurrence adoption evidence: Epic #32 / Task #33

## Context

Le premier vertical doit représenter des activités récurrentes, leurs exceptions,
la responsabilité effective de l'utilisateur et les préparations nécessaires sans
transformer chaque occurrence calculable en ligne durable ni chaque préparation
en événement calendrier.

L'audit USE EXISTING FIRST a confirmé qu'un moteur RRULE custom n'est pas
justifié, mais qu'aucune primitive auditée ne porte à elle seule les règles de
responsabilité et de préparation propres à Personal Secretary.

Le runtime spike de l'Epic #32 / Task #33 a ensuite validé `date_recur` sur la
fondation Drupal courante et le Project Lead a arbitré son adoption pour le
premier vertical.

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
custom ou autre primitive adaptée) reste différée au premier slice métier
autorisé après ce spike.

### Récurrence

```text
RECURRENCE = EXTEND EXISTING
RECURRENCE_PRIMITIVE = date_recur / RRULE
DATE_RECUR = ADOPTED
CUSTOM_RRULE_ENGINE = NO
PRIMARY_INITIAL_PRIMITIVE = date_recur
SMART_DATE = fallback only if a future proven blocker makes date_recur untenable
```

`date_recur` est adopté comme primitive initiale de récurrence du premier
vertical pour représenter une règle RRULE et calculer les occurrences de base
sans imposer une entité métier durable par occurrence.

Le spike runtime a notamment prouvé la génération hebdomadaire, la conservation
de durée, le comportement fuseau/DST, l'expansion bornée des règles infinies,
les overlays synthétiques d'annulation/replanification et les conséquences des
modifications sémantiques de série.

Construire un moteur RRULE custom reste interdit sans nouveau gap précis et
prouvé. Recurring Events n'est pas retenu comme modèle canonique parce que son
modèle matérialise les occurrences ordinaires comme entités. Smart Date n'est
plus un candidat équivalent en attente : il reste uniquement un fallback si un
futur blocker prouvé rend `date_recur` intenable. Changer de primitive exige de
nouvelles preuves et une nouvelle autorité Project Lead/Decision.

#### Fuseau source et DST

```text
RECURRENCE_SOURCE_TIMEZONE = EXPLICIT
SOURCE_TIMEZONE_GOVERNS_OCCURRENCE_CALCULATION = YES
```

Chaque série récurrente doit porter explicitement son fuseau source. Le fuseau
du site Drupal, le fuseau de l'utilisateur ou le fuseau par défaut du processus
PHP ne deviennent jamais implicitement la vérité de récurrence.

Le spike `Europe/Brussels` a prouvé que l'heure locale source reste stable à
travers les transitions DST de printemps et d'automne pendant que l'offset UTC
évolue comme attendu. Cette sémantique source-locale est le contrat de calcul à
préserver.

#### Récurrence infinie

```text
INFINITE_RECURRENCE_EXPANSION = ALWAYS_BOUNDED
```

Toute expansion opérationnelle d'une récurrence infinie doit fournir une borne
de date/plage et/ou une borne de nombre/limit. Aucun code applicatif ou métier ne
peut énumérer une règle infinie sans borne explicite.

Le fail-safe observé de `date_recur` lorsqu'une expansion infinie est demandée
sans date ni limite est une défense en profondeur utile ; il ne remplace pas
l'invariant de caller borné de Personal Secretary.

### Occurrences et exceptions

```text
ordinary ActivityOccurrence = calculated by default
ActivityException = explicit durable/auditable cancel or reschedule semantics
date_recur technical occurrence/index/cache rows != Personal Secretary domain truth
```

Une occurrence ordinaire reste calculée tant qu'aucune exigence métier ne
justifie sa matérialisation. Les tables/lignes techniques que `date_recur`
maintient pour les occurrences, index ou besoins de requête sont des projections
techniques : leur présence, absence ou nettoyage ne définit ni l'existence
métier d'une occurrence ni le cycle de vie d'une exception.

Une exception explicite peut cibler une occurrence, exprimer une annulation ou
replanification et porter les métadonnées d'audit nécessaires sans obliger à
persister toutes les occurrences normales.

#### Identité de ciblage d'une exception

```text
ORDINAL_ONLY_TARGETING = PROHIBITED
```

Une future `ActivityException` ne peut pas identifier une occurrence uniquement
par son ordinal/index dans une série : le spike a prouvé qu'un ordinal se décale
lorsqu'une occurrence antérieure est introduite.

Sans figer ici le schéma de persistance, le ciblage futur doit conserver assez de
contexte original pour rester auditable, conceptuellement :

```text
series identity
original occurrence key
original UTC instant
original source-local datetime
source timezone
series revision/version/effective context
```

Cette liste fixe un contexte sémantique requis, pas les noms ni types de champs
de la future entité.

#### Modifications de série

```text
SERIES_EDIT_RECALCULATES_BASE = YES
ORPHAN_EXCEPTION_RISK = REAL
```

Une modification sémantique de RRULE ou de la série recalcule les occurrences de
base et peut faire disparaître une occurrence précédemment ciblée. La future
conception `ActivitySeries` doit donc traiter explicitement :

```text
audited revisions/versioning
effective-from semantics or justified equivalent
semantic series-edit history
orphaned exception detection/status
explicit reconciliation policy
```

La représentation de persistance exacte et la mécanique de réconciliation sont
différées au premier Task métier autorisé ; elles ne sont pas implémentées par le
spike #33.

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

- Core fournit les primitives Drupal de base ; la persistance métier exacte reste
  différée.
- `date_recur` / RRULE est la primitive de récurrence adoptée pour le premier
  vertical ; Smart Date n'est qu'un fallback conditionné à un futur blocker
  prouvé et à une nouvelle autorité.
- Le fuseau source d'une série est explicite et gouverne le calcul des
  occurrences ; les expansions infinies sont toujours bornées par le caller.
- Les occurrences ordinaires sont calculées par défaut et les tables techniques
  `date_recur` ne deviennent jamais la vérité du domaine.
- Le ciblage ordinal-only d'une exception est interdit ; les futures exceptions
  conservent un contexte original et de révision suffisant pour l'audit.
- Les modifications de série peuvent orpheliner des exceptions et imposent une
  politique future explicite de version/effective-from/réconciliation.
- Les exceptions et overrides explicites sont durables et auditables.
- La responsabilité effective reste déterministe.
- Les préparations/tâches sont dérivées et matérialisées seulement si utiles.
- Le calendrier reste une projection filtrée, jamais la vérité du domaine.
