# 0018 — Extraction IA étroite et résolution déterministe du quick capture

Status: **ACCEPTED**
Decision authority: GitHub issue #159 / Project Lead acceptance comment 5721267759
Materialization task: #160

## Contexte

Les qualifications LOCAL_ONLY de Granite 4.1 3B, Ministral 3 3B et Qwen3.5 9B ont toutes rejeté le modèle candidat sur le contrat historique `ActivityCaptureProposal`. Elles ont aussi montré qu'une part importante des erreurs portait sur des décisions que l'application peut posséder de façon plus fiable : identité, autorisation, support de récurrence, normalisation temporelle, représentation finale de responsabilité et besoin de clarification.

Le flux produit accepté reste inchangé :

```text
texte naturel
→ extraction IA non autoritative
→ normalisation/résolution déterministe
→ revue structurée éditable
→ confirmation explicite
→ autorisation serveur fraîche
→ mutation Drupal gouvernée
```

Le problème n'est donc pas l'existence d'un appel IA unique, mais la largeur de son ancien contrat.

## Décision

L'IA ne produit plus directement le `ActivityCaptureProposal` final. Elle produit un `ActivityCaptureExtraction` étroit, limité à des candidats linguistiques :

```text
label/location textuels
mentions textuelles de Person explicitement concernées par l'activité
mentions textuelles de Person dont le rôle ne peut pas être classifié de façon fiable
alternative linguistique entre mentions concernées
candidat de responsabilité = SELF | référent textuel explicitement responsable | NONE
expression de date
composantes calendaires explicites jour/mois/année
sens relatif borné en jours
expressions d'heure de début/fin
expression de récurrence
signaux explicites ALL_DAY / TIMED
```

Cette extraction ne contient jamais :

```text
Person ID / UUID
Household ID / UUID
autorisation
RRULE
source timezone choisie par le modèle
date absolue finale autoritative
responsabilité domaine finale
verdict global authoritative ambiguous / unsupported
```

`ActivityCaptureResolver` compose ensuite l'extraction avec `ActivityCaptureInput` et l'état Drupal autorisé afin de produire un `ActivityCaptureProposal` de **revue**, ou des codes de clarification. Le resolver ne mute aucune entité.

## Identité, rôles de Person et autorisation

L'IA conserve uniquement des mentions textuelles et possède la classification linguistique étroite de leur rôle. `concerned_person_mentions` contient les Persons explicitement exprimées comme participantes, sujets, bénéficiaires ou directement concernées par l'activité. Une Person exprimée uniquement comme responsable n'y est pas copiée.

Une même Person peut légitimement apparaître comme concernée et comme responsable lorsque le texte exprime explicitement les deux rôles. Si une mention de Person est explicite mais que son rôle ne peut pas être classifié de façon fiable, l'IA la place uniquement dans `unclassified_person_mentions`. L'application n'infère alors aucun rôle et exige la clarification `person_role_requires_selection`.

Le contrat courant rejette une même mention normalisée à la fois `unclassified` et `concerned`, ou `unclassified` et responsabilité textuelle. La coexistence `concerned` + responsabilité textuelle reste valide pour le dual-role explicite. La normalisation cross-role est bornée aux différences inoffensives de casse et d'espaces ; elle n'est pas une analyse linguistique.

Le resolver réutilise `CurrentUserActivityCreationService`, qui réutilise lui-même `HouseholdAuthorizationService` et `CurrentPersonResolver`.

```text
0 correspondance Person autorisée → clarification
1 correspondance exacte autorisée → candidat de revue
>1 correspondance → clarification
alternative linguistique explicite → clarification
rôle textuel non classifié → clarification, sans assignation silencieuse
```

Une Household n'est auto-sélectionnée que si exactement une Household autorisée et cohérente avec les Persons résolues reste candidate. Sinon l'utilisateur doit choisir/corriger dans la revue. Aucun second système d'autorisation n'est créé.

## Responsabilité

Le modèle n'émet plus le couple fragile `responsibility=TEXT + responsibility_text`.

```text
SELF linguistique → CurrentPerson côté application
référent textuel explicitement responsable → résolution Person dans le scope Household autorisé
aucune responsabilité exprimée → aucune responsabilité inventée
```

L'identité, l'autorisation, la représentation finale de responsabilité et la politique de clarification restent possédées par l'application. Une activité hebdomadaire sans responsable résolu reste en clarification, conformément au contrat de création existant.

## Temps

L'`ActivityCaptureInput` reste propriétaire de l'instant d'interprétation figé et du fuseau source. Le modèle ne recopie jamais le fuseau.

Pour une date calendrier explicitement exprimée, l'IA extrait des composantes jour/mois/année ; l'application les valide et construit la date civile. Pour un sens relatif simple, l'extraction peut porter un offset borné que l'application applique à l'instant figé dans le fuseau source. Une mention de jour de semaine est normalisée uniquement dans la petite grammaire produit supportée.

Le resolver ne devient pas un parser générique de langage naturel français et aucune nouvelle dépendance temporelle n'est introduite par cette décision.

Les valeurs absolues normalisées sont celles qui doivent être revues puis confirmées. La confirmation ne réinterprète jamais le texte relatif.

Une heure de fin/durée n'est jamais inventée : si elle est requise pour une activité `TIMED` et absente, la proposition exige une correction utilisateur.

Le signal `explicit_all_day_signal=true` exige une preuve linguistique explicite que l'activité couvre toute la journée, par exemple « toute la journée », « pour toute la journée », « journée entière de formation » ou une formulation sémantiquement équivalente. La présence lexicale de `journée` seule dans « journée administrative », « journée pédagogique », « journée portes ouvertes », « journée de formation » ou « journée au bureau » ne suffit pas. Sans preuve ALL_DAY ni preuve TIMED, l'application conserve la clarification déterministe `time_mode_required`.

Le resolver mappe les signaux extraits vers `ALL_DAY`, `TIMED`, conflit ou clarification ; il ne parse pas le français pour fabriquer ces signaux.

## Récurrence

Le modèle extrait uniquement l'expression de récurrence. L'application possède la grammaire supportée, l'intent, le jour de semaine, la construction RRULE et le verdict unsupported.

Le premier périmètre conserve les formes réellement matérialisées :

```text
ONE_OFF
WEEKLY
```

Toute récurrence complexe hors grammaire, par exemple « le premier lundi de chaque mois », échoue fermé vers clarification / édition structurée. `AddActivityService` et `date_recur` restent les autorités de construction et validation RRULE.

```text
RAW_RRULE_FROM_AI = NO
```

## Proposition de revue et fallback

`ActivityCaptureProposal` est désormais une valeur **générée par l'application**. Elle peut donc contenir des identifiants déjà résolus dans le scope autorisé et des valeurs temporelles normalisées ; cela ne donne aucune autorité au modèle.

Une proposition n'est confirmable que si ses champs obligatoires sont complets et qu'aucune clarification ne reste ouverte. Le formulaire structuré existant reste immédiatement disponible et ne doit jamais être bloqué par la latence de l'IA.

```text
AI failure / timeout / invalid extraction
unsupported recurrence
unresolved identity
ambiguous temporal semantics
missing required end/duration
→ ZERO domain mutation
→ ZERO cloud fallback
→ structured manual capture remains available
```

L'écriture autonome reste interdite et la confirmation explicite reste obligatoire.

## Modèle et benchmark

Les preuves V1 Granite / Ministral / Qwen, leurs fixtures/runners/rapports et le BENCHMARK_V2 terminal #164 sont des contrats historiques immuables. Le changement de contrat #168 ne les réécrit, ne les rescore et ne les rejoue pas.

Le contrat provider courant ajoute `unclassified_person_mentions` comme seizième champ requis. `ActivityCaptureInterpreter` hydrate ce contrat strictement. Le chemin historique `ActivityCaptureExtraction::fromArray()` conserve uniquement la compatibilité bornée avec les anciens payloads 15 champs en défautant ce nouveau champ à `[]` ; il ne doit jamais servir à affaiblir la validation provider courante.

La prochaine génération de benchmark, si elle est autorisée après revue de #168, sera BENCHMARK_V3 avec une fixture et des attentes nouvelles figées avant toute inférence. Elle devra distinguer explicitement les rôles Person concerné/responsable/non classifié ainsi que les formulations ALL_DAY explicites des formulations ambiguës contenant seulement « journée ».

Aucun modèle n'est exécuté et aucun provider n'est adopté par #168.

## Fonctionnalités différées

```text
PreparationRequirement extraction = DEFERRED
preparation lead extraction = DEFERRED
TimeCommitment extraction = DEFERRED
generic confidence = DEFERRED
CCC / drupal/ai_context = OUT OF SCOPE
agent loop / multi-pass semantic inference = NONE
cloud fallback = NONE
```

## Conséquences

- Drupal reste la vérité métier et l'autorité d'identité/autorisation ;
- l'appel IA reste unique et non autoritatif ;
- le schéma modèle reste réduit à ce qui demande réellement une compréhension linguistique, y compris les rôles textuels de Person et la preuve explicite ALL_DAY ;
- les ambiguïtés de rôle Person échouent fermé sans assignation silencieuse ;
- la normalisation supportée devient déterministe et testable sans LLM réel ;
- le resolver ne devient jamais un parseur linguistique générique du français ;
- aucune dépendance Composer, configuration Drupal, migration de schéma ou migration de données n'est nécessaire ;
- le formulaire structuré reste la voie sûre et toujours disponible.
