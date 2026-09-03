# Workflow de delivery

GitHub est la vérité live de l'exécution. Les décisions et règles durables du
dépôt définissent l'autorité; les conversations coordonnent le travail sans les
remplacer.

## Préparation d'une tâche

Avant toute mutation :

1. recharger `main` live;
2. lire la Task modifiante applicable et ses commentaires, ainsi que les issues
   Epic/Decision qui portent l'autorité nécessaire;
3. lire `AGENTS.md`;
4. lire `docs/decisions/README.md` et les décisions applicables;
5. lire le Skill correspondant lorsque la procédure est répétable;
6. consulter `docs/operations/execution-capabilities.md` si la surface
   d'exécution est matérielle;
7. confirmer scope, exclusions, gates et dépendances.

Pour toute Task modifiante, établir avant l'implémentation :

```text
WHAT_USER_OR_PRODUCT_VALUE_DOES_THIS_DELIVER?
WHAT_IS_THE_SMALLEST_CHANGE_THAT_DELIVERS_IT?
WHAT_MATERIAL_RISK_MUST_BE_PROVEN?
```

Une ambiguïté d'architecture ou d'autorité retourne au Project Lead plutôt que
d'être inventée par Delivery.

## Unité Git

```text
1 modifying Task issue = 1 canonical branch = 1 canonical PR
```

Les issues Epic et Decision peuvent porter intention et autorité puis être
matérialisées par une Task/PR dédiée. Elles n'exigent pas chacune une branche ou
une PR sauf conversion explicite en travail modifiant exécutable.

Convention :

```text
work/issue-<number>-<slug>
```

La branche part d'un `main` rechargé. Aucun travail direct sur `main`.

Un seul agent modifiant écrit sur une branche à la fois. Un reviewer ou
spécialiste peut analyser en read-only.

## Choix de surface d'exécution

Commencer par la surface la plus simple qui prouve correctement le résultat.

```text
lightweight GitHub work
-> Codex only when a real execution gap requires development execution
-> future governed runner when the task requires its proven capability
```

Invariant :

```text
CODEX_CALL = ONLY_WHEN_REQUIRED
DEFAULT_CODEX_AGENTS = 1
MULTI_AGENT_CODEX = EXCEPTION_ONLY
```

Ne pas introduire de coding agents payants parallèles par défaut.

## Delivery normal

```text
reload authority
-> create/resume canonical branch for the modifying Task
-> implement only authorized scope
-> validate proportionally to material risk
-> inspect complete diff
-> open/update canonical PR
-> reload exact PR HEAD
-> collect only evidence needed for that HEAD
-> independent Project Lead review
-> merge only when repository gates and authority permit
-> reload main after merge
```

La validation est value-first et proportionnelle. Réutiliser tout gate, test ou
preuve existant qui couvre déjà le risque; ne pas en dupliquer la fonction. Les
tests ciblent le contrat, le comportement métier, les régressions réalistes et
les frontières de sécurité matérielles, pas chaque détail d'implémentation.

```text
low-risk mechanical change -> minimal validation
high-risk domain/security change -> proportional targeted validation
new gate/review -> explicit material risk justification required
```

Les gates, tests, preuves et revues redondants, ainsi que le processus pour le
processus lui-même, sont interdits. Entre deux processus également sûrs,
préférer le plus simple, le plus court et le moins coûteux.

Un CI rouge n'est pas automatiquement `HUMAN_REQUIRED`; Delivery peut corriger
dans le scope autorisé. Une extension matérielle de scope ou une décision non
résolue retourne au Project Lead.

## Exact-head verification

Avant une décision d'approbation ou merge :

- recharger la PR;
- confirmer qu'elle est ouverte, same-repository et basée sur `main`;
- relever le HEAD SHA exact;
- inspecter la liste complète des fichiers et le diff;
- associer chaque validation à ce HEAD;
- vérifier les commentaires/reviews matériels;
- confirmer qu'aucun changement parallèle de `main` ou d'autorité n'invalide
  le travail.

Une preuve issue d'un ancien HEAD ne valide pas le nouveau.

## Independent verification

Invariants :

```text
THE PRODUCER MUST NOT BE THE ONLY VERIFIER
NO APPROVAL WITHOUT EVIDENCE
```

La voie normale est une revue Project Lead indépendante depuis GitHub exact
HEAD, fondée sur le diff et les preuves déterministes. Un second agent Codex
n'est utilisé que si une seconde exécution ou expertise indépendante est
matériellement justifiée.

## Trust root

Chemins/surfaces de trust root au minimum :

- `AGENTS.md`;
- `.agents/skills/**`;
- `.github/agents/**`;
- `.github/workflows/**`;
- `.github/ISSUE_TEMPLATE/**`;
- `docs/decisions/**`;
- `docs/workflow.md`;
- contrats de revue, CI, autorité et capability routing.

Pendant Epic 0 :

```text
deterministic validation
-> exact-head reload
-> PROJECT_LEAD_APPROVAL
-> merge
```

Delivery ne fusionne pas autonomement une PR de trust root.

## Gates humains et FINAL_LIVE_AUTHORITY_RELOAD

Pour une action humainement gated, destructive, sensible aux
secrets/credentials ou matériellement irréversible :

```text
prepare gate
-> request approval
-> receive explicit approval
-> FINAL_LIVE_AUTHORITY_RELOAD
-> execute
```

Le reload final vérifie au minimum, lorsque pertinent : `main`, issue,
commentaires, décisions, roadmap, dépendances, capacité d'exécution et scope
exact approuvé.

Fail closed si un élément plus récent rend l'approbation inapplicable.

## Roadmap

`docs/roadmap.yaml` contient l'intention de planification uniquement. Il ne
stocke aucun état volatil de PR, HEAD, CI ou merge.

Après terminaison d'une tâche, recharger GitHub puis la roadmap. Delivery ne
continue automatiquement que lorsqu'une unique prochaine tâche est
explicitement autorisée et non ambiguë. Sinon, retour Project Lead.
