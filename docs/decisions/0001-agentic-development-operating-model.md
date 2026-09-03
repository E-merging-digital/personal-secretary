# 0001 — Agentic development operating model

Status: **ACCEPTED**  
Decision authority: GitHub issue #2  
Parent epic: #1

## Context

Personal Secretary doit pouvoir travailler efficacement avec ChatGPT, Codex,
GitHub, GitHub Actions, Agent Skills et de futurs runners sans dépendre de gros
prompts dupliquant les règles permanentes.

Le modèle doit limiter le coût des coding agents, conserver GitHub comme vérité
live, distinguer clairement production et vérification, et préserver une
portabilité des contrats de dépôt sans multiplier les fournisseurs d'exécution.

## Decision

### Autorité

- **ChatGPT Project Lead** possède Product, Architecture, Decisions, autorité,
  arbitrage et revue.
- **ChatGPT Delivery** coordonne l'exécution et peut effectuer le travail GitHub
  léger lorsque cette surface suffit.
- **GitHub** est la vérité live pour issues, branches, PR, checks et merges.
- **GitHub Actions / futurs runners** fournissent des preuves déterministes
  d'exécution.
- **Codex** est l'agent de développement officiel uniquement lorsqu'un besoin
  réel d'exécution le justifie.

Invariant de coût :

```text
CODEX_CALL = ONLY_WHEN_REQUIRED
DEFAULT_CODEX_AGENTS = 1
MULTI_AGENT_CODEX = EXCEPTION_ONLY
```

La portabilité fournisseur s'applique aux contrats de dépôt, pas à l'obligation
d'entretenir plusieurs chemins payants d'exécution.

### Git et unité de travail

```text
1 modifying Task issue = 1 canonical branch = 1 canonical PR
```

Les issues Epic et Decision portent l'intention ou l'autorité. Elles peuvent être
matérialisées par une Task modifiante et sa PR dédiées et n'exigent pas chacune
leur propre branche/PR, sauf si elles sont explicitement converties en travail
modifiant exécutable.

Ne jamais travailler directement sur `main`.

La convention de branche est :

```text
work/issue-<number>-<slug>
```

Un seul agent modifiant écrit sur une branche à la fois. Des analyses
read-only peuvent être parallèles si elles n'introduisent pas d'autorité
contradictoire.

### Simplicité, proportionnalité et valeur

La gouvernance, les gates, tests, revues et preuves existent pour protéger une
valeur livrée ou un risque matériel identifié; ils ne sont pas des livrables en
eux-mêmes. Le plus petit processus suffisant est la voie normale.

```text
VALUE_FIRST = YES
SIMPLEST_SUFFICIENT_PROCESS = REQUIRED
GOVERNANCE_IS_A_MEANS_NOT_A_DELIVERABLE = YES
EVERY_GATE_TEST_OR_PROOF = MUST_PROTECT_IDENTIFIED_RISK_OR_USER_VALUE
REDUNDANT_GATES = PROHIBITED
REDUNDANT_TESTS = PROHIBITED
DUPLICATE_EVIDENCE = PROHIBITED
PROCESS_FOR_PROCESS_SAKE = PROHIBITED
NEW_GATE_REQUIRES_EXPLICIT_RISK_JUSTIFICATION = YES
EXISTING_SUFFICIENT_GATE = REUSE
TEST_THE_CONTRACT_NOT_EVERY_IMPLEMENTATION_DETAIL = YES
EXISTING_TEST_PROVES_RISK = DO_NOT_DUPLICATE
LOW_RISK_MECHANICAL_CHANGE = MINIMAL_VALIDATION
HIGH_RISK_DOMAIN_OR_SECURITY_CHANGE = PROPORTIONAL_TARGETED_VALIDATION
EXTRA_REVIEW_WITHOUT_MATERIAL_VALUE = NO
```

Chaque Task modifiante doit pouvoir répondre simplement à :

```text
WHAT_USER_OR_PRODUCT_VALUE_DOES_THIS_DELIVER?
WHAT_IS_THE_SMALLEST_CHANGE_THAT_DELIVERS_IT?
WHAT_MATERIAL_RISK_MUST_BE_PROVEN?
```

Les tests ciblent les contrats, comportements métier, régressions réalistes et
frontières de sécurité matérielles, pas chaque détail d'implémentation. Une
preuve existante suffisante est réutilisée; les gates, tests, preuves ou revues
dupliqués sont interdits. Une modification mécanique à faible risque reçoit une
validation minimale; un changement métier ou sécurité à risque élevé reçoit une
validation ciblée et proportionnée. Entre deux processus également sûrs,
préférer le plus simple, le plus court et le moins coûteux.

### Vérification indépendante

Les invariants sont :

```text
THE PRODUCER MUST NOT BE THE ONLY VERIFIER
NO APPROVAL WITHOUT EVIDENCE
```

La voie normale lorsque Codex produit un changement est :

```text
Codex producer when required
-> deterministic CI/evidence
-> independent Project Lead review from GitHub exact HEAD
```

Un second Codex reviewer n'est pas requis par défaut. Il n'est justifié que
lorsqu'une seconde exécution ou un raisonnement indépendant supplémentaire
apporte une valeur matérielle.

Les preuves doivent être liées au candidat réellement revu : SHA exact, diff,
résultats de checks/tests applicables, artifacts ou observations déterministes
selon le risque.

### Trust root

La trust root inclut au minimum :

- `AGENTS.md`;
- `.agents/skills/**`;
- `.github/agents/**`;
- `.github/workflows/**`;
- `.github/ISSUE_TEMPLATE/**`;
- décisions et documents durables d'autorité/workflow;
- contrats de revue, CI et autorité.

Pendant Epic 0 :

```text
TRUST_ROOT_CHANGE
-> deterministic validation
-> exact-head reload
-> PROJECT_LEAD_APPROVAL
-> merge
```

Delivery ne peut pas fusionner autonomement la première PR de gouvernance.

### Gates humains

Une approbation humaine est une permission bornée pour l'action nommée; elle
n'annule pas une autorité GitHub plus récente.

Pour une action explicitement humainement gated, destructive, sensible aux
secrets/credentials ou matériellement irréversible :

```text
prepare gate
-> request approval
-> receive explicit approval
-> FINAL_LIVE_AUTHORITY_RELOAD
-> execute
```

L'action échoue en fail closed si le reload final révèle un hold, un changement
de scope, une dépendance non satisfaite, une autorité contradictoire ou toute
condition rendant l'approbation obsolète.

### Main protection

La protection de `main` est une cible approuvée mais suit obligatoirement :

```text
governance bootstrap
-> minimal CI materialized and proven
-> main ruleset/protection
-> prove PR-only path
```

Aucun required check ne doit être configuré avant l'existence et la preuve de
ce check.

## Consequences

- Les prompts d'exécution restent courts et référencent les sources durables.
- GitHub live et le dépôt portent l'autorité plutôt que la mémoire d'une
  conversation.
- Epic/Decision restent des autorités distinctes des Tasks modifiantes et ne
  créent pas artificiellement une branche/PR chacun.
- Les dépenses Codex sont réservées aux besoins réels de développement.
- La revue indépendante n'exige pas de doubler systématiquement les agents.
- Les changements de gouvernance supportent un gate plus strict que les PR
  ordinaires.
