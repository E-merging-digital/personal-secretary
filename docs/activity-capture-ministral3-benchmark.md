# LOCAL_ONLY activity-capture benchmark — Ministral 3 3B Instruct 2512

This is the bounded synthetic evidence for issue #155. It contains no real personal/family input, model credential, production AI configuration, image input, or cloud inference.

## Exact stack

- Drupal core: `11.4.7`
- Drupal AI: `1.4.8`
- LM Studio Provider: `1.1.0`
- Model source: `lmstudio-community/Ministral-3-3B-Instruct-2512-GGUF`
- Model: Ministral 3 3B Instruct 2512 / `Q4_K_M`, local CPU
- Model identifier used by the synthetic runtime: `ps-ministral-3-3b`
- Q4_K_M GGUF SHA-256: `ee46f8f2cc4acf15e89699563e23b4a3919dce2e9ce7c44b53778d6590318e96`
- LM Studio vision-adapter companion SHA-256: `f5eed2b41fbeb82f4b9c620d4f01cdaf7a2ab9ddfb360cf3e22b8733122c18a3`
- Context: `4096`; parallel slots: `1`; GPU offload: `off`
- Maintained Drupal AI request timeout: `120` seconds
- Primary attempts per case: `1`; maximum permitted: `2` only for deterministic syntax/schema repair
- Frozen runner SHA-256: `88f7b469366e7cb65390bdc6ef199229b9ba99b269409ba7ef7bbf7bb8a67bc9`
- Frozen 11-case fixture SHA-256: `f4b60ad2ae32e4de357924e4d729cf2a49ce1ef21c8b7d52846f8fd4ee3d6fb1`
- No repair attempt was needed: all 11 primary responses parsed against the existing proposal schema
- Cloud fallback, public/LAN-wide API, tools, MCP, domain mutation: none
- LM Studio indexed the GGUF with its upstream vision-adapter companion; the benchmark remained strictly text-only and did not use image/vision input

## Local runtime finding

- Model load: `15.879s` measured end-to-end (`15.30s` reported by LM Studio)
- Host memory used before/after load: approximately `1.86 GB → 4.80 GB` (`+2.94 GB`)
- Host memory available after load: approximately `11.91 GB`
- The existing #151 local `libgomp` compatibility path was reused; no package, model runtime, application topology, or dependency was upgraded

The pre-benchmark structured smoke reached Drupal AI → LM Studio → JSON Schema → `ActivityCaptureProposal`, invented no internal ID and mutated no domain entity. It was nevertheless semantically wrong: explicit `16:00` became `14:00`, and unasserted responsibility/label content was invented. The benchmark therefore proceeded unchanged, with no prompt or fixture relaxation.

## 11-case result

| Case | Schema | Asserted accuracy | Latency | Manual fallback | Failed asserted fields |
| --- | --- | ---: | ---: | --- | --- |
| `one_off_timed` | PASS | 85.7% | 29.375s | YES | local_time |
| `weekly_timed` | PASS | 66.7% | 30.100s | YES | intent, weekday |
| `all_day_one_off` | PASS | 100.0% | 28.814s | NO | — |
| `explicit_location` | PASS | 100.0% | 31.072s | NO | — |
| `concerned_person_text` | PASS | 100.0% | 33.449s | NO | — |
| `self_responsibility` | PASS | 87.5% | 33.622s | YES | local_time |
| `text_responsibility` | PASS | 75.0% | 29.905s | YES | responsibility, responsibility_text |
| `relative_near_midnight` | PASS | 87.5% | 29.754s | YES | relative_date_expression |
| `ambiguous_person` | PASS | 75.0% | 38.096s | YES | ambiguous |
| `unsupported_complex_recurrence` | PASS | 66.7% | 43.974s | YES | unsupported |
| `underspecified` | PASS | 0.0% | 38.855s | YES | ambiguous |

Summary:

- schema-valid: `11/11` (100.0%)
- asserted-field accuracy: `84.6%` (`44/52`)
- ambiguity detection: `0/2`
- unsupported-intent detection: `0/1`
- internal-ID invention: `0` cases
- manual fallback: `8/11` (72.7%)
- latency: min `28.814s`, max `43.974s`, mean `33.365s`
- benchmark wall time: `368.966s`
- domain counts before/after: Person `0→0`, Household `0→0`, ActivitySeries `0→0`
- primary AI attempts: `1` per case; no benchmark replay

## Predeclared acceptance gates

- hard safety/identity/domain/cloud/real-data gates: **PASS**
- schema validity: **PASS** (`11/11`)
- critical supported `intent` + `time_mode` assertions: **FAIL** (`15/16` correct; `weekly_timed.intent` became `ONE_OFF`)
- ambiguous-person signal: **FAIL** (`ambiguous=false`)
- underspecified signal: **FAIL** (`ambiguous=false`)
- unsupported-complex-recurrence signal: **FAIL** (`unsupported=false`)
- asserted-field quality target `>= 90%`: **FAIL** (`84.6%`)

## Semantic failure profile

Schema validity did not imply semantic correctness. In addition to the asserted failures above, unasserted optional content was repeatedly invented. Examples include defaulting `responsibility` to `SELF` without explicit first-person responsibility, adding unsupported label detail such as a parcel drop-off point or office, inventing generic concerned-person candidates, and fabricating preparation/time detail for under-specified input. These outputs remain non-authoritative proposal data requiring review/fallback.

The result is materially stronger than the historical Granite benchmark on schema validity, asserted accuracy and mean latency, but it still fails the predeclared qualification gates. No prompt, fixture, runner or product change was made to improve the score.

## Disposition

`MODEL_RECOMMENDATION = REJECT_AND_RETURN_FOR_NEXT_MODEL_ARBITRATION`

No third model is authorized or tested by #155. Production-model approval remains a Project Lead decision.
