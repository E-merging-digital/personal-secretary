# Execution capabilities

Registre repository-owned des surfaces d'exécution de Personal Secretary.

Statuts autorisés :

```text
planned
provisioning
available
degraded
unavailable
```

`available` signifie **prouvé pour ce dépôt**, pas seulement disponible en
théorie ou sur un autre projet. Aucun secret, token ou credential n'est stocké
ici.

## GitHub live repository operations

```text
status = available
surface = connected GitHub repository operations
role = read live authority + lightweight repository/issue/branch/PR work
proof = Epic 0 issues and branch for #4
secrets = none stored in repository
```

Cette surface suffit pour la matérialisation légère de gouvernance de #4.

## GitHub Actions CI

```text
status = planned
workflow = none
authority = #5
```

Aucun workflow CI n'est matérialisé pendant #4. La séquence approuvée est :
créer le plus petit CI déterministe utile, le prouver, puis seulement configurer
la protection de `main` et les required checks correspondants.

## Codex development execution

```text
status = planned
policy = CODEX_CALL_ONLY_WHEN_REQUIRED
default_agents = 1
authority = docs/decisions/0001-agentic-development-operating-model.md
```

Cette entrée ne signifie pas qu'un appel Codex est requis pour chaque tâche.
Codex devient la surface d'exécution lorsque le travail présente un vrai gap
d'exécution que les opérations GitHub légères ne couvrent pas correctement.
Chaque usage doit pouvoir expliquer ce gap.

## Drupal / DDEV runtime

```text
status = unavailable
drupal = not installed
composer_project = not materialized
ddev = not materialized
```

C'est intentionnel pendant Epic 0.

## Self-hosted runner

```text
status = planned
authority = #7
```

Trajectoire envisagée uniquement, sans provisionnement dans #4 :

```text
GitHub-hosted minimal CI
-> self-hosted smoke without secrets
-> exact-head DDEV validation
-> browser/Playwright when justified
-> controlled secret-bearing operations only when a real product need exists
```

Un futur runner ne devient `available` qu'après preuve réelle et doit exécuter
un contrôleur trusted plutôt que du code d'exécution arbitraire fourni par la PR
cible.

## MCP

```text
status = unavailable
reason = no demonstrated capability gap in Epic 0
```

MCP ne sera évalué qu'en présence d'un besoin structuré non couvert par les
surfaces existantes et avec une frontière de confiance explicitement définie.
