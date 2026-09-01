# 0007 — Design system, Canvas, SDC et validation frontend

Status: **ACCEPTED**  
Decision authority: GitHub issue #23  
Parent epic: #16  
Evidence audit: #24  
Materialization task: #25

## Context

Personal Secretary sera développé en partie par des agents. Le design system,
les composants, les règles visuelles et les critères de validation doivent donc
être des artefacts durables et versionnés du dépôt plutôt que du contexte
volatile de conversation.

Le frontend doit rester Drupal-native, accessible, composable lorsque cela
apporte une valeur réelle et suffisamment explicite pour être compris par les
humains, Codex, Canvas et de futurs outils de design.

## Decision

### SDC comme primitive de composant

```text
SDC = canonical custom Drupal UI component primitive
```

Tous les composants Drupal custom réutilisables utilisent Single-Directory
Components avant toute abstraction frontend maison.

Convention de schéma :

```text
ALL CUSTOM SDC PROPS = explicit JSON Schema
SLOTS = explicitly declared and documented
enforce_prop_schemas = true
```

Les props doivent déclarer des types et métadonnées suffisamment explicites
pour permettre validation, compréhension agentique et intégration Canvas. Les
slots sont nommés et documentés avec leur intention de composition.

Twig reste une couche de présentation : aucune règle de domaine, décision
d'autorisation ou calcul de responsabilité n'y est déplacé.

CSS et JavaScript sont colocalisés avec le composant lorsqu'ils lui appartiennent.
JavaScript n'est ajouté que lorsque HTML/CSS et progressive enhancement ne
suffisent pas.

### Autorité design et frontend

```text
DESIGN AUTHORITY = DESIGN.md + repository-owned semantic token model
FRONTEND TECHNICAL AUTHORITY = FRONTEND.md + executable SDC/code/tests
```

`DESIGN.md` porte les règles design/UX normatives. `FRONTEND.md` porte le
contrat technique d'implémentation.

Les artefacts exécutables restent également autoritatifs : schemas SDC, code,
tokens et tests versionnés.

### Design tokens

Les tokens sont sémantiques et repository-owned.

```text
MACHINE TOKEN FORMAT = DTCG JSON
RUNTIME TOKEN SURFACE = CSS custom properties
```

Le futur artefact DTCG devient la source machine-readable canonique une fois les
premières décisions visuelles réelles prises. Cette décision ne crée aucune
valeur de couleur, police, espacement, rayon, breakpoint ou autre valeur de
design arbitraire.

Les composants consomment des tokens sémantiques plutôt que de dupliquer des
valeurs locales sans justification.

### Accessibilité

```text
ACCESSIBILITY_TARGET = WCAG 2.2 AA
```

L'accessibilité est un input du design system et de l'implémentation, pas une
correction finale. HTML sémantique, navigation clavier, focus visible,
contrastes, indépendance à la couleur, formulaires/erreurs, reflow/zoom et
réduction du mouvement doivent être pris en compte dès la conception.

ATAG constitue une lentille pertinente lorsqu'une interface de composition telle
que Canvas permet de produire du contenu ou des interfaces.

### Drupal Canvas

```text
CANVAS = adopt when a real composable UI/dashboard use case exists
CANVAS != bootstrap dependency
CANVAS != domain truth
CANVAS != authorization/policy engine
CANVAS != default engine for critical application forms
```

Canvas peut composer des présentations gouvernées, dashboards configurables et
écrans non critiques à partir de composants/capacités métier existants. Il ne
calcule ni responsabilité, ni permissions, ni classification, ni autorisation
de mutation.

Le flux reste :

```text
Domain/Application Services
-> governed view model / capability
-> SDC
-> optional Canvas composition
```

Canvas Code Components restent une exception pour une UI véritablement riche en
JavaScript ; ils ne remplacent pas Twig/SDC comme primitive custom par défaut.

Canvas AI/provider est différé jusqu'à un cas d'usage réel et une autorité
dédiée.

### Playwright

```text
PLAYWRIGHT = adopt when meaningful browser UI exists
```

La stratégie future couvre proportionnellement :

```text
functional assertions
responsive assertions
keyboard interaction
ARIA/accessibility structure
screenshots / visual comparison
failure artifacts
```

Les snapshots visuels ne sont autoritatifs que dans un environnement
suffisamment reproductible et pinned pour éviter des différences permanentes de
rendu. Les tests visuels ne remplacent ni assertions fonctionnelles ni revue
d'accessibilité.

### Figma

```text
FIGMA = ADOPT_WHEN_UI_WORK_STARTS / optional design-development capability
FIGMA != bootstrap dependency
FIGMA != runtime dependency
FIGMA != source of truth
```

Figma peut servir à explorer, prototyper, documenter visuellement et réviser le
design system. Les Figma Variables peuvent refléter le modèle de tokens du
dépôt ; elles ne créent pas une seconde autorité indépendante.

Frontière de convergence :

```text
DESIGN.md + repository DTCG tokens = design authority
Figma Variables = mirror / exploration / review
SDC = executable reusable component authority
Canvas = governed composition inside Drupal
Playwright = proof of actual browser output
```

En cas de divergence, l'autorité acceptée du dépôt gagne jusqu'à une
réconciliation explicite, revue et versionnée.

Figma Code Connect reste optionnel. Des templates représentatifs Drupal/Twig/SDC
peuvent être évalués plus tard, mais aucune synchronisation native first-class
entre Figma et Drupal SDC n'est supposée.

Figma MCP et write-to-canvas restent des capacités optionnelles futures pour le
workflow design/développement des agents. Ils ne font pas partie du runtime
Personal Secretary et aucun MCP n'est autorisé par cette décision.

### Component preview

Storybook reste différé jusqu'à ce que le workflow Drupal-native/Canvas/DDEV/
Playwright/Figma démontre un gap concret de prévisualisation de composants.

## Documentation minimale

Le socle narratif durable est limité à :

```text
DESIGN.md
FRONTEND.md
```

Ne pas créer par défaut des documents `PRODUCT_SENSE.md`, `QUALITY_SCORE.md`,
`PLANS.md` ou `RELIABILITY.md`. Un document `ACCESSIBILITY.md` séparé reste
différé tant que DESIGN/FRONTEND couvrent correctement les responsabilités.

## Consequences

- SDC est la primitive exécutable standard pour les composants custom.
- Les props/slots sont explicitement décrits pour validation et lisibilité
  agentique.
- Le design system possède une autorité repository-owned et une trajectoire DTCG
  sans inventer de valeurs avant le vrai travail UI.
- Canvas devient une capacité de composition future, jamais une source de vérité
  métier.
- Playwright devient la preuve browser standard lorsque l'UI existe.
- Figma est adopté au début du travail UI lorsqu'il apporte de la valeur de
  prototype/revue, sans devenir autorité technique.
- Storybook et Canvas AI restent différés jusqu'à un gap ou cas d'usage réel.
