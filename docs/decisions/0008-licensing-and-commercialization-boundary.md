# 0008 — Licensing and commercialization boundary

Status: **ACCEPTED**
Decision authority: GitHub issue #8
Materialization task: #27

## Context

Personal Secretary est un dépôt public destiné à accueillir du code Drupal et du
code custom substantiel. Epic 0 avait volontairement différé toute licence ; ce
gate est maintenant levé avant le premier bootstrap fonctionnel.

Cette décision matérialise l'arbitrage licensing du Project Lead. Elle ne modifie
pas les autres frontières produit, domaine, privacy ou frontend établies par les
décisions 0001–0007.

## Decision

```text
REPOSITORY_LICENSE = GPL-2.0-or-later
SPDX = GPL-2.0-or-later
```

Le code et la documentation appartenant à Personal Secretary dans ce dépôt sont
publiés sous la **GNU General Public License version 2 or later**.

Le texte canonique de la GNU GPL version 2 est fourni dans `LICENSE.txt`. Le choix
`or later` est l'option applicable au dépôt et doit être exprimé par l'identifiant
SPDX `GPL-2.0-or-later` dans les métadonnées de projet pertinentes.

La règle historique `LICENSE = NONE FOR EPIC 0` de la décision 0002 décrivait
uniquement le hold volontaire de cette phase initiale ; la présente décision est
l'autorité ultérieure applicable au dépôt.

### Portée repository-owned

```text
repository-owned software = GPL-2.0-or-later
repository-owned documentation = GPL-2.0-or-later
```

`DESIGN.md`, `FRONTEND.md`, `docs/**` et les futurs artefacts repository-owned ne
reçoivent pas un régime documentaire propriétaire séparé.

Les fichiers ou assets tiers peuvent conserver une licence distincte uniquement
lorsque leur provenance et leur licence sont explicitement documentées et que
leur mode de distribution/agrégation est compatible avec Personal Secretary.

Les dépendances Composer conservent leur licence upstream. Le fait de référencer
une dépendance ou sa metadata ne la relicencie pas sous la licence du package
racine.

### Drupal boundary

Drupal est distribué sous GNU GPL version 2 or later. Les modules, thèmes et
travaux dérivés Drupal distribués depuis Personal Secretary doivent respecter la
GPL v2-or-later conformément à la guidance officielle Drupal applicable.

Pour le code Drupal intégré de ce dépôt :

```text
MIT = NOT_SELECTED_AS_REPOSITORY_LICENSE
Apache-2.0 = NOT_SELECTED_AS_REPOSITORY_LICENSE
NO_LICENSE = NOT_SELECTED
AGPL = NOT_SELECTED_AS_REPOSITORY_BASELINE
```

Cette décision ne crée pas de licence hybride et ne tente pas de rendre
propriétaire un module ou thème Drupal dérivé pour contourner les obligations GPL.

### Commercialization boundary

```text
COMMERCIAL_USE = ALLOWED
SELLING_COPIES = ALLOWED
SERVICES/HOSTING/SUPPORT = ALLOWED
```

La GPL n'est pas une licence non commerciale. E-merging Digital peut vendre des
copies du logiciel ainsi que de l'implémentation, de l'intégration, de la
maintenance, du support et de l'hébergement.

Lorsqu'une copie couverte par la GPL est distribuée à un destinataire, celui-ci
conserve les droits et libertés applicables de la GPL concernant le code source,
la modification et la redistribution. Le modèle commercial ne doit donc pas
reposer sur l'exclusivité propriétaire de modules ou thèmes Drupal dérivés qui
sont distribués.

Une offre hébergée sous GPL ordinaire ne déclenche pas, par le seul usage réseau,
l'obligation spécifique de mise à disposition du source associée à l'AGPL.
L'AGPL n'est pas retenue comme baseline du dépôt.

Une future capacité propriétaire n'est envisageable sans nouvelle décision que
si elle est réellement indépendante/non dérivée et séparée par une frontière
claire. Toute opération où la qualification dérivée, la redistribution
propriétaire, le dual licensing, un code tiers inhabituel ou des restrictions
contractuelles de source deviennent déterminants exige une revue licensing
spécifique avant distribution.

### Future Composer contract

Lors du futur bootstrap Composer, le package racine doit déclarer :

```json
{
  "license": "GPL-2.0-or-later"
}
```

Cette décision n'autorise ni ne crée le projet Composer.

### SPDX headers

```text
MANDATORY_PER_FILE_SPDX_HEADERS = NO
```

Le baseline est le `LICENSE.txt` racine, la déclaration README, cette décision et
la future metadata du package Composer. Des headers SPDX par fichier peuvent être
introduits plus tard uniquement si une convention dédiée démontre une valeur
réelle.

## Official references

- Drupal licensing: https://www.drupal.org/about/licensing
- Drupal User Guide — Concept: Drupal Licensing: https://www.drupal.org/docs/user_guide/en/understanding-gpl.html
- GitHub — Licensing a repository: https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/customizing-your-repository/licensing-a-repository
- GNU GPL FAQ: https://www.gnu.org/licenses/gpl-faq.en.html
- SPDX `GPL-2.0-or-later`: https://spdx.org/licenses/GPL-2.0-or-later.html

Ces références soutiennent une décision d'architecture/licensing du projet ; ce
document n'est pas un avis juridique personnalisé.

## Consequences

- Le gate licensing précédant le premier code Drupal/custom substantiel est
  matérialisé une fois cette décision fusionnée.
- Le dépôt possède une licence unique et explicite pour son code et sa
  documentation repository-owned : `GPL-2.0-or-later`.
- Les licences tierces restent attribuées à leurs composants respectifs avec
  provenance explicite ; elles ne modifient pas la licence du code Personal
  Secretary.
- Le futur package Composer racine utilise `GPL-2.0-or-later`.
- Aucun header SPDX par fichier n'est exigé au bootstrap.
- Commercialisation, vente, services et hébergement restent compatibles avec le
  choix GPL, sous réserve des obligations applicables lors de la distribution.
