# 0016 — Tâche personnelle autonome Household-scoped

Status: **ACCEPTED**
Decision authority: GitHub issue #100
Project Lead acceptance: comment 5548214596
Materialization task: #103

## Contexte

Personal Secretary distingue les activités planifiées, leurs préparations dérivées et le travail autonome à accomplir. Une tâche comme « Acheter des piles » ou « Appeler le dentiste demain » ne doit pas créer une fausse `ActivitySeries` ni un faux parent d'activité pour `PreparationRequirement`.

```text
PersonalTask = independent actionable work
ActivitySeries = time-bounded activity/event
PreparationRequirement = preparation around an activity
PreparationCompletion = sparse done-state for one exact derived preparation

TASK_DEADLINE != Activity duration
TASK_DEADLINE != TimeCommitment
TASK_DEADLINE != CalendarEligibility
```

## Scope et autorité

La première tranche est volontairement self-oriented :

```text
TASK_SCOPE = Household-scoped
HOUSEHOLD_REQUIRED = YES
ASSIGNED_PERSON_REQUIRED = YES
FIRST_SLICE_ASSIGNEE = CurrentPerson only
PRIVATE_USER_TASK = DEFER
ARBITRARY_HOUSEHOLD_MEMBER_ASSIGNMENT = DEFER
```

Decision 0015 reste autoritative :

```text
Drupal User = authentication / authorization principal
User -> Household grant = explicit account authority
Person = domain identity / assignee
assigned Person != Household authorization
Household.members != Household authorization
CurrentPerson != Household authorization
```

L'accès produit normal à une tâche compose :

```text
active persisted Drupal User
+ use personal secretary
+ explicit authorization to exact task Household
+ valid CurrentPerson
+ task.assigned_person == CurrentPerson
```

À la création, le `CurrentPerson` utilisé comme `assigned_person` doit en plus être un membre persistant du Household sélectionné. Cette vérification est une intégrité de domaine indépendante du grant du compte.

Une tâche persistée `Household H + Person P` ne peut pas être silencieusement orphanée, retargetée, mise à NULL ou déplacée si `P` cesse d'être membre de `H`. Tant qu'aucune opération gouvernée de retrait de membre ne gère cette référence, les lectures/mutations de cette tâche échouent fermé si l'intégrité est rompue.

## Persistance

Une seule Content Entity Core fieldable, non révisionnable :

```text
PersonalTask
entity type = personal_sec_task
base table = personal_secretary_task

id
uuid
title
household
assigned_person
due_mode
due_date
due_at
status
completed_at
completed_by_user
```

Aucun notes/rich text, pièce jointe, checklist, commentaire, priorité, tag, projet, sous-tâche, récurrence ou plateforme Todo générique.

## Deadline

```text
DUE_MODE = NONE | DATE | DATE_TIME

NONE:
  due_date = empty
  due_at = empty

DATE:
  due_date = civil YYYY-MM-DD
  due_at = empty

DATE_TIME:
  due_date = empty
  due_at = canonical UTC instant
```

`DATE` n'est jamais matérialisé comme un timestamp à minuit.

Pour `DATE_TIME`, l'entrée locale utilise le timezone du Drupal User courant, avec fallback sur `system.date:timezone.default`; seul l'instant UTC canonique est persisté. Aucun timezone source dupliqué n'est stocké.

Overdue est une présentation dérivée :

```text
DATE:
  viewer local civil date > due_date
DATE_TIME:
  due_at < current instant
NONE:
  never overdue
```

Une tâche DATE due aujourd'hui n'est pas overdue à minuit.

## Lifecycle

```text
OPEN
COMPLETED
```

Complete :

```text
OPEN -> COMPLETED
completed_at = UTC now
completed_by_user = authenticated persisted Drupal User
```

Un complete répété sur une tâche déjà `COMPLETED` est un NOOP et ne réécrit jamais l'acteur ou l'heure d'origine.

Reopen :

```text
COMPLETED -> OPEN
completed_at = NULL
completed_by_user = NULL
```

`completed_by_person` n'existe pas : User reste l'acteur authentifié, Person l'identité métier assignée.

La tâche est current-state et non révisionnable. L'édition est limitée aux tâches `OPEN` et seulement à `title + due semantics`. Household move et assignee change sont différés. Un hard delete explicite est accepté pour les tâches accidentelles/obsolètes ; aucun état CANCELLED/ARCHIVED, tombstone ou event framework n'est introduit.

## Surfaces et read security

Première surface :

```text
/personal-secretary/tasks/mine
```

Ordre de lecture obligatoire :

```text
explicit authorized Household IDs
+ CurrentPerson
-> PersonalTask EntityQuery:
   household IN authorized scope
   assigned_person = CurrentPerson
   status = OPEN
-> load matching tasks only
-> derive due/overdue presentation
```

Il est interdit de charger toutes les tâches puis de filtrer les Households non autorisés en PHP.

Plusieurs Drupal Users peuvent représenter la même Person, mais leurs grants restent indépendants : même Person + aucun grant Household ne donne aucun accès ; deux comptes indépendamment autorisés peuvent opérer sur la même tâche de cette Person.

My tasks reste OPEN-only. L'ordre est : overdue, autres tâches dues chronologiquement, tâches sans échéance, ID stable.

Reopen reste accessible via une surface de statut bornée immédiatement après une completion accidentelle ; aucune histoire générique des tâches complétées n'est créée.

## Intégrations différées

```text
RECURRING_TASK = NONE
TASK_REMINDER = NONE
AI_TASK_CAPTURE = NONE
TIME_COMMITMENT_COUPLING = NONE
CALENDAR_COUPLING = NONE
TODAY_TASK_INTEGRATION = DEFERRED
```

Un futur reminder de tâche pourra étudier la réutilisation conceptuelle du pattern #98, mais ne réutilise pas directement une identité de reminder de préparation. La capture IA future reste proposition-only, avec autorité Household/Person côté serveur.

## Données, logs et rétention

```text
PersonalTask real data = HIGHLY_SENSITIVE
repository/tests = SYNTHETIC_PUBLIC only
```

Les logs ordinaires n'incluent pas titre, nom Household, label Person, échéance exacte ou payload complet. Des catégories techniques minimisées peuvent être journalisées sans contenu métier.

OPEN/COMPLETED reste persistant jusqu'au hard delete explicite. Aucun moteur automatique de rétention, historique ou analytics n'est introduit dans cette tranche.

## Conséquences

- `PersonalTask` est la seule nouvelle vérité de travail autonome.
- Decision 0015 est réutilisée, aucune logique de grant n'est dupliquée.
- Membership Household/Person protège l'intégrité sans devenir une autorité de compte.
- L'EntityQuery de My tasks scope les Households avant tout chargement de tâche.
- Le calendrier, Today, les rappels, l'IA et #84 restent hors scope.
