# Personal Secretary — frontend technical contract

Ce document définit les conventions techniques frontend durables de Personal
Secretary. Il complète `DESIGN.md` sans dupliquer les décisions produit/domaine.
La décision d'architecture applicable est
`docs/decisions/0007-design-system-canvas-sdc-and-frontend-validation.md`.

Aucun thème frontend, token concret, breakpoint, package npm ou configuration
Playwright n'est matérialisé par ce document.

## Frontend / administration boundary

- Gin est un thème d'administration ; il ne devient pas le thème frontend de
  l'application.
- La navigation/admin UX Drupal reste séparée de la navigation et du design de
  l'application Personal Secretary.
- Le futur thème frontend sera choisi/créé par une Task dédiée après bootstrap ;
  il doit respecter ce contrat et `DESIGN.md`.

## Single-Directory Components

```text
SDC = canonical custom Drupal UI component primitive
```

Un composant custom réutilisable doit privilégier SDC/Core avant une abstraction
frontend maison.

### Naming et structure

- Noms machine sémantiques et stables, en kebab-case lorsque le nom doit être
  exprimé comme identifiant de composant.
- Un composant regroupe ses fichiers et assets qui lui appartiennent dans son
  répertoire SDC.
- Éviter les noms liés à une page précise lorsqu'un rôle réutilisable décrit le
  composant.

Structure attendue lorsque les fichiers sont nécessaires :

```text
components/<component-name>/
  <component-name>.component.yml
  <component-name>.twig
  <component-name>.css
  <component-name>.js
  assets/
```

Les fichiers CSS/JS/assets ne sont créés que lorsque le composant en a besoin.

### Props et slots

Pour tous les SDC custom :

```text
props = explicit JSON Schema
slots = explicitly declared and documented
enforce_prop_schemas = true
```

Chaque prop déclare :

- type explicite ;
- titre/description utiles ;
- caractère requis uniquement lorsqu'il est réellement obligatoire ;
- contraintes (`enum`, format, range ou équivalent) lorsqu'elles clarifient le
  contrat ;
- exemples uniquement lorsqu'ils améliorent la compréhension sans figer une
  valeur de design.

Chaque slot déclare son nom, son intention et les attentes de composition. Un
slot ne remplace pas le typing d'une prop structurée lorsqu'un vrai contrat de
données est nécessaire.

## Twig boundary

Twig est une couche de rendu/presentation.

Twig ne doit pas posséder :

```text
business rules
effective responsibility
authorization
data-classification decisions
mutation policy
provider selection
```

Les données arrivent déjà validées sous une forme adaptée au rendu depuis les
capacités applicatives/Drupal appropriées.

Éviter la logique complexe dans les templates ; préférer des view models,
preprocess/plugins/services adaptés lorsque la préparation technique est
nécessaire, sans déplacer les Domain Services dans le thème.

## CSS architecture

- Respecter les standards CSS Drupal courants au moment de l'implémentation.
- Garder le style d'un composant local au composant autant que possible.
- Utiliser une convention de classes cohérente de type BEM lorsqu'elle améliore
  la lisibilité et évite le couplage au DOM.
- Ne pas sélectionner des éléments par structure DOM fragile lorsqu'une classe
  sémantique explicite convient mieux.
- Ne pas répéter de valeurs visuelles arbitraires : consommer les design tokens.
- Les utilitaires globaux éventuels doivent avoir une responsabilité clairement
  transversale et ne pas devenir une seconde architecture de composants.

## Design tokens

Le modèle de tokens est repository-owned.

```text
canonical machine format once values exist = DTCG JSON
runtime web surface = CSS custom properties
```

Chaîne attendue :

```text
DESIGN.md
-> semantic token model
-> repository DTCG artifact
-> CSS custom properties
-> SDC
-> application / optional Canvas composition
```

Le premier artefact DTCG n'est créé qu'une fois les décisions visuelles réelles
(colorimétrie, typographie, spacing, etc.) acceptées. Aucun token fictif n'est
créé pour satisfaire la structure.

Les tokens de composant ne sont ajoutés que lorsqu'un rôle réutilisable ne peut
pas être exprimé par les tokens sémantiques existants.

## Assets et libraries Drupal

- Les assets strictement liés à un SDC restent colocalisés avec celui-ci lorsque
  SDC/Core permet leur chargement normal.
- Une library globale n'est créée que pour un comportement/style réellement
  global.
- Ne pas charger du JavaScript global pour un composant qui n'en a besoin que
  localement.
- Les overrides de libraries Core/contrib doivent être bornés, documentés et
  justifiés par un gap concret.

## JavaScript et progressive enhancement

```text
HTML/CSS first
-> progressive enhancement
-> JavaScript only when needed
```

- Préserver le fonctionnement essentiel sans JavaScript lorsque le produit le
  permet raisonnablement.
- Utiliser les primitives Drupal courantes (behaviors/once ou leur successeur
  officiel au moment de l'implémentation) plutôt qu'un bootstrap JS maison.
- Les interactions enrichies doivent préserver navigation clavier, focus,
  annonces accessibles et reduced motion.
- Un état client ne devient pas source de vérité métier.
- Une capacité JS-rich intrinsèque peut justifier plus tard un Canvas Code
  Component, mais cela reste une exception revue, pas le défaut.

## Canvas boundary

Canvas est une future surface de composition gouvernée, activée uniquement
lorsqu'un vrai dashboard/page composable le justifie.

```text
Canvas may compose SDC / governed presentation
Canvas must not own domain truth / permissions / authorization / classification
```

Les composants exposés à Canvas doivent conserver des props/slots suffisamment
explicites pour que la composition reste compréhensible. Les formulaires métier
critiques restent des interfaces applicatives gouvernées sauf décision future
bornée.

Canvas AI/provider n'est pas une dépendance frontend implicite.

## Responsive implementation

- L'implémentation suit les principes de `DESIGN.md` et la priorité du contenu.
- Aucun breakpoint n'est figé avant le premier vrai layout.
- Les breakpoints futurs sont versionnés dans le code/tokens adaptés et choisis
  selon les points de rupture du contenu, pas une liste d'appareils arbitraires.
- Le DOM et l'ordre de focus restent logiques entre tailles d'écran.
- Les fonctionnalités essentielles restent utilisables avec zoom/reflow.

## Browser support

Ne pas stocker une liste de versions de navigateurs qui devient rapidement
obsolète. Au moment de chaque bootstrap ou changement matériel de frontend,
recharger la politique de support navigateur Drupal courante et définir la
matrice de test proportionnée aux parcours critiques.

## Accessibility engineering

Cible : **WCAG 2.2 AA**.

Règles d'implémentation :

- utiliser d'abord le HTML sémantique ; ARIA complète la sémantique uniquement
  lorsque le HTML natif ne suffit pas ;
- toutes les interactions critiques sont utilisables au clavier ;
- focus visible, logique et non masqué ;
- labels et messages d'erreur liés aux contrôles concernés ;
- états dynamiques annoncés lorsqu'une technologie d'assistance doit les
  percevoir ;
- ne jamais encoder une distinction seulement par la couleur ;
- respecter contrastes, zoom/reflow et reduced motion ;
- considérer ATAG pour les interfaces d'authoring/composition telles que Canvas.

Les tests automatisés complètent mais ne remplacent pas la vérification
manuelle des parcours clavier et des sémantiques importantes.

## Playwright strategy

Playwright devient le standard de preuve navigateur lorsqu'une UI significative
existe. Aucun package/config n'est créé avant cela.

Circuit cible :

```text
frontend change
-> DDEV/browser runtime
-> Playwright
-> functional assertions
   + responsive assertions
   + keyboard interactions
   + ARIA/accessibility structure
   + screenshots/visual comparisons where justified
-> exact-head evidence
```

Les parcours critiques sont testés fonctionnellement avant d'ajouter des
snapshots visuels.

Les baselines visuelles ne sont utilisées que sur un environnement suffisamment
reproductible : navigateur/runtime, fonts, viewport, locale/timezone, données de
test, assets et animations doivent être contrôlés pour éviter les faux positifs
permanents.

Les artefacts d'échec (trace, screenshot, logs applicables) doivent être
conservés par la CI lorsqu'ils améliorent le diagnostic et respectent la
politique de données synthétiques/privacy.

## Frontend CI gates

Une future CI frontend doit rester proportionnée au risque et peut inclure,
selon le scope :

```text
lint/static validation
SDC schema validation
functional browser tests
keyboard/ARIA checks
visual comparisons on controlled runner
```

Chaque preuve doit être attribuable au HEAD exact revu conformément au workflow
du dépôt.

## Figma synchronization contract

Figma est une surface optionnelle de design/développement adoptée lorsque le
travail UI substantiel commence.

```text
repository = authority
Figma = mirror / exploration / review
```

### Variables / DTCG

Direction normale :

```text
repository DTCG
-> Figma Variables mirror
```

Un changement initié dans Figma suit :

```text
Figma proposal/export
-> repository diff/proposal
-> review
-> accepted repository change
-> implementation
```

Aucun export Figma ne remplace automatiquement les tokens du dépôt.

### Code Connect

Code Connect est optionnel. Il peut plus tard fournir des exemples
représentatifs d'usage Twig/SDC si cela améliore Dev Mode/handoff, mais aucune
synchronisation native first-class entre Figma et Drupal SDC n'est supposée.

Un mapping Code Connect ne devient pas une seconde définition du schema SDC.

### Figma MCP / agents

Une future intégration Figma MCP ou write-to-canvas peut améliorer le workflow
design/développement d'agents lorsqu'elle est explicitement autorisée. Elle
reste hors runtime applicatif et ne confère aucune autorité de mutation au
produit Personal Secretary.

## Component preview

Storybook est différé. D'abord utiliser le rendu Drupal réel, SDC, le futur DDEV,
Canvas lorsqu'il est activé, Figma lorsqu'il apporte une valeur de design et
Playwright pour la preuve navigateur.

Storybook ou une autre preview isolée ne sont ajoutés que si ce workflow démontre
un gap concret.
