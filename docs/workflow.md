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

## Clarification avant Delivery

Pour toute Task modifiante qui implique du comportement produit, Project Lead
applique avant spec/Delivery :

```text
USER INTENT
-> RESEARCH CURRENT TRUTH
-> CLARIFICATION_LEVEL = L0 | L1 | L2
-> resolve only material decisions
-> spec / Task
-> Delivery
```

La question de gate est : reste-t-il une décision utilisateur significative
susceptible de changer ce que Personal Secretary fait, quand il le fait ou avec
quelles données ? Si non, avancer. Si oui, clarifier avant Delivery.

### L0 — direct

Utiliser L0 pour une demande claire et étroite sans décision produit matérielle
non résolue, nouveau comportement automatique, nouvelle permission ou nouvelle
implication sensible de données. Aucun échange de clarification n'est obligatoire.

### L1 — clarification légère

Utiliser L1 lorsqu'un petit nombre de choix peut changer matériellement le
comportement visible. Rechercher la vérité actuelle, poser uniquement des
questions ciblées dont la réponse peut changer une décision matérielle, puis
arrêter dès que le besoin est assez clair.

### L2 — clarification structurée

Utiliser L2 pour une fonctionnalité/workflow matériellement nouveau, une
automatisation/proactivité, une intégration externe, email/calendrier,
notification, permission, nouvel usage de données personnelles, nouveau modèle
mental ou changement UX/navigation important. Utiliser une clarification courte
et structurée avant la spec et des slices Delivery bornés. L2 n'est pas le
défaut.

### Research before question

```text
DO NOT TURN PROJECT-LEAD RESEARCH DEBT INTO USER COGNITIVE LOAD
STOP_WHEN_CLEAR = REQUIRED
```

Avant de questionner l'utilisateur, exploiter les preuves disponibles lorsque
raisonnablement suffisantes : dépôt, issues/commentaires, décisions/docs,
comportement produit/navigateur, design/Figma existant et documentation
officielle. Ne pas demander ce qui peut être établi depuis ces sources. La
clarification n'est pas un questionnaire et ne continue pas pour complétude ou
symétrie.

### Defaults et contrôle utilisateur

Un default matériel est une décision produit. Selon le risque réel, décider
seulement ce qui est pertinent parmi : default, opt-in/opt-out, persistance,
scope, visibilité et réversibilité. Ne pas créer de setting lorsqu'un bon default
suffit.

Pour une automatisation/proactivité, examiner proportionnellement le trigger,
l'action, sa visibilité, le besoin de confirmation, la réversibilité, la
désactivation, le comportement d'erreur et la visibilité post-action. Une
capacité technique ne constitue jamais à elle seule une autorité
d'automatisation. Si l'action surprendrait raisonnablement l'utilisateur,
préférer suggestion ou confirmation.

Pour une notification, ne clarifier que ce qui est matériel : raison, moment,
canal, urgence, répétition, désactivation et condition d'absence de notification.
Éviter la fatigue notificationnelle.

### Email, calendrier, permissions et données

Préserver explicitement la frontière :

```text
READ
SUGGEST
DRAFT
CREATE
MODIFY
SEND
DELETE
```

Une autorité pour une capacité faible ne vaut jamais autorité silencieuse pour
une capacité plus forte. `DRAFT != SEND`, `READ != MODIFY` et
`REMIND ABOUT EVENT != MODIFY CALENDAR`.

Pour un nouvel usage de calendrier, email, contacts, localisation, historique,
fichiers, données personnelles ou API externe, appliquer la minimisation et ne
clarifier que les dimensions matérielles : données nécessaires, finalité,
permission, stockage/rétention, visibilité, service externe et comportement sans
permission.

Pour L1/L2, demander aussi si un utilisateur raisonnable pourrait être surpris
par le comportement. Si oui, vérifier proportionnellement visibilité,
consentement, feedback, réversibilité et opt-out.

```text
USEFUL BY DEFAULT
CONFIGURABLE WHEN NEEDED
```

Ne pas exposer la complexité d'implémentation avant qu'elle produise une valeur
utilisateur.

### Escalade Delivery et frontière de spec

Delivery doit STOP / RETURN PROJECT LEAD lorsqu'il découvre une nouvelle décision
matérielle concernant :

- comportement utilisateur ambigu;
- choix UX significatif;
- default;
- permission;
- automatisation/proactivité;
- action externe;
- élargissement matériel du scope;
- architecture durable.

Delivery n'escalade pas les choix locaux de classes, services, plugins,
composants, structure interne ou tests proportionnés lorsque le comportement
autorisé et les gates restent respectés.

Project Lead/spec définit prioritairement problème, acteur, résultat attendu,
comportement visible, defaults matériels, règles métier, décisions, contraintes,
cas limites, erreurs et non-scope. Delivery conserve l'autonomie technique à
l'intérieur de ces contrats.

```text
CLARIFICATION != HUMAN_ACCEPTANCE
```

La clarification réduit les mauvaises hypothèses avant implémentation; elle ne
remplace jamais l'acceptance humaine du comportement réel, des defaults, du
feedback, de la réversibilité, des permissions, notifications ou automatisations.

Ce gate ne déclenche aucune réécriture rétroactive du backlog. Préserver
`VALUE_FIRST`, `MINIMUM_NECESSARY`, `SIMPLEST_SUFFICIENT_PROCESS`,
`STOP_WHEN_DOD_MET` et `NO_PROOF_FOR_PROOF_SAKE`; pas de grilling obligatoire,
questionnaire massif, spec lourde pour L0, ADR pour chaque choix UX local,
setting pour chaque option, automatisation sans besoin produit ni abstraction
spéculative.

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
