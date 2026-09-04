# 0013 — Liaison d'identité Drupal User → Person

Status: **ACCEPTED**
Decision authority: GitHub issue #74
Project Lead acceptance: comment 5540468328
Materialization task: #76

## Context

Le premier vertical sait calculer les occurrences effectives, la responsabilité et
la préparation, et gérer les membres du foyer. Il lui manque toutefois une réponse
déterministe à la question : quelle `Person` du domaine représente le compte
Drupal actuellement authentifié ?

Cette liaison est un prérequis de personnalisation et de future éligibilité à une
projection calendrier, sans fusionner identité d'authentification et identité
métier.

## Decision

```text
PERSON != DRUPAL_USER

AUTHENTICATION_IDENTITY = Drupal User
DOMAIN_IDENTITY = Personal Secretary Person

RECOMMENDED_PRIMITIVE =
Drupal Core configurable entity-reference field on User

FIELD = field_personal_secretary_person
TARGET_TYPE = personal_secretary_person
LINK_DIRECTION = User -> Person

CARDINALITY_USER_TO_PERSON = 0..1
CARDINALITY_PERSON_TO_USER = 0..N
TRANSLATABLE = false
REQUIRED = false

MAPPING_TRUTH = one User entity-reference field
REVERSE_MAPPING = derived query only
SECOND_EDITABLE_COPY = NONE
```

Aucune entité/table de mapping custom, aucun SQL custom et aucune dépendance
contrib ne sont justifiés.

### CurrentPerson

La résolution courante est déterministe et fail-closed :

```text
CurrentPersonResolver::resolve(AccountInterface $account): Person

anonymous = FAIL_CLOSED
unlinked User = FAIL_CLOSED
blocked User = FAIL_CLOSED
missing/stale Person reference = FAIL_CLOSED
```

Le resolver recharge le `User` persisté par UID, exige un compte actif, exige
exactement une référence non vide, charge la `Person` correspondante et retourne
cette identité exacte.

Il est interdit de résoudre l'identité par :

```text
Person.name
email
Household order
first Household member
AI inference
arbitrary fallback
```

Après création de la liaison, la résolution de l'identité ne dépend pas de
l'appartenance actuelle à un `Household` :

```text
IDENTITY_BINDING != HOUSEHOLD_AUTHORIZATION
```

L'appartenance au foyer reste toutefois une validation de la mutation initiale
ou d'un relink via la surface produit gouvernée.

### Mutation gouvernée

La liaison est créée ou remplacée par une petite capacité applicative opérant
uniquement sur le compte Drupal courant :

```text
LinkCurrentUserToPersonService
```

Le caller ne fournit aucun User ID. La `Person` sélectionnée doit être persistée
et actuellement référencée par au moins un `Household.members`, avec
revalidation serveur immédiatement avant l'écriture.

```text
same mapping = NOOP
relink = ALLOWED
atomic transaction = NONE
```

Plusieurs comptes Drupal peuvent référencer la même `Person`. Aucune unicité
inverse n'est introduite.

La première surface produit reste bornée et protégée par la permission existante :

```text
Link my account to household member
permission = administer personal secretary domain
```

Le champ de mapping n'est pas une surface de profil générique et n'est pas ajouté
au form display générique du `User`.

### Lifecycle

La suppression ou le blocage d'un compte Drupal ne supprime jamais l'identité
métier `Person` ni son historique de domaine. Un compte bloqué ne peut pas être
résolu comme `CurrentPerson`.

Une référence stale échoue en fail-closed et n'est pas auto-réparée.

Il n'existe pas encore de capacité produit gouvernée de suppression d'une
`Person`, donc aucun guard générique de suppression n'est ajouté par le premier
slice. Lorsqu'une telle capacité sera introduite, une `Person` liée ne pourra pas
être supprimée silencieusement : prévention ou réconciliation sûre exigera une
autorité séparée.

### Séparation identité / autorité externe

La liaison User → Person ne porte aucune autorité de connecteur externe :

```text
IDENTITY != ACCOUNT != CREDENTIAL != CAPABILITY != AUTHORITY != APPROVAL
APPROVAL_FOR_ACCOUNT_A != APPROVAL_FOR_ACCOUNT_B

USER_TO_PERSON_IS_EXTERNAL_ACCOUNT_AUTHORITY = NO
USER_TO_PERSON_IS_GOOGLE_AUTHORITY = NO
USER_TO_PERSON_IS_MICROSOFT_AUTHORITY = NO
USER_TO_PERSON_IS_EMAIL_AUTHORITY = NO
USER_TO_PERSON_IS_CALENDAR_OAUTH_AUTHORITY = NO
```

Toute future autorité Google, Microsoft, email, calendrier ou autre connecteur
reste account-scoped et séparément autorisée.

### Déploiement

Le stockage est porté par deux Config Entities Core :

```text
field.storage.user.field_personal_secretary_person
field.field.user.user.field_personal_secretary_person
```

Fresh install et clean rebuild les créent par import de la configuration
canonique. Une installation existante les reçoit par import normal de
configuration et la Field API Core gère le schéma. Aucun `hook_update_N` n'est
nécessaire uniquement pour créer ce nouveau champ configurable.

### Valeur pour la future projection calendrier

Cette décision rend possible le prédicat déterministe :

```text
CurrentPerson = resolver(current Drupal User)

EffectiveOccurrence
-> EffectiveResponsibility
-> responsiblePersonId == CurrentPerson.id
```

Cette égalité prouve seulement que l'occurrence concerne la `Person` courante.
Elle ne suffit ni à prouver que l'occurrence occupe réellement le temps de
l'utilisateur, ni à autoriser une projection externe, ni à sélectionner un
provider/compte/credential.

## Consequences

- Drupal User reste l'identité d'authentification.
- `Person` reste l'identité métier indépendante et durable.
- Le mapping possède une seule vérité éditable.
- La résolution courante est déterministe et fail-closed.
- Household authorization reste une tranche distincte.
- La personnalisation d'Upcoming reste différée.
- Calendar provider, OAuth, credentials, MCP, FlowDrop et AI restent hors scope.
- Strategic review #75 reste READ_ONLY et ne bloque pas cette progression produit.
