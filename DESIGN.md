# Personal Secretary — design contract

Ce document porte les règles design et UX durables de Personal Secretary. Il ne
constitue ni une maquette, ni un catalogue de valeurs visuelles finales. Les
choix concrets de couleurs, polices, dimensions, breakpoints et valeurs de
tokens seront décidés et versionnés lorsque le premier travail UI réel les
justifiera.

L'autorité frontend technique correspondante vit dans `FRONTEND.md`. La décision
d'architecture applicable est `docs/decisions/0007-design-system-canvas-sdc-and-frontend-validation.md`.

## Principes visuels produit

- Prioriser la compréhension de la situation et la prochaine action utile avant
  la décoration.
- Maintenir une hiérarchie visuelle stable afin qu'une même importance métier
  reçoive un traitement cohérent entre écrans.
- Préférer des patterns simples, prévisibles et réutilisables aux variations
  locales improvisées.
- Limiter la densité et la divulgation de données privées à ce qui est utile au
  contexte courant.
- Faire de l'accessibilité, de la lisibilité et du responsive des contraintes de
  conception initiales.
- Toute nouvelle direction visuelle proposée par un humain, Figma ou un agent
  reste une proposition jusqu'à réconciliation explicite avec ce contrat et les
  artefacts du dépôt.

## Hiérarchie de l'information

L'interface distingue explicitement :

1. ce qui nécessite une action ou attention immédiate ;
2. ce qui est pertinent pour le contexte/responsabilité courante ;
3. les informations de support ou secondaires ;
4. les détails accessibles à la demande.

La proximité visuelle ne doit pas suggérer une relation métier inexistante. Une
préparation, une tâche et un événement calendrier restent des concepts distincts
même s'ils sont présentés dans un même dashboard.

## Design tokens

Les valeurs visuelles réutilisables passent par des tokens sémantiques
repository-owned. Le futur format machine-readable canonique est DTCG JSON ; les
CSS custom properties constituent leur surface runtime web.

Taxonomie à couvrir lorsque les valeurs seront réellement décidées :

```text
color / surface
color / text
color / border
color / action
color / state
spacing
typography
radii
elevation
motion
focus
```

Les noms doivent exprimer un rôle sémantique plutôt qu'une valeur physique ou
une couleur particulière lorsque le rôle peut rester stable.

Aucune valeur de token n'est définie dans ce document.

## Couleurs et surfaces

Le système devra distinguer sémantiquement au minimum : surfaces, texte,
bordures, actions, focus et états de feedback. Les états ne peuvent pas reposer
uniquement sur la couleur.

Les valeurs finales et la palette de marque restent volontairement non décidées.

## Typographie

La typographie doit exprimer des rôles stables tels que titres, texte courant,
labels, métadonnées et contenus d'action. Les composants consomment ces rôles
plutôt que des styles typographiques locaux arbitraires.

Aucune famille de police ni échelle chiffrée n'est décidée ici.

## Espacement, rayons et élévation

Espacements, rayons et élévations suivent des échelles/tokens cohérents une fois
le design visuel établi. Un composant ne crée pas une nouvelle valeur isolée si
un rôle sémantique existant répond au besoin.

Aucune valeur physique n'est décidée ici.

## Light / dark mode

Le design-token model doit permettre une trajectoire light/dark sans renommer
les rôles sémantiques des composants. L'adoption d'un mode sombre et ses valeurs
concrètes appartiennent à un futur travail UI ; aucun mode n'est implicitement
promis par ce document.

## Responsive

- Concevoir à partir de la priorité du contenu et des interactions plutôt que
  d'une liste d'appareils figée.
- Préserver l'ordre logique et la compréhension lorsque l'espace se réduit.
- Éviter les composants dont le sens dépend d'une largeur fixe.
- Les breakpoints concrets sont une décision technique de `FRONTEND.md` et du
  futur thème, revalidée lorsque l'UI existe ; aucune valeur n'est fixée ici.
- Zoom et reflow ne doivent pas masquer les fonctions essentielles.

## Motion

Les animations doivent expliquer un changement d'état, une relation spatiale ou
un feedback utile. Elles ne sont pas décoratives par défaut.

Le système doit prévoir des tokens de durée/easing lorsque le besoin apparaît et
respecter `prefers-reduced-motion` avec une alternative réduite ou sans motion.

## Navigation

- La navigation applicative reflète les tâches et domaines utilisateur, pas la
  structure technique Drupal.
- La navigation admin Drupal reste séparée de la navigation de l'application.
- Les actions critiques doivent conserver un emplacement/pattern prévisible.
- Une composition Canvas future peut organiser des composants autorisés mais ne
  redéfinit pas le modèle de navigation métier ou les permissions.

## Formulaires

- Labels explicites et persistants lorsque nécessaire à la compréhension.
- Groupement logique des champs sans masquer la hiérarchie métier.
- Aides et contraintes placées près du champ/action concerné.
- Erreurs compréhensibles, reliées au contrôle concerné et détectables sans
  dépendre uniquement de la couleur.
- Confirmation/gate humain lorsqu'une mutation sensible l'exige selon les
  décisions métier/privacy.

## Dashboards

Un dashboard peut présenter des zones telles que Today, Children, Preparations,
Tasks, Calendar ou d'autres capacités futures, mais la composition visuelle ne
possède jamais la vérité métier sous-jacente.

- Montrer d'abord les éléments pertinents pour l'utilisateur et son contexte.
- Réduire l'exposition de détails sensibles lorsqu'un résumé suffit.
- Conserver des composants compréhensibles indépendamment de leur position dans
  la composition.
- Une future personnalisation Canvas choisit placement/présentation, pas règles
  de responsabilité, autorisation ou classification.

## États d'interface

Tout pattern de données asynchrone ou actionnable doit considérer explicitement :

```text
empty
loading
success
warning
error
unavailable / permission denied when applicable
```

Un état vide explique ce qui manque ou la prochaine action pertinente ; il ne
simule pas des données. Les erreurs ne doivent pas exposer de données sensibles
ou de détails techniques inutiles.

## Privacy-sensitive UI

- Afficher uniquement le niveau de détail nécessaire au contexte courant.
- Éviter de rendre visibles des données familiales, documentaires ou financières
  sensibles dans un écran récapitulatif si une représentation minimisée suffit.
- Ne jamais placer de credential, secret ou donnée de diagnostic sensible dans
  un exemple visuel, fixture ou capture destinée au dépôt public.
- Les captures/tests publics utilisent uniquement des données synthétiques.
- Les actions sensibles doivent rendre leur effet compréhensible avant
  confirmation lorsque le domaine exige un gate humain.

## Accessibilité

Cible : **WCAG 2.2 AA**.

Le design doit prévoir dès le départ :

- HTML sémantique et structure compréhensible ;
- navigation clavier complète ;
- focus visible et non masqué ;
- contrastes suffisants ;
- information indépendante de la couleur ;
- labels, instructions et erreurs compréhensibles ;
- zoom/reflow et responsive ;
- alternatives aux interactions dépendant uniquement du drag/pointeur ;
- réduction du mouvement ;
- annonces/feedback accessibles pour les changements d'état lorsque nécessaire.

Pour les interfaces de composition, ATAG est une lentille complémentaire :
l'outil d'authoring doit lui-même rester accessible et aider à produire une UI
accessible.

## Do / don't

### Do

- Réutiliser les tokens et patterns acceptés.
- Garder une hiérarchie claire et constante.
- Préférer les composants SDC réutilisables aux fragments visuels divergents.
- Valider les parcours critiques dans le vrai navigateur lorsque l'UI existe.
- Documenter explicitement une nouvelle règle design durable avant de la
  répandre dans plusieurs composants.

### Don't

- Inventer des couleurs, polices ou valeurs locales pour accélérer un composant.
- Utiliser Canvas, Figma ou une sortie IA comme autorité implicite.
- Confondre composition visuelle et logique métier.
- Masquer une information importante uniquement derrière une couleur, animation
  ou interaction au pointeur.
- Copier des données personnelles réelles dans maquettes, captures, tests ou
  exemples du dépôt.

## Figma

Figma est une surface future d'exploration, prototype, revue et visualisation du
design system lorsque le travail UI substantiel commence.

```text
repository DESIGN.md + DTCG tokens = authority
Figma Variables = mirror / exploration / review
```

Si Figma diverge du dépôt, le dépôt reste autoritatif jusqu'à une réconciliation
explicite et revue. Un export Figma ne remplace jamais silencieusement les tokens
ou composants de production.

## Références visuelles

Les références ou maquettes futures peuvent être liées lorsque leur statut est
clair. Une référence n'est pas une règle durable tant qu'elle n'est pas
réconciliée avec ce document, les tokens et l'implémentation acceptée.
