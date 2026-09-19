# Activity Capture BENCHMARK_V3 — rapport comparatif final

Date de gel du contrat : 2026-09-18
Date de finalisation comparative : 2026-09-19

## Objet

BENCHMARK_V3 évalue le contrat provider courant à 16 champs matérialisé par #168 avec une fixture synthétique figée, un oracle déterministe réel et trois tranches provider gouvernées.
Le présent document conserve le contrat gelé de Phase A et matérialise les preuves finales immuables de Ministral 3 3B, GPT-5.6 Sol et GPT-5.6 Terra.

```text
PROVIDER_MATRIX = COMPLETE
MINISTRAL_V3_REQUESTS = 20 / IMMUTABLE
OPENAI_SOL_V3_REQUESTS = 20 / IMMUTABLE
OPENAI_TERRA_V3_REQUESTS = 20 / IMMUTABLE
NEW_PROVIDER_REQUESTS = 0
MINISTRAL_REPLAY = NO
SOL_REPLAY = NO
TERRA_REPLAY = NO
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
Fixture SHA-256 : `bb31e0ba98db688adcdfd530275bf3aff47013c79d4c6bb2c4896b1477cc8cbd`.

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
| 11 | `unclassified_unique_person` | person_role | Le 27 septembre 2026, toute la journée, activité. À noter : Personne Bêta. | CLARIFICATION | `person_role_requires_selection` | NON | `safe_clarification_unclassified_role` |
| 12 | `unclassified_unknown_person` | person_role | Le 28 septembre 2026, toute la journée, activité. À noter : Personne Gamma. | CLARIFICATION | `person_not_found`, `person_role_requires_selection` | NON | `safe_clarification_unclassified_unknown_identity` |
| 13 | `unclassified_ambiguous_person` | person_role | Le 29 septembre 2026, toute la journée, activité. À noter : Personne Double. | CLARIFICATION | `person_ambiguous`, `person_role_requires_selection` | NON | `safe_clarification_unclassified_ambiguous_identity` |
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

## Résultats provider immuables

### Comparaison synthétique

| Provider | Schema | Champs IA | Accuracy | Outcome produit | Clarifications | Fallback évitable | Unsafe confirmable wrong | Internal-ID invention | Mean provider | P95 provider | Timeouts | Sémantique | Latence sync |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---|---|
| Ministral 3 3B | 18/20 | 263/320 | 82.1875% | 2/20 | 9 attendues / 16 réelles | 9 | 0 | 0 | 46.150814s | 120.170959s | 2 | FAIL | FAIL |
| GPT-5.6 Sol | 20/20 | 320/320 | 100% | 20/20 | 9 / 9 | 0 | 0 | 0 | 2.407608s | 3.350804s | 0 | PASS | PASS |
| GPT-5.6 Terra | 20/20 | 320/320 | 100% | 20/20 | 9 / 9 | 0 | 0 | 0 | 1.898228s | 2.702923s | 0 | PASS | PASS |

### Arm A — Ministral 3 3B local

```text
MODEL = Ministral 3 3B Instruct 2512
SOURCE = lmstudio-community/Ministral-3-3B-Instruct-2512-GGUF
QUANTIZATION = Q4_K_M
MODEL_ID = ps-ministral-3-3b
GGUF_SHA256 = ee46f8f2cc4acf15e89699563e23b4a3919dce2e9ce7c44b53778d6590318e96
RUNTIME = LOCAL_ONLY
REQUESTS = 20 / IMMUTABLE
RESULT_SHA256 = 837aa1cf5fe184a096664d9f2b1300f20061d6bc6f679d27de4af57587bfe01d

SCHEMA_VALID = 18 / 20
AI_FIELDS_CORRECT = 263 / 320
AI_EXTRACTION_ACCURACY = 82.1875%
FINAL_PRODUCT_OUTCOME_CORRECT = 2 / 20
EXPECTED_CLARIFICATIONS = 9
ACTUAL_CLARIFICATIONS = 16
AVOIDABLE_FALLBACK = 9
UNSAFE_CONFIRMABLE_WRONG = 0
INTERNAL_ID_INVENTION = 0

PROVIDER_LATENCY_MIN = 30.151091s
PROVIDER_LATENCY_MEAN = 46.150814s
PROVIDER_LATENCY_P50 = 36.816768s
PROVIDER_LATENCY_P95 = 120.170959s
PROVIDER_LATENCY_MAX = 120.186968s
E2E_P50 = 36.848715s
E2E_P95 = 120.170959s
TIMEOUTS = 2
TIMEOUT_CASES = one_off_timed, no_time_mode_evidence

SEMANTIC_VERDICT = FAIL
SYNCHRONOUS_LATENCY = FAIL
```

Ministral 3 3B n’atteint ni les gates sémantiques ni le gate de latence synchrone V3. Aucune proposition confirmable dangereusement erronée ni invention d’identifiant interne n’a toutefois été observée.

### Arm B — GPT-5.6 Sol

```text
MODEL = gpt-5.6-sol
API = Responses API
REASONING_EFFORT = none
TOOLS / WEB / FILE_SEARCH / MCP = none
STORE = false
DATA = synthetic V3 fixture only
REQUESTS = 20 / IMMUTABLE

WORKFLOW_RUN = 35431363192
JOB = 105866454023
RUN_ATTEMPT = 1
ARTIFACT = activity-capture-v3-openai-sol
ARTIFACT_ID = 10580119315
ARTIFACT_ZIP_SHA256 = 2ef47a358f55a333f84c00cca70d58e08aad5edfea843f4198c4cc1bea931c21
RESULT_SHA256 = 3a8327777904d325a1c5046925976bef6cc1d5dc5b4a53e1ff4b7f0cebf0dc03

SCHEMA_VALID = 20 / 20
AI_FIELDS_CORRECT = 320 / 320
AI_EXTRACTION_ACCURACY = 100%
FINAL_PRODUCT_OUTCOME_CORRECT = 20 / 20
EXPECTED_CLARIFICATIONS = 9
ACTUAL_CLARIFICATIONS = 9
AVOIDABLE_FALLBACK = 0
UNSAFE_CONFIRMABLE_WRONG = 0
INTERNAL_ID_INVENTION = 0

PROVIDER_LATENCY_MIN = 1.810305s
PROVIDER_LATENCY_MEAN = 2.407608s
PROVIDER_LATENCY_P50 = 2.306825s
PROVIDER_LATENCY_P95 = 3.350804s
PROVIDER_LATENCY_MAX = 3.497379s
E2E_P95 = 3.352929s
TIMEOUTS = 0

INPUT_TOKENS = 17300
CACHED_INPUT_TOKENS = 0
OUTPUT_TOKENS = 2364

SEMANTIC_VERDICT = PASS
SYNCHRONOUS_LATENCY = PASS
```

Sol satisfait tous les gates V3 sémantiques, safety et de latence synchrone et constitue la référence qualité cloud de cette matrice.

### Arm C — GPT-5.6 Terra

```text
MODEL = gpt-5.6-terra
API = Responses API
REASONING_EFFORT = none
TOOLS / WEB / FILE_SEARCH / MCP = none
STORE = false
DATA = synthetic V3 fixture only
REQUESTS = 20 / IMMUTABLE

WORKFLOW_RUN = 35432609752
ACCEPTED_RUN_ATTEMPT = 2
JOB = 105869997745
ARTIFACT = activity-capture-v3-openai-terra
ARTIFACT_ID = 10581510406
ARTIFACT_ZIP_SHA256 = 0f38f46b7018b49084e352f17f01fc75d91cd453ce3674d5d1c93cf63fc3aced
RAW_RESULT_SHA256 = 62c8668b3d9d8ecf73a0b981e6adc71eac59769e2cfce6d832f9bd8d8ed6c3b2
SCORED_RESULT_SHA256 = 52c85980c3eeedd946e33ff3f0586ff0275ec14a9e2f2e31b4cf754b4c7bb914

SCHEMA_VALID = 20 / 20
AI_FIELDS_CORRECT = 320 / 320
AI_EXTRACTION_ACCURACY = 100%
FINAL_PRODUCT_OUTCOME_CORRECT = 20 / 20
EXPECTED_CLARIFICATIONS = 9
ACTUAL_CLARIFICATIONS = 9
AVOIDABLE_FALLBACK = 0
UNSAFE_CONFIRMABLE_WRONG = 0
INTERNAL_ID_INVENTION = 0

PROVIDER_LATENCY_MIN = 1.648116s
PROVIDER_LATENCY_MEAN = 1.898228s
PROVIDER_LATENCY_P50 = 1.783020s
PROVIDER_LATENCY_P95 = 2.702923s
PROVIDER_LATENCY_MAX = 2.729496s
E2E_LATENCY_MEAN = 1.900620s
E2E_LATENCY_P50 = 1.785370s
E2E_LATENCY_P95 = 2.705416s
E2E_LATENCY_MAX = 2.731971s
TIMEOUTS = 0

INPUT_TOKENS = 17300
CACHED_INPUT_TOKENS = 0
OUTPUT_TOKENS = 2363

SEMANTIC_VERDICT = PASS
SYNCHRONOUS_LATENCY = PASS
```

L’essai 1 du run Terra a échoué avant toute inférence lors de `composer audit` à cause d’un échec de téléchargement Packagist. Le rerun pré-inférence explicitement autorisé a constitué l’unique tranche sémantique Terra : il ne s’agit pas d’un retry sémantique.

Terra satisfait exactement les mêmes gates sémantiques et safety figés que Sol. Les différences textuelles acceptées entre sorties n’ont produit aucune différence sur les matchers figés ni sur les outcomes déterministes.

## Fair-comparison invariant

Pendant toute la matrice V3, sont restés figés : fixture SHA, 20 cas, 320 attentes IA, outcomes resolver, clarifications, confirmabilité, safety, scorer, gates sémantiques et gate de latence.

Différences provider autorisées : transport, syntaxe API, syntaxe Structured Outputs et livraison du secret. Les hints sémantiques provider/case, les attentes divergentes, un resolver différent ou un scoring différent sont interdits.

Politique : exactement **une** tranche sémantique primaire par provider autorisé, sans retry sémantique ni deuxième tranche pour améliorer le score.

## Snapshot tarifaire OpenAI — 2026-09-19

Tarification Standard, texte, contexte court, vérifiée sur les références officielles OpenAI au 2026-09-19 :

| Modèle | Input / 1M | Cached input / 1M | Output / 1M |
|---|---:|---:|---:|
| GPT-5.6 Sol | $4.00 | $0.40 | $20.00 |
| GPT-5.6 Terra | $2.00 | $0.20 | $12.00 |

Références :
- https://developers.openai.com/api/docs/models/gpt-5.6-sol
- https://developers.openai.com/api/docs/models/gpt-5.6-terra
- https://developers.openai.com/api/docs/pricing

Les captures V3 utilisent moins de 272K tokens d’entrée par requête ; aucun multiplicateur long-contexte n’est appliqué. Aucun uplift de traitement régional n’est ajouté, car l’évidence du benchmark ne prouve pas l’utilisation d’un mode endpoint régional tarifé.

### Estimation de coût à partir de l’usage enregistré

```text
SOL_V3_ESTIMATED_COST_USD =
(17300 × $4.00 + 0 × $0.40 + 2364 × $20.00) / 1,000,000
= $0.116480

SOL_AVERAGE_COST_PER_CAPTURE_USD =
$0.116480 / 20
= $0.005824

TERRA_V3_ESTIMATED_COST_USD =
(17300 × $2.00 + 0 × $0.20 + 2363 × $12.00) / 1,000,000
= $0.062956

TERRA_AVERAGE_COST_PER_CAPTURE_USD =
$0.062956 / 20
= $0.0031478

TERRA_COST_REDUCTION_VS_SOL =
45.951236...%
≈ 45.95%
```

Ces montants sont des estimations de benchmark calculées depuis les tokens enregistrés et les tarifs publics du jour ; ce ne sont pas des reçus de facturation.

## Interprétation comparative

```text
MINISTRAL_3_3B = NOT QUALIFIED FOR CURRENT V3 SYNCHRONOUS DEFAULT GATE
SOL = QUALIFIED ON V3 / QUALITY REFERENCE
TERRA = QUALIFIED ON V3 / COST-PERFORMANCE CANDIDATE
SOL_VS_TERRA_SEMANTIC_DIFFERENCE = NONE ON FROZEN V3 GATES
```

Sur cette fixture V3 synthétique, Terra égale Sol sur chaque gate sémantique et safety figé : 20/20 réponses schema-valid, 320/320 champs acceptés, 20/20 outcomes produit, 9/9 clarifications attendues, zéro fallback évitable, zéro proposition confirmable incorrecte et zéro invention d’identifiant interne.

Terra a également montré une latence observée inférieure : mean provider 1.898228s contre 2.407608s pour Sol, et P95 provider 2.702923s contre 3.350804s. Son coût API estimé est inférieur d’environ 45.95% sur les usages enregistrés.

Ces constats qualifient Terra comme candidat coût/performance V3 et Sol comme référence qualité V3. Ils ne constituent pas une décision d’adoption production.

## Limites et frontière produit

- BENCHMARK_V3 contient seulement **20 cas synthétiques** et ne prétend pas représenter toute la distribution des entrées réelles.
- Les résultats mesurent le contrat figé actuel ; un changement de prompt, schema, scorer, resolver, modèle ou pricing nécessite une nouvelle évaluation gouvernée.
- Aucun texte réel Personal Secretary, aucune donnée familiale, enfant, personnelle ou PROD n’a été envoyé à OpenAI par cette finalisation.
- **BENCHMARK_V3 n’autorise pas l’envoi de données réelles Personal Secretary à OpenAI.**
- L’adoption éventuelle d’un provider OpenAI relève exclusivement de la Decision **#177** et de ses gates propres de rétention, data boundary, sécurité, coût et intégration.
- `SILENT_CLOUD_FALLBACK = FORBIDDEN` reste inchangé.
- `MANUAL_STRUCTURED_CAPTURE = PRESERVED` reste inchangé.
- `EXPLICIT_CONFIRMATION = REQUIRED` et `FRESH_SERVER_AUTHORIZATION = REQUIRED` restent inchangés.

## Évidence et surfaces finales

Les surfaces de benchmark V3 conservées sont :

- `web/modules/custom/personal_secretary/tests/fixtures/activity_capture_benchmark_v3.json`
- `web/modules/custom/personal_secretary/tests/src/Kernel/ActivityCaptureBenchmarkV3KernelTest.php`
- `web/modules/custom/personal_secretary/tests/src/Kernel/ActivityCaptureBenchmarkV3ProviderKernelTest.php`
- `scripts/activity-capture-benchmark-v3.php`
- `scripts/activity-capture-provider-v3.php`
- `scripts/activity-capture-openai-v3.py`
- `docs/activity-capture-benchmark-v3.md`
- `docs/roadmap.yaml`

Le workflow feature-branch multi-arm historique `.github/workflows/activity-capture-benchmark-v3-openai.yml` est retiré de la livraison finale. Les deux bootstraps temporaires mono-provider déjà présents sur `main` restent inchangés dans #170 et seront traités séparément si un cleanup ultérieur est autorisé.

```text
V3_FIXTURE_CHANGED = NO
V3_EXPECTATIONS_CHANGED = NO
V3_GATES_CHANGED = NO
V3_PROMPT_CHANGED = NO
V3_SCHEMA_CHANGED = NO
V3_SCORER_SEMANTICS_CHANGED = NO
V1_CHANGED = NO
V2_CHANGED = NO

MINISTRAL_REQUESTS = 20 / IMMUTABLE
SOL_REQUESTS = 20 / IMMUTABLE
TERRA_REQUESTS = 20 / IMMUTABLE
NEW_PROVIDER_REQUESTS = 0

PRODUCT_PROVIDER_ADOPTION = NONE
OPENAI_REAL_PERSONAL_DATA = NOT AUTHORIZED
PRODUCT_RUNTIME_CHANGE = NONE
```
