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
1 issue = 1 branch = 1 PR
```

Ne jamais travailler directement sur `main`.

La convention de branche est :

```text
work/issue-<number>-<slug>
```

Un seul agent modifiant écrit sur une branche à la fois. Des analyses
read-only peuvent être parallèles si elles n'introduisent pas d'autorité
contradictoire.

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
- Les dépenses Codex sont réservées aux besoins réels de développement.
- La revue indépendante n'exige pas de doubler systématiquement les agents.
- Les changements de gouvernance supportent un gate plus strict que les PR
  ordinaires.
