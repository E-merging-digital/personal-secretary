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
proof = Epic 0 governance delivery through merged PR #14
secrets = none stored in repository
```

Cette surface couvre les opérations GitHub légères prouvées. Elle ne doit pas
être supposée capable de muter des réglages repository-level non exposés par le
connecteur.

## GitHub Actions CI

```text
status = provisioning
workflow = .github/workflows/governance.yml
authority = #5
proof = none yet
```

Le workflow minimal est matérialisé sur la branche de #5 et vise un check unique
`governance` sur les branches gouvernées `work/**`. Il valide le diff depuis le
merge-base avec `main` et parse les fichiers YAML de gouvernance sans dépendance
de projet.

La capacité n'est pas `available` tant qu'un vrai run GitHub Actions réussi n'a
pas été observé pour un HEAD candidat. Au dernier diagnostic #5, plusieurs
mises à jour de branche — y compris une mise à jour Git ref explicite — ont
produit zéro workflow run. La cause repository-level ou event-routing doit être
résolue avant de rendre le check obligatoire.

## Main protection administration

```text
status = unavailable
surface = connected GitHub repository operations
reason = protection/ruleset reads are available but no protection/ruleset mutation is exposed
```

Aucune required check ni règle de protection n'est configurée tant que le CI
n'est pas réellement prouvé. Une surface d'administration autorisée est requise
pour appliquer ensuite la protection bornée de `main`.

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
Chaque usage doit pouvoir expliquer ce gap. Codex n'est pas utilisé comme
substitut à une permission d'administration GitHub absente.

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

Trajectoire envisagée uniquement :

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
reason = no demonstrated capability gap requiring MCP in Epic 0
```

MCP ne sera évalué qu'en présence d'un besoin structuré non couvert par les
surfaces existantes et avec une frontière de confiance explicitement définie.
