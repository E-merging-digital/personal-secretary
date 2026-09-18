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
mentions textuelles de Person
alternative linguistique entre mentions
candidat de responsabilité = SELF | référent textuel | NONE
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

## Identité et autorisation

L'IA conserve uniquement les mentions textuelles. Le resolver réutilise `CurrentUserActivityCreationService`, qui réutilise lui-même `HouseholdAuthorizationService` et `CurrentPersonResolver`.

```text
0 correspondance Person autorisée → clarification
1 correspondance exacte autorisée → candidat de revue
>1 correspondance → clarification
alternative linguistique explicite → clarification
```

Une Household n'est auto-sélectionnée que si exactement une Household autorisée et cohérente avec les Persons résolues reste candidate. Sinon l'utilisateur doit choisir/corriger dans la revue. Aucun second système d'autorisation n'est créé.

## Responsabilité

Le modèle n'émet plus le couple fragile `responsibility=TEXT + responsibility_text`.

```text
SELF linguistique → CurrentPerson côté application
référent textuel → résolution Person dans le scope Household autorisé
aucune responsabilité exprimée → aucune responsabilité inventée
```

Une activité hebdomadaire sans responsable résolu reste en clarification, conformément au contrat de création existant.

## Temps

L'`ActivityCaptureInput` reste propriétaire de l'instant d'interprétation figé et du fuseau source. Le modèle ne recopie jamais le fuseau.

Pour une date calendrier explicitement exprimée, l'IA extrait des composantes jour/mois/année ; l'application les valide et construit la date civile. Pour un sens relatif simple, l'extraction peut porter un offset borné que l'application applique à l'instant figé dans le fuseau source. Une mention de jour de semaine est normalisée uniquement dans la petite grammaire produit supportée.

Le resolver ne devient pas un parser générique de langage naturel français et aucune nouvelle dépendance temporelle n'est introduite par cette décision.

Les valeurs absolues normalisées sont celles qui doivent être revues puis confirmées. La confirmation ne réinterprète jamais le texte relatif.

Une heure de fin/durée n'est jamais inventée : si elle est requise pour une activité `TIMED` et absente, la proposition exige une correction utilisateur.

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

Les preuves V1 Granite / Ministral / Qwen et la fixture V1 restent historiques et immuables. Aucun quatrième modèle n'est qualifié dans #160 et aucun benchmark V1 n'est rejoué.

L'architecture expose les seams nécessaires à un futur BENCHMARK_V2, qui devra distinguer :

```text
AI extraction schema validity
AI extraction accuracy
temporal resolver correctness
Person resolution outcome
recurrence normalization outcome
unsupported fail-closed outcome
responsibility mapping outcome
final review proposal correctness
internal ID invention = 0 at the AI boundary
domain mutation = NONE
LOCAL_ONLY = PASS
latency
```

Le BENCHMARK_V2 nécessite une autorité séparée après matérialisation de cette frontière. Un éventuel quatrième modèle ne peut être arbitré qu'après cette preuve d'architecture.

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
- le schéma modèle est réduit à ce qui demande réellement une compréhension linguistique ;
- la normalisation supportée devient déterministe et testable sans LLM réel ;
- aucune dépendance Composer, configuration Drupal, migration de schéma ou migration de données n'est nécessaire ;
- le formulaire structuré reste la voie sûre et toujours disponible.
