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
status = available
workflow = .github/workflows/governance.yml
check = governance
authority = #5
proof = successful real GitHub Actions execution on an exact candidate HEAD
```

Le workflow minimal vise un check unique `governance` sur les branches
gouvernées `work/**`. Il valide le diff depuis le merge-base avec `main` et
parse les fichiers YAML de gouvernance sans dépendance de projet.

La capacité a été promue à `available` uniquement après observation d'un vrai
run GitHub Actions réussi dont le job `governance`, le checkout exact de branche
et l'étape de validation ont tous terminé avec succès. Chaque nouveau HEAD reste
responsable de sa propre preuve ; un succès antérieur ne valide pas un candidat
ultérieur.

## Technical Drupal CI

```text
status = provisioning
workflow = .github/workflows/drupal.yml
check = drupal
authority = #30
proof_required = successful read-only exact-head Composer + DDEV + Drupal run
```

La Task #30 a déjà prouvé la chaîne runtime sur GitHub-hosted Linux pendant la
matérialisation du lock et de la configuration. La capacité CI durable reste
cependant `provisioning` tant que le workflow repository-read-only `drupal` n'a
pas lui-même réussi sur le HEAD final qui ne contient plus le workflow temporaire
de matérialisation. Le workflow n'est pas encore un check requis du ruleset.

## Main protection enforcement

```text
status = available
ruleset = protected-main
target = default branch / main
required_check = governance
authority = #5
proof = live ACTIVE GitHub ruleset enforcing the governed PR path
```

La protection de `main` est prouvée par GitHub live. Le contrat durable exige
une Pull Request, le check `governance` à jour avec `main`, la résolution des
conversations, bloque la suppression et les mises à jour non fast-forward, et
ne définit aucun bypass. Le nombre d'approbations requis reste à zéro pendant
ce bootstrap afin de ne pas créer un gate auto-impossible avec le compte
propriétaire unique.

## Main protection administration routes

```text
connected_github_ruleset_mutation = unavailable
human_github_admin_route = available_when_explicitly_human_required_and_authorized
```

La surface GitHub connectée peut lire la protection et les rulesets mais ne doit
pas être présentée comme capable de les créer ou modifier. Une mutation de
ruleset peut être effectuée par un administrateur humain uniquement lorsqu'elle
est explicitement `HUMAN_REQUIRED`, bornée et autorisée. Après une telle action,
GitHub live doit être rechargé avant de considérer l'état comme prouvé.

## Codex development execution

```text
status = degraded
policy = CODEX_CALL_ONLY_WHEN_REQUIRED
default_agents = 1
authority = docs/decisions/0001-agentic-development-operating-model.md
source_authoring = proven in the Task #30 Codex Cloud run
artifact_handoff = unavailable in the observed Task #30 surface
packages.drupal.org = proxy-blocked in the observed Codex Cloud environment
docker_ddev = unavailable in the observed Codex Cloud environment
```

Codex a effectivement exécuté le rôle de développement demandé par la Task #30,
mais son workspace observé n'a pas fourni de transport complet et durable de
l'artefact vers GitHub. Cette limitation de handoff est acceptée par l'autorité
Project Lead de #30 et ne doit pas être contournée par ajout de secrets.

Le statut `degraded` décrit cette surface observée, pas l'ensemble du produit :
le dépôt peut utiliser des surfaces d'exécution distinctes pour la persistance,
la résolution Composer et la preuve Docker/DDEV.

## Drupal / DDEV runtime

```text
status = available
composer_project = materialized by #30
ddev_config = materialized by #30
github_hosted_ddev_proof = successful real GitHub Actions execution
observed_stack = Drupal 11.4.5 / DDEV 1.25.3 / PHP 8.5.7 / MariaDB 11.8.8 / Drush 13.7.6
codex_cloud_ddev = unavailable in the observed environment
```

La capacité GitHub-hosted Drupal/DDEV a été promue à `available` seulement après
une exécution réelle ayant réussi la résolution et l'audit Composer, le démarrage
DDEV, le bootstrap Drupal, l'isolation DEV, deux rebuilds propres, la trajectoire
production `--no-dev`, l'absence de dérive de configuration et la garde du
write-set généré. Cette preuve runtime ne préjuge pas du statut du workflow CI
durable `drupal`, qui doit encore réussir en lecture seule sur son HEAD final.

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
reason = no demonstrated application-runtime capability gap requiring MCP
```

MCP ne sera évalué qu'en présence d'un besoin structuré non couvert par les
surfaces existantes et avec une frontière de confiance explicitement définie.
