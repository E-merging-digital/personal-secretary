# 0017 — Completion sparse d'une préparation dérivée exacte

Status: **ACCEPTED**
Decision authority: GitHub issue #109
Materialization task: #109

## Contexte

Personal Secretary sait déjà dériver une préparation courante à partir de la règle de préparation, de l'occurrence effective et de la responsabilité effective. Cette vérité reste calculée : elle peut disparaître après annulation, se déplacer après replanification, changer de Person après changement de responsabilité ou être remplacée par une autre `PreparationRequirement`.

Le produit a toutefois besoin d'un état utilisateur durable pour exprimer qu'une préparation exacte actuellement dérivée a réellement été effectuée.

Decision 0016 avait déjà distingué conceptuellement :

```text
PreparationRequirement = what must be prepared
PreparationEligibility = whether that preparation exists now
PreparationCompletion = sparse done-state for one exact derived preparation
PersonalTask = separate standalone actionable work
```

Cette décision matérialise `PreparationCompletion` sans transformer la préparation en tâche.

## Modèle

```text
PreparationRequirement
+ effective occurrence
+ effective responsible Person
= derived PreparationEligibility

PreparationEligibility
+ optional PreparationCompletion
= current presentation state
```

`PreparationCompletion` est une Content Entity fieldable non révisionnable. Son existence signifie `PREPARED`; son absence signifie `UNPREPARED`. Aucun champ booléen ou cycle de statut supplémentaire n'est créé.

Champs métier minimaux :

```text
series
target_revision_id
original_occurrence_key
preparation_requirement
responsible_person
prepared_at
prepared_by_user
```

Aucun label, `due_at`, horaire effectif, lead time, snapshot Household/responsabilité, rappel, notification ou état calendrier n'est dupliqué.

## Identité sémantique

Une completion exacte est identifiée par :

```text
ActivitySeries ID
| target ActivitySeries revision ID
| original occurrence key
| PreparationRequirement ID
| responsible Person ID
```

Cette identité n'utilise jamais `effectiveUtcStart`, `due_at`, un label, le Drupal User seul ou la date courante.

Conséquences :

- une replanification de la même occurrence conserve la completion ;
- un passage de responsabilité A vers B ne transfère pas la completion de A : B voit une préparation non préparée ;
- si la responsabilité revient de B vers A sur la même identité, la completion de A s'applique de nouveau ;
- le remplacement d'une `PreparationRequirement` crée une nouvelle préparation non préparée ;
- une annulation ou une occurrence devenue passée masque la préparation dérivée sans obliger à supprimer la completion dormante.

## Acteur et autorité

```text
Drupal User = authentication + authorization principal
Person = domain identity
prepared_by_user = actor that performed the mutation
responsible_person = semantic owner of the preparation completion
```

Deux Drupal Users distincts qui représentent la même Person et disposent chacun du grant Household requis voient la même completion. `prepared_by_user` ne rend pas la completion privée au compte qui l'a créée.

Toute lecture/mutation normale compose :

```text
active persisted Drupal User
+ explicit HouseholdAuthorizationService grant
+ valid CurrentPerson
+ exact ActivitySeries in authorized Household
+ current exact EffectiveOccurrence
+ EffectiveResponsibility == CurrentPerson
+ current PreparationEligibility for exact requirement
```

Aucun Person, User ou Household fourni par le client n'est accepté comme autorité. Une révocation du grant prend effet à la requête suivante sans effacer la vérité de completion.

## Mutations réversibles

`Mark prepared` crée au plus une ligne pour la clé sémantique exacte. L'opération est idempotente : une ligne existante reste inchangée. Plusieurs lignes pour la même clé sont considérées comme un état corrompu et échouent fermé.

`Mark not prepared` supprime l'unique ligne exacte, est un no-op si elle n'existe pas, et échoue fermé si plusieurs lignes concurrentes existent. Aucun état `UNPREPARED`, historique ou tombstone n'est persisté.

Les mutations utilisent Drupal Form API avec confirmation et POST. Les paramètres de route ne sont que des identifiants de cible non autoritatifs ; le service re-dérive et revalide la préparation sous contrôle de concurrence avant écriture/suppression.

## Surfaces

`/personal-secretary/preparations/mine` conserve la fenêtre `active overdue + due in next 7 days` et l'horizon d'occurrences borné par le lead time persistant autorisé. La surface est séparée en :

```text
To prepare = derived current preparation without completion
Prepared = derived current preparation with exact completion
```

L'ordre stable existant est conservé dans chaque section.

`/personal-secretary/today` conserve `Tasks / Preparations / Activities`, mais la section Preparations reste action-oriented et n'affiche que les candidats actuellement non préparés. Marquer préparé les retire de Today ; un undo ultérieur les fait réapparaître s'ils satisfont encore les critères de Today.

## Intégrations différées

```text
PreparationTask = NONE
Reminder = NONE
Notification = NONE
AI = NONE
Calendar coupling = NONE
Google = NONE
```

Invariant futur uniquement : un éventuel système de notification de préparation ne devra jamais notifier une préparation dont la clé exacte possède une `PreparationCompletion` courante. Aucun système de notification n'est introduit par cette décision.

## Données

Conformément à Decision 0005 :

```text
responsible_person = PERSONAL
prepared_by_user = PERSONAL
prepared_at = PERSONAL
occurrence / requirement linkage = PERSONAL DOMAIN STATE
SECRET = NONE
```

Les tests et le dépôt utilisent uniquement des identités synthétiques.

## Conséquences

- les préparations restent dérivées ; seule leur completion est persistée de façon sparse ;
- `PersonalTask` reste un modèle distinct et son nombre ne change jamais lors d'un Mark prepared ;
- aucune réconciliation n'est requise lors d'une annulation, replanification, transition de responsabilité ou remplacement de requirement ;
- les reads de completion restent bornés aux candidats de préparation déjà autorisés ; aucune table globale n'est chargée puis filtrée par User ;
- les rappels, notifications, IA et calendriers restent hors scope.
