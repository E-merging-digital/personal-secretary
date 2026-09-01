# 0002 — Drupal foundations, AI boundaries and public-data policy

Status: **ACCEPTED**  
Decision authority: GitHub issue #3  
Parent epic: #1

## Context

Personal Secretary sera construit sur Drupal, mais Epic 0 ne doit installer ni
Drupal, ni Composer project, ni DDEV, ni provider, ni MCP, ni code fonctionnel.

Le projet doit éviter de recréer des capacités déjà couvertes par Drupal et doit
préparer l'intelligence artificielle selon la direction Drupal AI Initiative,
tout en conservant des frontières de sécurité et de gouvernance compatibles
avec des interactions Inside AI et Outside AI.

Le dépôt est public et ne peut pas contenir de données personnelles réelles.

## Decision

### USE EXISTING FIRST

Avant toute capacité custom substantielle, évaluer dans cet ordre :

```text
Drupal Core
-> Drupal APIs / Drush
-> Recipes
-> stable + maintained + Drupal Security Team-covered contrib for production dependencies
-> Drupal AI Initiative primitives when AI-related
-> EXTEND EXISTING
-> BUILD CUSTOM only with demonstrated gap
```

L'évaluation doit être proportionnée au besoin. Elle doit vérifier les
capacités réellement pertinentes, leur maintenance, leur stabilité, leur
compatibilité Drupal, leur statut de couverture sécurité et les frontières
produit applicables.

Pour une dépendance Drupal contrib pertinente pour la production, la cible par
défaut est :

```text
stable
+ maintained
+ covered by Drupal Security Team
```

Les capacités upstream expérimentales, `-dev`, alpha, beta, RC ou autrement non
couvertes par la Drupal Security Team peuvent être recherchées, évaluées,
prototypées ou testées. Cette règle ne constitue pas une interdiction de suivre
les capacités émergentes de Drupal ou de la Drupal AI Initiative.

En revanche, faire d'une telle capacité une dépendance de production exige une
décision/exception bornée et explicite documentant au minimum :

```text
necessity
risk
security/stability status
scope
upgrade/removal/re-evaluation trajectory
```

`BUILD CUSTOM` exige un gap précis et démontré. L'absence de recherche, la
préférence personnelle ou le fait qu'une implémentation custom soit déjà
commencée ne constituent pas un gap.

`USE EXISTING FIRST` ne signifie pas ajouter une dépendance à chaque besoin :
une API Drupal ou une primitive système standard peut être préférable à une
nouvelle dépendance.

### Drupal AI

Lorsqu'une capacité IA produit est introduite, **Drupal AI est l'abstraction
provider par défaut**.

Le code métier ne doit pas dépendre directement d'un fournisseur de modèles si
Drupal AI fournit une abstraction stable adaptée. Une exception exige une
décision/tâche dédiée qui démontre le gap, borne le couplage et prévoit sa
réévaluation.

L'IA ne contourne pas les permissions, révisions, workflows, validations ou
autres autorités Drupal applicables.

### Inside AI / Outside AI

L'architecture doit rester compatible avec :

```text
INSIDE AI
human -> Drupal -> governed AI capability
```

et :

```text
OUTSIDE AI
human or authorized external agent
-> governed Drupal capability
```

Cette compatibilité ne crée aucune surface d'écriture autonome Outside AI dans
Epic 0. Toute future écriture externe devra disposer d'une identité, de
permissions minimales, de limites d'action, d'audit et de gates adaptés.

### Dépôt public et données synthétiques

Toutes les fixtures et exemples du dépôt doivent être entièrement synthétiques.

Interdit dans le dépôt :

```text
REAL_FAMILY_DATA = FORBIDDEN
REAL_CHILD_DATA = FORBIDDEN
REAL_EMAIL = FORBIDDEN
REAL_DOCUMENT = FORBIDDEN
REAL_INVOICE = FORBIDDEN
REAL_PERSONAL_FINANCE = FORBIDDEN
REAL_CREDENTIAL = FORBIDDEN
```

Les secrets, tokens, mots de passe et clés réels sont également interdits.

Un export réel anonymisé ou pseudonymisé ne devient pas une fixture synthétique
et ne doit pas être utilisé comme donnée de test publique.

### Licence

```text
LICENSE = NONE FOR EPIC 0
PUBLIC != OPEN SOURCE DECLARATION
```

Une décision dédiée doit évaluer la licence avant publication fonctionnelle
substantielle, y compris les implications liées à Drupal.

## Consequences

- Epic 0 n'ajoute aucune dépendance Drupal ou IA.
- Chaque futur custom substantiel doit pouvoir montrer son audit de capacités
  existantes.
- Une dépendance contrib de production non stable/maintenue/security-covered
  exige une exception bornée explicite avec trajectoire de réévaluation ou retrait.
- Les futures fonctions IA restent provider-agnostic via Drupal AI par défaut.
- Les trajectoires Inside AI et Outside AI peuvent partager des capacités
  gouvernées sans dupliquer la logique métier.
- Les données réelles restent hors du dépôt public, même sous forme anonymisée.
