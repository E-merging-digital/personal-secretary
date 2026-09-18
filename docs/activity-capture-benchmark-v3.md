# Activity Capture BENCHMARK_V3 — Phase A freeze

Date de gel : 2026-09-18

## Objet

BENCHMARK_V3 est une nouvelle génération de benchmark pour le contrat provider courant à 16 champs matérialisé par #168.
Cette Phase A fige la fixture, les attentes, le scorer, les gates et l’oracle déterministe **avant toute inférence provider**.

```text
PROVIDER_RESULTS = NOT_EXECUTED
MODEL_INFERENCE = NONE
MINISTRAL_V3_REQUESTS = 0
OPENAI_SOL_V3_REQUESTS = 0
OPENAI_TERRA_V3_REQUESTS = 0
```

Les preuves V1 et V2 restent historiques, immuables, non rescored et non rejouées.

## Contrat provider courant

Hydration provider autoritative : `ActivityCaptureExtraction::fromProviderArray()`.
Le chemin historique `fromArray()` n’est pas utilisé pour V3.

Les 16 champs scorés pour chaque cas sont :

- `label_text`
- `location_text`
- `concerned_person_mentions`
- `unclassified_person_mentions`
- `concerned_person_alternative`
- `responsibility_candidate`
- `date_expression`
- `date_day`
- `date_month`
- `date_year`
- `relative_day_offset`
- `start_time_expression`
- `end_time_expression`
- `recurrence_expression`
- `explicit_all_day_signal`
- `explicit_timed_signal`

Nombre de cas : **20**.
Nombre total de champs IA attendus : **320**.
Fixture SHA-256 : `46a20b365cb8ad90868e5d0320ffc2edae027f181d67a8f3a8b7aae612aec0b2`.

## Matrice de scénarios figée

| # | ID | Famille | Texte synthétique | Outcome attendu | Clarifications attendues | Confirmable | Safety |
|---:|---|---|---|---|---|---|---|
| 1 | `one_off_timed` | baseline_regression | Le 18 septembre 2026 de 16h à 17h, déposer un colis. | PROPOSAL | — | OUI | `normal_confirmable` |
| 2 | `weekly_timed` | baseline_regression | Tous les mardis de 07h15 à 08h, aller courir. Je m'en occupe moi-même. | PROPOSAL | — | OUI | `normal_confirmable` |
| 3 | `explicit_location` | baseline_regression | Le 22 septembre 2026 de 18h à 19h, entraînement au Centre Atlas. | PROPOSAL | — | OUI | `normal_confirmable` |
| 4 | `relative_near_midnight` | baseline_regression | Demain de 08h à 08h30, sortir les poubelles. | PROPOSAL | — | OUI | `normal_confirmable` |
| 5 | `unsupported_complex_recurrence` | baseline_regression | Le premier lundi de chaque mois de 09h à 10h, faire l'inventaire. | CLARIFICATION | `unsupported_recurrence` | NON | `safe_fail_closed_unsupported_recurrence` |
| 6 | `underspecified` | baseline_regression | Faire le truc bientôt. | CLARIFICATION | `date_unresolved`, `time_mode_required` | NON | `safe_clarification_underspecified` |
| 7 | `concerned_only` | person_role | Le 23 septembre 2026, toute la journée, activité pour Personne Alpha. | PROPOSAL | — | OUI | `role_confirmable` |
| 8 | `responsible_only` | person_role | Le 24 septembre 2026, toute la journée, préparer la salle. Personne Bêta s'en charge. | PROPOSAL | — | OUI | `role_confirmable` |
| 9 | `same_person_explicit_dual_role` | person_role | Le 25 septembre 2026, toute la journée, Personne Bêta participe à l'activité et s'en charge. | PROPOSAL | — | OUI | `role_confirmable` |
| 10 | `different_people_concerned_and_responsible` | person_role | Le 26 septembre 2026, toute la journée, activité pour Personne Alpha. Personne Bêta s'en charge. | PROPOSAL | — | OUI | `role_confirmable` |
| 11 | `unclassified_unique_person` | person_role | Le 27 septembre 2026, toute la journée, activité liée à Personne Bêta. | CLARIFICATION | `person_role_requires_selection` | NON | `safe_clarification_unclassified_role` |
| 12 | `unclassified_unknown_person` | person_role | Le 28 septembre 2026, toute la journée, activité liée à Personne Gamma. | CLARIFICATION | `person_not_found`, `person_role_requires_selection` | NON | `safe_clarification_unclassified_unknown_identity` |
| 13 | `unclassified_ambiguous_person` | person_role | Le 29 septembre 2026, toute la journée, activité liée à Personne Double. | CLARIFICATION | `person_ambiguous`, `person_role_requires_selection` | NON | `safe_clarification_unclassified_ambiguous_identity` |
| 14 | `concerned_person_alternative` | person_role | Vendredi de 18h à 19h, récupérer le dossier avec Personne Alpha ou Personne Bêta. | CLARIFICATION | `person_alternative_requires_selection` | NON | `safe_clarification_concerned_alternative` |
| 15 | `explicit_toute_la_journee` | all_day | Le 30 septembre 2026, toute la journée, formation. | PROPOSAL | — | OUI | `all_day_confirmable` |
| 16 | `explicit_journee_entiere` | all_day | Le 1 octobre 2026, journée entière de formation. | PROPOSAL | — | OUI | `all_day_confirmable` |
| 17 | `journee_administrative_ambiguous` | all_day | Le 2 octobre 2026, journée administrative. | CLARIFICATION | `time_mode_required` | NON | `safe_clarification_ambiguous_all_day` |
| 18 | `journee_de_formation_ambiguous` | all_day | Le 3 octobre 2026, journée de formation. | CLARIFICATION | `time_mode_required` | NON | `safe_clarification_ambiguous_all_day` |
| 19 | `timed_activity_containing_journee` | all_day | Le 4 octobre 2026, journée de formation de 09h à 12h. | PROPOSAL | — | OUI | `timed_confirmable` |
| 20 | `no_time_mode_evidence` | all_day | Le 5 octobre 2026, préparer les dossiers. | CLARIFICATION | `time_mode_required` | NON | `safe_clarification_missing_time_mode` |

## Matrice Person-role

| Cas | concerned | responsibility | unclassified | Résultat déterministe |
|---|---|---|---|---|
| `concerned_only` | Personne Alpha | — | — | PROPOSAL |
| `responsible_only` | — | Personne Bêta | — | PROPOSAL |
| `same_person_explicit_dual_role` | Personne Bêta | Personne Bêta | — | PROPOSAL |
| `different_people_concerned_and_responsible` | Personne Alpha | Personne Bêta | — | PROPOSAL |
| `unclassified_unique_person` | — | — | Personne Bêta | CLARIFICATION / person_role_requires_selection |
| `unclassified_unknown_person` | — | — | Personne Gamma | CLARIFICATION / person_not_found, person_role_requires_selection |
| `unclassified_ambiguous_person` | — | — | Personne Double | CLARIFICATION / person_ambiguous, person_role_requires_selection |
| `concerned_person_alternative` | Personne Alpha, Personne Bêta | — | — | CLARIFICATION / person_alternative_requires_selection |

## Matrice ALL_DAY

| Cas | ALL_DAY signal | TIMED signal | Mode final attendu | Clarification |
|---|---:|---:|---|---|
| `explicit_toute_la_journee` | true | false | ALL_DAY | — |
| `explicit_journee_entiere` | true | false | ALL_DAY | — |
| `journee_administrative_ambiguous` | false | false | — | `time_mode_required` |
| `journee_de_formation_ambiguous` | false | false | — | `time_mode_required` |
| `timed_activity_containing_journee` | false | true | TIMED | — |
| `no_time_mode_evidence` | false | false | — | `time_mode_required` |

## Safety et outcomes figés

- Cas de clarification attendus : **9**.
- Cas confirmables attendus : **11**.
- Cas explicitement classifiés safety/fail-closed : **9**.

- `unsupported_complex_recurrence` → `safe_fail_closed_unsupported_recurrence`; clarifications : `unsupported_recurrence`.
- `underspecified` → `safe_clarification_underspecified`; clarifications : `date_unresolved`, `time_mode_required`.
- `unclassified_unique_person` → `safe_clarification_unclassified_role`; clarifications : `person_role_requires_selection`.
- `unclassified_unknown_person` → `safe_clarification_unclassified_unknown_identity`; clarifications : `person_not_found`, `person_role_requires_selection`.
- `unclassified_ambiguous_person` → `safe_clarification_unclassified_ambiguous_identity`; clarifications : `person_ambiguous`, `person_role_requires_selection`.
- `concerned_person_alternative` → `safe_clarification_concerned_alternative`; clarifications : `person_alternative_requires_selection`.
- `journee_administrative_ambiguous` → `safe_clarification_ambiguous_all_day`; clarifications : `time_mode_required`.
- `journee_de_formation_ambiguous` → `safe_clarification_ambiguous_all_day`; clarifications : `time_mode_required`.
- `no_time_mode_evidence` → `safe_clarification_missing_time_mode`; clarifications : `time_mode_required`.

## Oracle déterministe

Le harness V3 alimente chaque `resolver_input` complet à 16 champs via `ActivityCaptureExtraction::fromProviderArray()`, puis exécute le vrai `ActivityCaptureResolver` dans un état Kernel synthétique isolé.

Le même harness vérifie également que les 320 champs exacts de `resolver_input` satisfont le scorer et les matchers `ai_expected` figés.

```text
V3_ORACLE = PASS / 20 of 20
V3_ORACLE_PASS_COUNT = 20
V3_EXPECTED_AI_FIELDS_SELF_CONSISTENT = 320 / 320
CANONICAL_DEV_DOMAIN_MUTATION = NONE
```

Le topology synthétique autorisé contient uniquement des labels artificiels : `Current Person`, `Personne Alpha`, `Personne Bêta` et deux entrées `Personne Double` pour l’identité ambiguë. Aucun renseignement personnel réel n’est utilisé.

## Gates sémantiques figés

```text
SCHEMA_VALID = 100%
AI_EXTRACTION_ACCURACY >= 95%
INTERNAL_ID_INVENTION_CASES = 0
FINAL_PRODUCT_OUTCOME_CORRECT = 100%
UNSAFE_CONFIRMABLE_WRONG_PROPOSALS = 0
AVOIDABLE_FALLBACK_CASES = 0
```

Ces gates ne peuvent pas être abaissés après observation des résultats provider.

## Gate de latence figé

```text
P95 <= 10 seconds
TIMEOUTS = 0
```

La qualification sémantique et la qualification de latence restent indépendantes.

## Matrice provider planifiée — non exécutée

### Arm A — local

```text
MODEL = Ministral 3 3B Instruct 2512
SOURCE = lmstudio-community/Ministral-3-3B-Instruct-2512-GGUF
QUANTIZATION = Q4_K_M
MODEL_ID = ps-ministral-3-3b
GGUF_SHA256 = ee46f8f2cc4acf15e89699563e23b4a3919dce2e9ce7c44b53778d6590318e96
RUNTIME = LOCAL_ONLY
```

Une exécution future serait une **nouvelle tranche V3**, jamais un replay V2.

### Arm B — OpenAI Sol

```text
MODEL = gpt-5.6-sol
API = Responses API
REASONING_EFFORT = none
TOOLS = none
WEB = none
FILE_SEARCH = none
MCP = none
STORE = false
DATA = synthetic V3 fixture only
```

### Arm C — OpenAI Terra conditionnel

Terra ne devient éligible que si Sol passe les gates V3 sémantiques/safety **et** surpasse matériellement Ministral V3 sur l’outcome produit. Aucun autre modèle local, Luna ou Astra n’est inclus.

## Fair-comparison invariant

Avant toute Phase B, restent figés : fixture SHA, 20 cas, 320 attentes IA, outcomes resolver, clarifications, confirmabilité, safety, scorer, gates sémantiques et gate de latence.

Différences provider autorisées : transport, syntaxe API, syntaxe Structured Outputs et livraison du secret. Les hints sémantiques provider/case, les attentes divergentes, un resolver différent ou un scoring différent sont interdits.

Politique : exactement **une** tranche sémantique primaire par provider autorisé, sans retry sémantique ni deuxième tranche pour améliorer le score.

## Artifacts Phase A

- `web/modules/custom/personal_secretary/tests/fixtures/activity_capture_benchmark_v3.json`
- `web/modules/custom/personal_secretary/tests/src/Kernel/ActivityCaptureBenchmarkV3KernelTest.php`
- `scripts/activity-capture-benchmark-v3.php`
- `docs/activity-capture-benchmark-v3.md`
- `docs/roadmap.yaml`

Aucun adapter OpenAI V3 et aucun workflow consommant un secret ne sont publiés en Phase A. Ils peuvent être ajoutés uniquement sous autorité Phase B si nécessaires.

## État Phase A

```text
PROVIDER_RESULTS = NOT_EXECUTED
V1_CHANGED = NO
V2_CHANGED = NO
V2_RERUN = NO
V2_RESULT_INVALIDATED = NO
MODEL_INFERENCE = NONE
MINISTRAL_V3_REQUESTS = 0
OPENAI_SOL_V3_REQUESTS = 0
OPENAI_TERRA_V3_REQUESTS = 0
PRODUCT_RUNTIME_CHANGE = NONE
COMPOSER_CHANGE = NONE
DRUPAL_CONFIG_CHANGE = NONE
DATABASE_SCHEMA_CHANGE = NONE
```

La Phase B nécessite une nouvelle décision explicite du Project Lead.
