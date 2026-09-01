# Personal Secretary — instructions permanentes des agents

Ce fichier contient uniquement les invariants durables applicables à tout travail
dans le dépôt. Les décisions d'architecture et de produit vivent dans
`docs/decisions/`; les procédures répétables vivent dans `.agents/skills/`.

## Sources d'autorité

En cas de conflit, appliquer dans cet ordre :

1. décisions acceptées dans `docs/decisions/`;
2. `AGENTS.md`;
3. issue GitHub applicable et ses commentaires les plus récents;
4. `docs/workflow.md` et les documents d'opération applicables;
5. implémentation existante.

GitHub reste la vérité live pour l'état des issues, branches, Pull Requests,
checks et merges. Les conversations humaines peuvent coordonner le travail mais
ne remplacent pas les décisions durables.

## Dépôt public et données

Le dépôt est public. Les exemples, tests et fixtures doivent être entièrement
synthétiques.

Sont interdits dans le dépôt : données familiales ou d'enfants réelles, emails
réels, documents privés réels, factures réelles, données financières
personnelles réelles, secrets, tokens, mots de passe et credentials réels.

Un export personnel réel anonymisé ne constitue pas une fixture synthétique.

## USE EXISTING FIRST

Avant tout custom substantiel, appliquer la décision
`docs/decisions/0002-drupal-foundations-and-ai-boundaries.md`.

Évaluer d'abord Drupal Core, APIs Drupal/Drush, Recipes, contrib stable et
maintenu, primitives Drupal AI lorsqu'elles sont pertinentes, puis l'extension
de l'existant. Construire du custom uniquement lorsqu'un gap réel est démontré.

## Drupal AI

Lorsqu'une capacité IA produit sera introduite, Drupal AI est l'abstraction
provider par défaut. Le produit doit rester compatible avec les trajectoires
Inside AI et Outside AI sans créer par défaut une surface d'écriture autonome
pour un agent externe.

## Git et périmètre

- Une Task GitHub modifiante = une branche canonique = une Pull Request canonique.
- Les issues Epic et Decision peuvent porter l'intention ou l'autorité et être
  matérialisées par une Task/PR dédiée. Elles n'exigent pas chacune leur propre
  branche/PR sauf conversion explicite en travail modifiant exécutable.
- Ne jamais travailler directement sur `main`.
- Partir d'un `main` rechargé et courant.
- Convention de branche : `work/issue-<numéro>-<slug>`.
- Une PR doit référencer sa Task modifiante et rester strictement dans son périmètre.
- Un seul agent modifiant écrit sur une branche à la fois.

## Exécution et Codex

Lire `docs/operations/execution-capabilities.md` avant de conclure qu'une
capacité d'exécution est disponible ou indisponible.

Invariant de coût :

```text
CODEX_CALL = ONLY_WHEN_REQUIRED
DEFAULT_CODEX_AGENTS = 1
MULTI_AGENT_CODEX = EXCEPTION_ONLY
```

Codex est l'agent de développement officiel lorsqu'une tâche présente un vrai
besoin d'exécution que le travail GitHub léger ne couvre pas correctement. Ne
pas introduire de voies parallèles payantes de coding agents par défaut.

## Vérification

Toute validation doit être attribuable au candidat réellement examiné.

```text
THE PRODUCER MUST NOT BE THE ONLY VERIFIER
NO APPROVAL WITHOUT EVIDENCE
```

Avant approbation, recharger le HEAD exact de la PR et relier les validations,
le diff et les preuves à ce SHA. La revue Project Lead depuis GitHub exact HEAD
est la voie indépendante normale; un second agent d'exécution n'est pas requis
par défaut.

## Gates humains et trust root

Les changements de trust root incluent au minimum `AGENTS.md`,
`.agents/skills/**`, `.github/agents/**`, `.github/workflows/**`,
`.github/ISSUE_TEMPLATE/**`, les décisions et documents d'autorité/workflow,
ainsi que les contrats de revue et CI.

Pendant Epic 0, tout changement de trust root exige :

```text
deterministic validation
-> exact-head reload
-> PROJECT_LEAD_APPROVAL
-> merge
```

Delivery ne fusionne pas un tel changement sans cette approbation.

Pour toute action explicitement humainement gated, destructive, sensible aux
secrets/credentials ou matériellement irréversible, une approbation autorise
uniquement l'action nommée. Après approbation et avant l'action, recharger
l'autorité live et échouer en fail closed si elle a changé.
