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

## Trusted capability selection policy

Ce registre est aussi le contrat canonique de sélection des capacités pour toute
exécution assistée par IA dans Personal Secretary.

```text
CAPABILITY BEFORE COMMAND
AUTHORITY BEFORE ACTION
EVIDENCE BEFORE TRUST
```

La présence technique d'un outil n'autorise jamais son usage à elle seule. Une
action doit combiner :

1. un besoin réel dérivé de l'intention utilisateur ou d'une Task autorisée ;
2. une capacité approuvée dont le contrat couvre ce besoin ;
3. l'autorité minimale nécessaire à cette opération ;
4. les règles de données, de confirmation et de preuve applicables.

Le but n'est pas `ZERO CUSTOM CODE`. Le choix doit minimiser la frontière de
confiance totale. Un connecteur tiers trop puissant, opaque ou excessif n'est
pas préférable à une petite capacité locale correctement bornée simplement
parce qu'il existe.

### Ordre de sélection

Pour un besoin donné, rechercher dans cet ordre conceptuel et choisir la
**plus petite capacité sûre qui satisfait réellement le contrat** :

```text
1. existing approved structured capability
2. existing project-owned application/domain capability
3. existing approved connector or provider API with bounded contract
4. existing bounded MCP/tool/local execution surface
5. small governed integration when existing surfaces are insufficient
6. new dependency/tool only when materially justified
7. generic shell/browser/desktop execution only as last bounded option
```

Cet ordre n'est pas un classement absolu des fournisseurs. Une primitive
project-owned peut être plus sûre qu'un connecteur externe, et une API bornée
peut être plus déterministe qu'une automatisation navigateur. À risque et
capacité équivalents, préférer la surface structurée, contractuelle, auditable
et la moins privilégiée.

Avant de créer une nouvelle voie d'exécution, répondre au minimum à :

```text
DOES_AN_APPROVED_CAPABILITY_ALREADY_COVER_THE_NEED?
WHAT_AUTHORITY_IS_ACTUALLY_REQUIRED?
WHAT_DATA_LEAVES_WHICH_BOUNDARY?
WHY_IS_THE_EXISTING_SURFACE_INSUFFICIENT?
WHAT_NEW_TRUST_BOUNDARY_WOULD_BE_CREATED?
```

### Classes d'autorité

Toute capacité significative doit être comprise selon son effet, même si cette
classification n'est pas encore matérialisée comme type logiciel :

```text
READ
WRITE
DESTRUCTIVE_WRITE
EXTERNAL_SIDE_EFFECT
```

Exemples : lire un calendrier n'autorise pas à créer un événement ; rechercher
un e-mail n'autorise pas à l'envoyer ; lire un fichier n'autorise pas à le
supprimer ; produire un brouillon n'autorise pas sa transmission.

L'autorité vient de l'intention utilisateur et des politiques du projet, pas de
la disponibilité du tool. Une action support nécessaire et réversible peut être
couverte par l'action explicitement demandée lorsqu'elle est normalement
implicite et bornée. Une action destructive, une communication externe, une
extension de permission, une autorité persistante ou une action sensible exige
l'autorité spécifique prévue par le projet et, lorsqu'applicable, son gate
humain.

Une automatisation persistante n'hérite pas silencieusement de l'autorité d'une
action ponctuelle. Son déclencheur, ses lectures, ses mutations, sa fréquence,
son arrêt et l'autorité qui la couvre doivent être explicites avant activation.

### Failure semantics et absence d'escalade silencieuse

Une limitation d'une capacité ne justifie pas automatiquement une capacité plus
puissante. Les états suivants doivent rester conceptuellement distincts :

```text
CAPABILITY_UNAVAILABLE
AUTHORITY_MISSING
USER_CONFIRMATION_REQUIRED
POLICY_BLOCKED
EXECUTION_FAILED
PROVIDER_UNAVAILABLE
```

Ils ne doivent pas être aplatis en `ERROR` puis contournés par une autre voie.
En particulier, ces escalades par confort sont interdites sans justification et
autorité nouvelles :

```text
structured tool cannot do X -> generic shell
shell cannot do X -> sudo/root
filesystem cannot reach X -> widen allowedDirectories
API lacks permission -> broaden OAuth/provider scopes
connector unavailable -> browser/desktop automation with broader authority
```

Avant toute escalade, vérifier si l'opération est réellement requise, s'il
existe une voie plus sûre, et quelle nouvelle frontière de confiance serait
créée.

### Shell, filesystem, browser et desktop

Le shell est une capacité large, pas un raccourci universel. Lorsqu'il est
réellement nécessaire, utiliser la commande minimale dans l'identité, le
répertoire et le contexte déjà approuvés. Ne jamais élargir `sudo`, groupes OS,
Docker, Desktop Commander ou filesystem pour le confort d'un agent.

```text
filesystem visibility != filesystem authority
```

Un nouveau chemin filesystem doit être justifié par un besoin non couvert,
réduit au chemin minimal et documenté comme nouvelle frontière de confiance.

Browser/computer use reste valable lorsqu'aucune interface structurée suffisante
n'existe, mais il est moins déterministe et plus difficile à auditer. À contrat
équivalent :

```text
structured project/API capability
> approved connector
> browser automation
> generic desktop automation
```

### Connecteurs, MCP et dépendances

Un connecteur ou MCP n'est jamais trusted simplement parce qu'il est disponible
ou qu'il parle un protocole standard. Avant adoption, évaluer
proportionnellement :

```text
identity / provenance / maintainer
required capability gap
read/write/destructive surface
permissions and scopes
data exposed and network destinations
secret handling
retention implications
update path
revocation path
```

Une nouvelle dépendance suit la même doctrine `USE EXISTING FIRST` : vérifier
qu'elle existe réellement à la source canonique, qu'elle est nécessaire, son
état de maintenance/sécurité et la nouvelle frontière runtime qu'elle introduit.
La mécanique générique de provenance/conformance des dépendances appartient à
Preflight lorsqu'elle est applicable ; Personal Secretary définit ici le besoin
et la frontière produit sans dupliquer un moteur d'enforcement générique.

### Secrets et minimisation des données

Le principe est :

```text
USE SECRET != READ SECRET
```

Lorsque la surface le permet, une capacité utilise un credential via un provider
ou une référence sans exposer sa valeur brute au modèle, au shell ou aux logs.
Aucun secret manager général n'est créé par ce contrat.

Toute capacité externe doit limiter les données à la destination et à la durée
nécessaires. Les décisions de classification/egress restent autoritatives ; une
surface qui exige un dataset plus large que le besoin réel est architecturalement
plus coûteuse même si elle est techniquement pratique.

### Receipts, provenance et discovery

Pour une action significative, réutiliser les mécanismes existants permettant de
répondre, selon le risque :

```text
WHAT_HAPPENED?
WHAT_AUTHORIZED_IT?
WHICH_CAPABILITY_EXECUTED_IT?
WHAT_TARGET_AND_RESULT?
```

Ne pas créer un framework d'audit général uniquement pour satisfaire ce
principe. Lorsqu'aucun receipt durable n'existe et que le risque le nécessite,
traiter ce manque comme un gap borné distinct.

Les agents doivent découvrir les capacités approuvées via ce registre et les
contrats project-owned applicables plutôt que deviner un executable, une API ou
un package puis expérimenter jusqu'à réussite. Une capacité nouvelle ou dont le
statut réel a changé doit être rechargée/prouvée avant d'être traitée comme
`available`.

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
status = available
workflow = .github/workflows/drupal.yml
check = drupal
authority = #30
proof = successful read-only exact-head Composer + DDEV + Drupal run
```

La capacité CI Drupal est promue à `available` après une exécution réelle du
workflow durable, avec `contents: read`, réussie sur un HEAD exact sans workflow
temporaire de matérialisation. Elle a validé le lock commité, `composer validate`,
l'installation depuis le lock, l'audit, DDEV, le bootstrap depuis la configuration
canonique, deux rebuilds propres, la trajectoire production `--no-dev` et
l'absence de dérive repository/configuration.

Le check `drupal` n'est pas encore requis par le ruleset. Chaque nouveau HEAD
reste responsable de sa propre preuve ; un succès antérieur ne valide pas un
candidat ultérieur.

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
observed_stack = Drupal 11.4.5 / DDEV 1.25.4 / PHP 8.5.9 / MariaDB 11.8.9 / Drush 13.7.6
codex_cloud_ddev = unavailable in the observed environment
```

La capacité GitHub-hosted Drupal/DDEV a été promue à `available` seulement après
une exécution réelle ayant réussi la résolution et l'audit Composer, le démarrage
DDEV, le bootstrap Drupal, l'isolation DEV, deux rebuilds propres, la trajectoire
production `--no-dev`, l'absence de dérive de configuration et la garde du
write-set généré. Le workflow CI durable `drupal` a ensuite confirmé cette chaîne
en lecture seule sur un HEAD exact sans workflow temporaire de matérialisation.

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