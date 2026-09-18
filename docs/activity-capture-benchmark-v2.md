# Activity Capture BENCHMARK_V2

Date d'exécution : 2026-09-18

## Objet

Ce benchmark qualifie la frontière matérialisée par ADR 0018 / #159 / #160 :

```text
natural-language input
→ narrow non-authoritative AI extraction
→ deterministic ActivityCaptureResolver
→ review proposal OR clarification
```

Le benchmark V2 est distinct du benchmark V1 historique. Les fixtures, runners et rapports V1 n'ont pas été modifiés ni rejoués.

## Contrat figé

- Fixture : `web/modules/custom/personal_secretary/tests/fixtures/activity_capture_benchmark_v2.json`
- SHA-256 : `837deef48cfa0d2d33628e3b78d9692ef70cd68c48d1032b9cae290d7838d59f`
- 11 scénarios synthétiques.
- 15 champs possédés par l'IA par scénario, soit 165 assertions.
- Même fixture, mêmes contextes, mêmes fuseaux, mêmes attentes, même scoring et même `ActivityCaptureResolver` pour tous les providers.
- Données personnelles réelles envoyées aux providers : aucune.
- Mutation du domaine DEV canonique : aucune.

## Architecture B — oracle déterministe

L'oracle alimente le vrai `ActivityCaptureResolver` avec les extractions V2 idéales pré-déclarées.

```text
ORACLE_RESOLVER_CORRECT = 11/11
ARCHITECTURE_B_PRODUCT_SEMANTICS = PASS
ACTIVITY_MUTATION = NONE
```

Ce résultat isole le resolver de l'évaluation provider : les erreurs provider ci-dessous ne sont pas attribuées au resolver lorsqu'un oracle produit le bon outcome.

## Arm A — Ministral 3 3B LOCAL

Artefact historique exact :

```text
MODEL_ID = ps-ministral-3-3b
MODEL_SOURCE = lmstudio-community/Ministral-3-3B-Instruct-2512-GGUF
QUANTIZATION = Q4_K_M
GGUF_SHA256 = ee46f8f2cc4acf15e89699563e23b4a3919dce2e9ce7c44b53778d6590318e96
RESULT_SHA256 = 008bfab0faa84bf3587157b985f40cb58311ef0292f96069f2054f3e21910b83
MINISTRAL_REPLAY = NO
```

### Synthèse Ministral

| Mesure | Résultat |
| --- | ---: |
| Schema-valid | 11/11 |
| Champs IA corrects | 147/165 |
| Précision extraction | 89,0909 % |
| IDs internes inventés | 0 |
| Outcomes produit corrects | 1/11 |
| Clarifications attendues / réelles | 10 / 11 |
| Fallbacks évitables | 1 |
| Propositions erronées confirmables | 0 |
| P50 | 36,271 s |
| P95 | 82,102 s |
| Timeouts | 0 |
| Coût API | $0 |
| Verdict sémantique | **FAIL** |
| Latence synchrone | **FAIL** |

### Matrice Ministral

Tous les champs IA non listés dans « champs IA en échec » sont PASS pour le scénario.

| Cas | Schema | Précision IA | Champs IA en échec | Outcome réel | Outcome final correct | Clarifications | E2E |
| --- | --- | ---: | --- | --- | --- | --- | ---: |
| one_off_timed | PASS | 93,33 % | `label_text` | CLARIFICATION | NON | `label_required`, `end_time_required` | 82,102 s |
| weekly_timed | PASS | 80,00 % | extraction partielle | CLARIFICATION | NON | `label_required`, `end_time_required` | 34,550 s |
| all_day_one_off | PASS | 93,33 % | `label_text` | CLARIFICATION | NON | `label_required` | 33,104 s |
| explicit_location | PASS | 100 % | — | CLARIFICATION | OUI | `end_time_required` | 36,271 s |
| concerned_person_text | PASS | 93,33 % | `label_text` | CLARIFICATION | NON | `label_required`, `end_time_required` | 37,117 s |
| self_responsibility | PASS | 93,33 % | `label_text` | CLARIFICATION | NON | `label_required`, `end_time_required` | 39,470 s |
| text_responsibility | PASS | 66,67 % | extraction multiple | CLARIFICATION | NON | `label_required`, `date_unresolved`, `end_time_required` | 40,989 s |
| relative_near_midnight | PASS | 93,33 % | `label_text` | CLARIFICATION | NON | `label_required`, `end_time_required` | 35,056 s |
| ambiguous_person | PASS | 93,33 % | `label_text` | CLARIFICATION | NON | `label_required`, `person_alternative_requires_selection` | 36,625 s |
| unsupported_complex_recurrence | PASS | 93,33 % | `label_text` | CLARIFICATION | NON | `label_required`, `unsupported_recurrence` | 33,511 s |
| underspecified | PASS | 80,00 % | extraction multiple | CLARIFICATION | NON | `label_required`, `date_required`, `time_mode_required`, `person_not_found` | 32,495 s |

## Arm B — OpenAI GPT-5.6 Sol

Configuration :

```text
MODEL = gpt-5.6-sol
API = Responses API
REASONING_EFFORT = none
TOOLS = none
WEB = none
STORE = false
INPUT = synthetic V2 fixture only
REQUESTS = 11
SEMANTIC_RETRY = no
```

GitHub Actions a exécuté la tranche sur le head benchmark intermédiaire après validation de la fixture et de l'oracle. Les deux premières tentatives du même run se sont arrêtées avant l'adapter parce que le secret d'environnement était absent ; elles ont envoyé zéro requête OpenAI. La tentative 3 est la seule tranche sémantique Sol consommée.

Artefact Actions :

```text
WORKFLOW_RUN = 35379476645
RUN_ATTEMPT = 3
ARTIFACT_ID = 10564171695
ARTIFACT_NAME = activity-capture-v2-openai-sol
ARTIFACT_ZIP_SHA256 = abffe9b7eba60f365f9491762597ef0d71e129f3c3abaa96616b330cae9e1e8a
RESULT_JSON_SHA256 = 8548434b1a47ea35abfc71ca531f0e245224e247f16e15a9df2d61461899d3f1
```

### Synthèse Sol

| Mesure | Résultat |
| --- | ---: |
| Schema-valid | 11/11 |
| Champs IA corrects | 162/165 |
| Précision extraction | 98,1818 % |
| IDs internes inventés | 0 |
| Outcomes produit corrects | 9/11 |
| Clarifications attendues / réelles | 10 / 11 |
| Fallbacks évitables | 1 |
| Propositions erronées confirmables | 0 |
| P50 | 3,254 s |
| P95 | 5,165 s |
| Timeouts | 0 |
| Input tokens | 7 316 |
| Cached input tokens | 0 |
| Output tokens | 1 247 |
| Verdict sémantique | **FAIL** |
| Latence synchrone | **PASS** |

### Coût Sol

Tarification modèle officielle consultée le 2026-09-18 : GPT-5.6 Sol = $4 / 1M tokens input, $0,40 / 1M cached input, $20 / 1M output. Source : `https://developers.openai.com/api/docs/models/gpt-5.6-sol`.

```text
INPUT_COST  = 7316 / 1,000,000 × $4.00  = $0.029264
CACHED_COST = 0    / 1,000,000 × $0.40  = $0
OUTPUT_COST = 1247 / 1,000,000 × $20.00 = $0.024940
TOTAL_COST  = $0.054204
AVG_CAPTURE = $0.004928
```

### Matrice Sol

Tous les champs IA non listés dans « champs IA en échec » sont PASS pour le scénario.

| Cas | Schema | Précision IA | Champs IA en échec | Outcome attendu → réel | Final correct | Clarifications | Latence E2E | Tokens in/cached/out | Response ID |
| --- | --- | ---: | --- | --- | --- | --- | ---: | ---: | --- |
| one_off_timed | PASS | 100 % | — | CLARIFICATION → CLARIFICATION | OUI | `end_time_required` | 5,165 s | 664/0/112 | `resp_0a5d7afcf911e33f016aad91696f1887d0a60fac4a3850998a` |
| weekly_timed | PASS | 100 % | — | CLARIFICATION → CLARIFICATION | OUI | `end_time_required`, `responsibility_required_for_weekly` | 2,352 s | 662/0/110 | `resp_0d45100d4e90865f016aad916d94c087d0afc9f93df44475b2` |
| all_day_one_off | PASS | 93,33 % | `explicit_all_day_signal` | PROPOSAL → CLARIFICATION | **NON** | `time_mode_required` | 3,508 s | 659/0/110 | `resp_0ca7e5a20943818c016aad916feddc87d086ec7bf38d2b7e1d` |
| explicit_location | PASS | 100 % | — | CLARIFICATION → CLARIFICATION | OUI | `end_time_required` | 3,392 s | 666/0/111 | `resp_0aaaf4b5332348ce016aad917374f887d0a71f70ed701433df` |
| concerned_person_text | PASS | 100 % | — | CLARIFICATION → CLARIFICATION | OUI | `end_time_required` | 3,254 s | 668/0/118 | `resp_0cb07e9f8347f0a4016aad9176df7487d09ca099f64c6cb39b` |
| self_responsibility | PASS | 100 % | — | CLARIFICATION → CLARIFICATION | OUI | `end_time_required` | 3,599 s | 672/0/112 | `resp_0c6de1d225dfb6d8016aad917a169887d0b267f066995d8794` |
| text_responsibility | PASS | 93,33 % | `concerned_person_mentions` | CLARIFICATION → CLARIFICATION | **NON** | `end_time_required` | 2,508 s | 674/0/123 | `resp_067907eb0bb3861e016aad917da44487d0b8253da390bd743d` |
| relative_near_midnight | PASS | 100 % | — | CLARIFICATION → CLARIFICATION | OUI | `end_time_required` | 4,463 s | 661/0/108 | `resp_0cdd7c67f3a422b9016aad918034ac87d082070ede5c146043` |
| ambiguous_person | PASS | 100 % | — | CLARIFICATION → CLARIFICATION | OUI | `end_time_required`, `person_alternative_requires_selection` | 2,235 s | 669/0/118 | `resp_0f68fccc267e7f95016aad9184a54887d088eb20f74643021c` |
| unsupported_complex_recurrence | PASS | 100 % | — | CLARIFICATION → CLARIFICATION | OUI | `unsupported_recurrence`, `end_time_required` | 2,761 s | 665/0/116 | `resp_0a67767c39350e39016aad9186dc3487d0bc8f0885384f7af9` |
| underspecified | PASS | 93,33 % | `concerned_person_mentions` | CLARIFICATION → CLARIFICATION | OUI | `date_unresolved`, `time_mode_required`, `person_not_found` | 2,784 s | 656/0/109 | `resp_0c4daea8d5401205016aad9189b7cc87d0b2660814290da68a` |

Les trois cas obligatoires de sûreté passent :

- `ambiguous_person` → clarification sûre ;
- `unsupported_complex_recurrence` → clarification fail-closed ;
- `underspecified` → clarification sûre.

Les deux écarts qui empêchent la qualification sémantique sont :

1. `all_day_one_off` : `explicit_all_day_signal=false` au lieu de `true`, ce qui transforme la proposition attendue en clarification `time_mode_required` et crée le seul fallback évitable.
2. `text_responsibility` : ajout de `Personne Bêta` dans `concerned_person_mentions` alors que le texte l'exprime comme responsable, pas comme personne concernée.

Le score d'extraction dépasse bien le seuil de 90 %, la latence passe le gate synchrone et aucun outcome erroné n'est confirmable. Néanmoins, le contrat exige 11/11 outcomes produit corrects et zéro fallback évitable : Sol est donc **FAIL sémantique** sous le contrat V2 figé.

## Arm C — OpenAI GPT-5.6 Terra

Terra était autorisé uniquement si Sol passait tous les gates sémantiques et de sûreté. Sol ayant obtenu 9/11 outcomes produit corrects et un fallback évitable :

```text
OPENAI_TERRA_ARM_EXECUTED = NO
REASON = SOL_SEMANTIC_VERDICT_FAIL
```

Aucune requête Terra n'a été envoyée et aucun coût Terra n'a été engagé.

## Comparaison providers

| Provider | Extraction IA | Outcome produit | P50 | P95 | Semantic | Sync latency | Coût benchmark |
| --- | ---: | ---: | ---: | ---: | --- | --- | ---: |
| Ministral 3 3B LOCAL | 89,0909 % | 1/11 | 36,271 s | 82,102 s | FAIL | FAIL | $0 |
| GPT-5.6 Sol | 98,1818 % | 9/11 | 3,254 s | 5,165 s | FAIL | PASS | $0,054204 |
| GPT-5.6 Terra | non exécuté | non exécuté | — | — | — | — | $0 |

Sol améliore matériellement le résultat produit par rapport à Ministral (9/11 contre 1/11) et réduit fortement la latence, mais **ne satisfait pas le gate de qualification produit V2**. Decision #166 n'autorise donc pas Terra et #164 n'autorise aucune adoption OpenAI réelle.

## Conclusion #164

```text
ARCHITECTURE_B_ORACLE = PASS
MINISTRAL_V2_SEMANTICS = FAIL
MINISTRAL_SYNCHRONOUS_LATENCY = FAIL
OPENAI_SOL_V2_SEMANTICS = FAIL
OPENAI_SOL_SYNCHRONOUS_LATENCY = PASS
SOL_MATERIALLY_OUTPERFORMS_MINISTRAL_ON_PRODUCT_OUTCOME = YES
OPENAI_TERRA_ARM_EXECUTED = NO
REAL_PERSONAL_DATA_TO_OPENAI = NONE
PRODUCT_OPENAI_ADOPTION = NOT_AUTHORIZED
```

Le résultat principal est architectural : la séparation extraction étroite / resolver déterministe est confirmée par l'oracle 11/11. Sol constitue une référence sensiblement supérieure à Ministral mais échoue encore deux scénarios du contrat figé. Toute évolution de prompt, de scoring, du produit ou toute nouvelle tranche provider nécessite une nouvelle autorité ; aucun rerun n'est utilisé pour améliorer ces scores.
