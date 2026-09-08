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
-> relevant maintained contrib, including credible pre-stable candidates
-> Drupal AI Initiative primitives when AI-related
-> EXTEND / WRAP EXISTING
-> BUILD CUSTOM only with demonstrated material gap
```

L'évaluation doit être proportionnée au besoin et à la surface réellement
activée. Une release stable, maintenue et couverte par la Drupal Security Team
reste le choix préféré lorsqu'elle satisfait le besoin. Ce critère est une
préférence de risque, pas un veto absolu.

Une release ou branche alpha, beta, RC ou `-dev` pertinente n'est donc pas
rejetée automatiquement. L'absence de couverture par la Drupal Security Team
est un signal de risque matériel qui doit être évalué et accepté explicitement;
elle n'est pas, à elle seule, un motif suffisant de rejet.

Lorsqu'un candidat pré-stable est pertinent, l'évaluation doit examiner
proportionnellement au minimum :

```text
exact Drupal/PHP compatibility
maintainer/project activity
release maturity + changelog
usage/adoption signal where meaningful
security coverage
dependencies
exact enabled modules/submodules/surfaces
relevant Critical/Major/security/regression issues
exact-use blockers
ability to isolate risky components
patch/fork requirement
upgrade/removal path
re-evaluation trajectory
exact-stack testability
```

Une longue issue queue n'est pas un blocker en soi. Les issues doivent être
qualifiées par rapport aux modules, sous-modules et comportements que Personal
Secretary prévoit réellement d'activer. Un défaut matériel dans un composant
optionnel peut justifier de laisser ce composant désactivé sans rejeter tout le
projet contrib.

Lorsqu'une surface contrib sûre couvre une partie du besoin, préférer :

```text
SAFE CONTRIB SURFACE
+ CUSTOM ONLY FOR PROVEN GAP
```

à une réimplémentation custom 100 %. `BUILD CUSTOM` exige toujours un gap
matériel précis et démontré. L'absence de recherche, la préférence personnelle,
le statut pré-stable à lui seul ou le fait qu'une implémentation custom soit déjà
commencée ne constituent pas un gap.

Accepter une release pré-stable n'autorise pas implicitement une escalade de
maintenance. Les opérations suivantes exigent une justification et une autorité
distinctes :

```text
PATCH
FORK
DEV OVERRIDE
```

Pour toute release pré-stable acceptée en production :

```text
ACCEPTED_PRE_STABLE_RELEASE = EXACTLY IDENTIFIED
LOCKFILE_RESOLUTION = EXACT ACCEPTED RELEASE
ROOT_CONSTRAINT = BOUNDED SO IT CANNOT SILENTLY ADVANCE
LITERAL_EXACT_COMPOSER_CONSTRAINT = NOT REQUIRED IF IT BREAKS EXISTING VALIDATION
RISK_ACCEPTANCE = EXPLICIT
PROPORTIONATE_EXACT_STACK_TESTS = REQUIRED
```

La sémantique de pin porte donc sur l'identité exacte de la release acceptée,
la résolution exacte du lockfile et une contrainte racine empêchant tout
avancement silencieux. Une contrainte Composer littéralement exacte n'est pas
requise lorsqu'elle rend une validation existante incompatible. Une borne doit
rester spécifique au risque accepté; un pattern propre à un package ne devient
pas une règle universelle.

Une capacité pré-stable acceptée doit être réévaluée au minimum lorsqu'apparaît :

```text
new release / beta / RC / stable
security advisory or security-relevant issue
new blocker in enabled surface
Drupal compatibility change
PHP compatibility change
material production defect
```

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
  existantes et son gap matériel démontré.
- Une dépendance contrib pré-stable de production suit le contrat d'évaluation,
  d'acceptation de risque, de lock/bornage, de test et de réévaluation ci-dessus;
  son niveau de maturité ou son absence de couverture sécurité ne constitue pas,
  seul, un rejet automatique.
- Lorsqu'une surface contrib sûre couvre une partie du besoin, la réutilisation
  hybride est préférée au custom 100 %.
- Les futures fonctions IA restent provider-agnostic via Drupal AI par défaut.
- Les trajectoires Inside AI et Outside AI peuvent partager des capacités
  gouvernées sans dupliquer la logique métier.
- Les données réelles restent hors du dépôt public, même sous forme anonymisée.
