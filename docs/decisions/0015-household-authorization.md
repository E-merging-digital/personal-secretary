# 0015 — Autorisation Household explicite par compte Drupal

Status: **ACCEPTED**
Decision authority: GitHub issue #93
Project Lead acceptance: comment 5547581214
Materialization task: #101

## Context

Personal Secretary distingue déjà l'identité d'authentification Drupal User de
l'identité métier `Person`. La personnalisation par `CurrentPerson` ne constitue
cependant pas une autorité d'accès à un `Household` et ne doit jamais permettre à
un read model de charger des données de foyers non explicitement autorisés avant
de les filtrer ensuite.

Decision 0003 conserve Drupal comme source de vérité et Decision 0013 impose :

```text
PERSON != DRUPAL_USER
IDENTITY_BINDING != HOUSEHOLD_AUTHORIZATION
```

La première surface normale concernée est `My upcoming`, qui doit appliquer le
scope d'autorisation avant la projection des activités, responsabilités et
préparations.

## Decision

```text
AUTHENTICATION_PRINCIPAL = Drupal User
HOUSEHOLD_AUTHORIZATION = explicit account authority
CURRENT_PERSON = domain identity only

HOUSEHOLD_MEMBERSHIP != authorization
RESPONSIBILITY != authorization
CONCERNED_PERSON != authorization
EXTERNAL_ACCOUNT_AUTHORITY != Household authorization
```

L'autorité produit ordinaire compose exactement :

```text
small global product-use permission
AND
explicit target-Household grant
```

`administer personal secretary domain` reste un bypass administratif/global
privilégié. Il ne devient pas l'autorité normale du produit.

### Primitive de grant

La vérité de grant réutilise la Field API Core :

```text
FIELD = field_personal_secretary_households
ENTITY = Drupal User
TARGET = personal_secretary_household
CARDINALITY = 0..N
REQUIRED = false
TRANSLATABLE = false
```

Le sens est :

```text
User -> authorized Households = explicit editable authority truth
Household -> authorized Users = derived reverse query only
```

Aucune entité de grant, table SQL custom, rôle par Household, invitation ou
plateforme ACL générique n'est introduite.

Le champ est une métadonnée d'autorisation et n'est pas exposé sur l'édition
User générique. Sa mutation initiale est réservée à un bootstrap administratif
gouverné.

```text
CURRENT_PERSON_REQUIRED_FOR_HOUSEHOLD_AUTHORIZATION = NO
PER_HOUSEHOLD_ROLES = DEFER
INVITATION_FLOW = DEFER
SELF_GRANT = NO
FIRST_GRANT_MUTATION_AUTHORITY = administrative bootstrap only
```

### Service d'autorisation

`HouseholdAuthorizationService` recharge le Drupal User persisté et calcule le
scope exact à partir du champ de grant.

```text
anonymous = DENY
missing User = DENY
blocked User = DENY
ordinary User without product permission = DENY
ordinary User without exact Household grant = DENY
admin bypass = ALLOW for persisted target Households
stale Household reference = FAIL_CLOSED
```

Aucun grant n'est inféré de :

```text
Household.members
User -> Person
responsibility
concerned Persons
name/email
external account
```

`CurrentPerson` n'est donc pas requis pour répondre à la question « ce compte
est-il autorisé sur ce Household ? ».

### Mutation administrative

Le premier bootstrap de grants passe par une petite capacité applicative qui :

```text
requires administrator authority
reloads the target User
reloads every selected Household
replaces the complete explicit grant set
same set = NOOP
```

Un ID Household forgé ou stale échoue avant toute écriture. Une modification de
grants ne modifie jamais `field_personal_secretary_person`, et un relink
User->Person ne modifie jamais les grants.

### Ordre de lecture

Pour un utilisateur ordinaire, l'ordre est une frontière de sécurité :

```text
explicit authorized Household IDs
-> restrict ActivitySeries/read aggregation to those Households
-> calculate effective occurrences/responsibility/preparation
-> apply CurrentPerson personalization
-> presentation
```

Il est interdit de faire :

```text
load all Household/activity data
-> personalize afterward
```

Le filtre `CurrentPerson` ne s'applique donc qu'à l'intérieur d'un scope déjà
autorisé. Les labels, préparations ou autres données de foyers non autorisés ne
doivent pas entrer dans le modèle de présentation ordinaire.

### Personnalisation et grants indépendants

Decision 0013 permet plusieurs comptes pour une même `Person` :

```text
User A -> Person P
User B -> Person P
```

Les grants restent account-scoped :

```text
A granted H1
B not granted H1
-> A may consume P-personalized H1 data
-> B may not
```

Si les deux comptes ont indépendamment H1, ils peuvent voir les mêmes éléments
personnalisés pour P. Il n'existe aucun broadcast de grant via `Person`.

De même :

```text
User -> Person relink
!= Household grant mutation

Household.members mutation
!= Household grant mutation
```

### Mutations cross-Household

Toute future mutation affectant plusieurs Households doit dériver l'ensemble
complet des Households affectés depuis l'état persisté et exiger l'autorisation
sur chacun d'eux. Un seul grant partiel ne suffit jamais.

Cette décision n'ouvre aucune mutation produit normale ; #101 ouvre uniquement
la lecture personnalisée `My upcoming` et le bootstrap administratif de grants.

### Données et logs

```text
User -> Household grant = PERSONAL + security-relevant account metadata
```

Decision 0005 reste autoritative. Les logs ordinaires n'incluent pas les noms de
Households, labels de Persons, ensembles complets de grants ou graphes de
membership. Des diagnostics de sécurité bornés peuvent utiliser des IDs internes
minimisés lorsque cela est matériellement nécessaire.

Le dépôt et les tests restent `SYNTHETIC_PUBLIC` uniquement.

## Consequences

- Drupal User reste le principal d'authentification et d'autorisation.
- `Person` reste une identité métier, jamais une autorité Household implicite.
- Un seul champ Core multi-value porte la vérité de grant par compte.
- `HouseholdAuthorizationService` devient la primitive réutilisable des futures
  surfaces normales nécessitant un scope Household.
- `My upcoming` scope les ActivitySeries avant toute personnalisation.
- Le Household-wide `Upcoming` administratif peut rester inchangé.
- Les mutations normales existantes restent restrictives et ne sont pas ouvertes
  par cette décision.
- Aucun grant n'est backfillé ou inféré pour des Users existants.
- Toute future mutation cross-Household doit valider le scope affecté complet.
