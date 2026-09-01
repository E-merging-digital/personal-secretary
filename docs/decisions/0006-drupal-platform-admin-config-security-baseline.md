# 0006 — Socle Drupal administration, configuration, sécurité et outillage

Status: **ACCEPTED**  
Decision authority: GitHub issue #21  
Parent epic: #16  
Evidence audit: #22  
Materialization task: #25

## Context

Le premier bootstrap Drupal doit être immédiatement administrable, proprement
séparé par environnement et suffisamment sûr pour préparer l'arrivée future de
données personnelles sensibles, sans transformer le projet en distribution
surchargée.

Chaque dépendance contrib future de production reste soumise à la règle de la
décision 0002 : stable, maintenue et couverte par la Drupal Security Team, sauf
exception bornée explicitement acceptée.

Les versions exactes ne sont pas figées ici. Elles doivent être rechargées live
au moment du bootstrap ou de toute activation future.

## Decision

### INSTALL_NOW

Le futur bootstrap technique doit prévoir le socle suivant :

```text
Core Navigation
Gin
Gin Toolbar
Pathauto
Token
Config Split
```

Raisons :

- Core Navigation constitue la direction native du nouveau projet ;
- Gin améliore l'interface d'administration sans devenir le thème frontend de
  l'application ;
- Gin Toolbar complète cette expérience sans recréer une seconde stack de
  navigation concurrente ;
- Pathauto + Token fournissent une primitive générique d'alias/naming utile ;
- Config Split rend les différences d'environnement explicites et versionnées.

Les primitives Core de flood, access, session et least privilege font partie du
socle sécurité normal dès le bootstrap.

### DEV_ONLY

```text
Devel
Security Review
```

Les dépendances DEV doivent être isolées par design :

```text
Composer require-dev
+
explicit development Config Split
+
production installation supporting --no-dev
```

Aucun outil purement DEV ne doit devenir une dépendance runtime PROD par
commodité.

### ENABLE_WHEN_ENV_EXISTS

```text
Config Readonly = future PROD
Environment Indicator = PREPROD/PROD when multiple environments exist
```

Config Readonly est une défense en profondeur contre les modifications
ordinaires de configuration en PROD ; il ne remplace pas les permissions,
policies ou procédures de déploiement.

Environment Indicator n'est activé qu'une fois plusieurs environnements réels
présents, après reload live de sa release stable, de sa maintenance, de sa
couverture sécurité et de sa compatibilité avec la Navigation/admin stack
courante.

### DEFER_UNTIL_NEEDED

```text
Field Group
Diff
Redirect
Metatag
Config Ignore
Password Policy
Monolog
Redis
```

Déclencheurs attendus :

- Field Group : complexité réelle des formulaires ;
- Diff : besoin humain réel de comparaison de révisions ;
- Redirect : contrat de continuité d'anciennes URLs ;
- Metatag : contenu réellement public/indexable/partageable ;
- Config Ignore : configuration locale mutable explicitement justifiée ;
- Password Policy : politique/compliance démontrée ;
- Monolog : besoin de logging structuré/externe ;
- Redis : besoin mesuré ou infrastructure existante le justifiant.

Ces modules ne sont pas installés « au cas où ».

### REJECT_REDUNDANT — initial baseline

```text
legacy Core Toolbar stack
Admin Toolbar
Login Security
WebProfiler
```

Le rejet est borné au baseline initial :

- le legacy Toolbar/Admin Toolbar est redondant avec la direction Core
  Navigation de ce projet greenfield ;
- Login Security recouvre des protections déjà disponibles dans Core sans gap
  initial démontré ;
- WebProfiler n'est pas ajouté tant que Devel couvre le besoin de diagnostic du
  bootstrap.

Un besoin futur matériel peut déclencher un nouvel audit, mais n'annule pas ce
baseline par défaut.

### Gate TFA avant données réelles

TFA n'est pas une dépendance du bootstrap synthétique, mais constitue un gate de
sécurité explicite :

```text
REAL PERSONAL DATA / REAL PRIVILEGED USERS
-> TFA trajectory explicitly resolved and proven
-> Decision 0005 enforcement proven
-> only then real-data authorization can be considered
```

La release TFA applicable doit être rechargée live avant adoption. Aucune
branche expérimentale ou non couverte n'est implicitement autorisée.

### Logging et performance

Le bootstrap commence avec les primitives Core de logging/cache. Monolog et
Redis restent différés jusqu'à un besoin démontré.

Toute observabilité doit respecter la décision 0005 : pas de payload familial,
documentaire ou financier sensible en clair par défaut, pas de credentials,
identifiants minimisés et rétention explicite.

## Consequences

- Le bootstrap futur possède une liste minimale et explicite plutôt qu'un
  catalogue de modules préventifs.
- La navigation admin repose sur Core Navigation + Gin + Gin Toolbar, sans
  Admin Toolbar concurrent.
- La séparation DEV/PROD est explicite via Config Split et le contrat
  `require-dev`/`--no-dev`.
- Les outils d'environnement sont activés seulement lorsqu'un environnement
  réel leur donne une fonction.
- TFA devient un gate avant données personnelles réelles, pas un module installé
  mécaniquement dans le bootstrap synthétique.
- Chaque nouvelle dépendance future doit démontrer le gap ou risque qu'elle
  réduit et revalider son état upstream live.
