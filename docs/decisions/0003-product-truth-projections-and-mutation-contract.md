# 0003 — Source de vérité produit, projections externes et contrat de mutation

Status: **ACCEPTED**  
Decision authority: GitHub issue #17  
Parent epic: #16  
Evidence audit: #20  
Materialization task: #25

## Context

Personal Secretary doit pouvoir agréger des activités, responsabilités,
préparations, tâches et, plus tard, des calendriers, emails, documents et
finances sans déplacer la vérité métier vers un système externe ou un modèle
IA.

Les mêmes règles doivent rester valables quelle que soit la surface qui demande
une action : UI Drupal, orchestration, future capacité IA Inside AI ou future
surface Outside AI explicitement autorisée.

## Decision

### Source de vérité

```text
SOURCE_OF_TRUTH = Drupal-owned domain state
```

L'état métier gouverné dans Drupal est la source de vérité. Un calendrier,
service externe, import, agent ou modèle IA est une projection, une source
d'entrée ou une surface d'interaction ; aucun ne devient l'autorité métier.

Aucun provider, aucune API calendrier particulière et aucun type de stockage
Drupal précis ne sont fixés par cette décision.

### Projections externes

```text
EXTERNAL_CALENDAR = filtered projection / integration surface only
```

Un élément n'est projeté vers un calendrier externe que lorsqu'il concerne
réellement l'utilisateur et occupe effectivement son temps selon les règles du
domaine. Une préparation contextuelle peut rester interne à Personal Secretary
sans devenir un événement calendrier.

Une projection externe peut être reconstruite depuis l'état métier gouverné ;
elle ne doit pas être nécessaire pour reconstruire la vérité métier.

### Rôle de l'IA

```text
AI = interpret / extract / classify / propose
AI != domain truth
```

L'IA peut aider à comprendre une entrée, extraire une structure, classifier ou
proposer une action. Elle ne décide pas seule d'une mutation métier et ne
contourne ni validation déterministe, ni permissions, ni policy, ni gate humain.

Les règles métier critiques doivent fonctionner sans LLM.

### Contrat de mutation

Toute mutation métier suit une capacité applicative ou domaine gouvernée.

```text
input / email / document / user
-> deterministic parser OR Drupal AI structured proposal
-> schema validation
-> deterministic business validation
-> policy / permissions
-> human approval when required
-> application/domain service
-> Drupal entity/action
-> queued/orchestrated side effects
```

```text
MUTATION = validated application/domain service only
AUTHORIZATION = deterministic business validation + Drupal access/policy
HUMAN_GATE = required when sensitivity/risk demands it
```

Une mutation sensible doit échouer en fail closed si l'identité, l'autorité, la
validation ou l'approbation requise est absente.

### Convergence Inside AI / Outside AI

UI Drupal, ECA, futures capacités AI Agents/Tool API et toute future surface
Outside AI autorisée doivent converger vers les mêmes services applicatifs et
domaine gouvernés. Elles ne réimplémentent pas les règles métier dans leur
propre couche.

```text
multiple interaction surfaces
-> same governed capability
-> same validation / policy / audit contract
```

Cette compatibilité n'autorise aucune surface d'écriture Outside AI par elle-même.

## Consequences

- Drupal conserve la vérité métier canonique.
- Les projections externes restent remplaçables et reconstructibles.
- L'IA reste une couche d'assistance/proposition, jamais une autorité implicite.
- Les Domain/Application Services constituent la frontière unique de mutation
  métier.
- Les permissions et policies Drupal complètent, sans remplacer, les règles
  métier déterministes.
- Les side effects peuvent être asynchrones ou orchestrés après validation sans
  déplacer la décision métier vers la queue ou l'orchestrateur.
- Le choix d'un provider, d'une API externe et du stockage Drupal exact reste
  différé jusqu'à une Task qui en démontre le besoin.
