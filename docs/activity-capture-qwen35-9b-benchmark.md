# LOCAL_ONLY activity-capture benchmark — Qwen3.5 9B

This is the bounded synthetic evidence for issue #157. It contains no real personal/family input, production AI configuration, image input, cloud inference, MCP, tools, agents, or domain mutation.

## Exact stack and candidate

- Drupal core: `11.4.7`
- Drupal AI: `1.4.8`
- LM Studio Provider: `1.1.0`
- Model source: `lmstudio-community/Qwen3.5-9B-GGUF`
- Immutable source revision: `d9006465af6fc714af653e381fbfa55ead5af84b`
- Model: Qwen3.5 9B / `Q4_K_M`, local CPU
- GGUF filename: `Qwen3.5-9B-Q4_K_M.gguf`
- GGUF size: `5627044256` bytes
- GGUF SHA-256: `cd76ec205963b3b33350093e6904d9de16c4e666fd104e1f632d25c7f15f2a13`
- License: Apache-2.0
- LM Studio model identifier: `personal-secretary/qwen35-9b-nonthinking`
- LM Studio version: `ff50809`
- llmster version: `0.0.24+1`
- llama.cpp backend: `llama.cpp-linux-x86_64-avx2-2.33.0`
- Context: `4096`; parallel slots: `1`; GPU offload: `off / 0%`
- Drupal AI request timeout: `120` seconds
- Sampling overrides: none
- Frozen runner SHA-256: `88f7b469366e7cb65390bdc6ef199229b9ba99b269409ba7ef7bbf7bb8a67bc9`
- Frozen 11-case fixture SHA-256: `f4b60ad2ae32e4de357924e4d729cf2a49ce1ef21c8b7d52846f8fd4ee3d6fb1`
- Upstream multimodal companion remained indexed but vision/image use was `NONE`

## Infrastructure and local runtime

Infrastructure #74 terminally resolved the prior GNU OpenMP blocker: `libgomp1` is installed, `libgomp.so.1` resolves, the exact llama.cpp backend has no missing native libraries, and `llama-server --version` exits successfully. No runtime component was upgraded for this benchmark.

The exact already-verified GGUF was reused without redownload. Its presence, size and SHA-256 matched the accepted identity before load.

Resource/load evidence:

- resource estimate: `6.95 GiB` total, CPU/local, GPU offload `0%`
- RAM available before load: `14900518912` bytes
- RAM available after load: `10579075072` bytes
- available-RAM delta across load: `4321443840` bytes
- swap available before load: `4294967296` bytes
- swap available after load: `4294967296` bytes
- model load: **PASS**, `45.363836746s`

## Non-thinking proof

The authorized runtime composition set `enableThinking.defaultValue=false`, mapping to the Jinja variable `enable_thinking=false`; no sampling override or prompt-level `/no_think` mechanism was introduced.

LM Studio model-source traces for the actual Drupal AI request path showed that the rendered assistant prefix contained only an empty template marker (`<think></think>` with no content), while every emitted `llm.prediction.output` began directly with the structured JSON response and contained no reasoning/thinking text or think tag. Therefore:

- `THINKING_MODE_REQUESTED = DISABLED`
- `THINKING_MODE_EFFECTIVE = DISABLED`
- `THINK_BLOCK_OUTPUT = NONE`
- `SAMPLING_OVERRIDES = NONE`

## Distinct structured-output smoke

A synthetic smoke distinct from all 11 frozen cases used `Le 30 septembre 2026 à 08h, activité synthétique.` through the unchanged `ActivityCaptureInterpreter` → Drupal AI → LM Studio path.

- provider reachability: PASS
- Drupal AI invocation: PASS
- structured JSON schema response: PASS
- `ActivityCaptureProposal` parse: PASS
- thinking disabled: PASS
- internal-ID invention: none
- domain counts: Person `0→0`, Household `0→0`, ActivitySeries `0→0`
- smoke wall time: `117.786283993s`

The returned proposal preserved the explicit `ONE_OFF`, `TIMED`, `2026-09-30`, `08:00` and `Europe/Brussels` fields. No tuning followed the smoke.

## Exactly-once 11-case result

The unchanged frozen benchmark ran once, with one primary AI attempt per case and no semantic retry, restart, replay or timeout rescue. No deterministic second repair attempt was consumed.

| Case | Schema | Asserted accuracy | Latency | Manual fallback | Failed asserted fields |
| --- | --- | ---: | ---: | --- | --- |
| `one_off_timed` | PASS | 100.0% | 71.421s | NO | — |
| `weekly_timed` | PASS | 66.7% | 76.728s | YES | intent, ambiguous |
| `all_day_one_off` | PASS | 83.3% | 88.563s | YES | absolute_date |
| `explicit_location` | PASS | 100.0% | 84.169s | NO | — |
| `concerned_person_text` | FAIL | 0.0% | 76.547s | YES | proposal rejected: `TEXT responsibility requires responsibility_text` |
| `self_responsibility` | PASS | 100.0% | 73.107s | NO | — |
| `text_responsibility` | FAIL | 0.0% | 79.015s | YES | proposal rejected: `TEXT responsibility requires responsibility_text` |
| `relative_near_midnight` | PASS | 100.0% | 66.164s | NO | — |
| `ambiguous_person` | PASS | 75.0% | 79.818s | YES | ambiguous |
| `unsupported_complex_recurrence` | PASS | 66.7% | 84.728s | YES | unsupported |
| `underspecified` | PASS | 100.0% | 76.441s | YES | —; expected ambiguity correctly forces fallback |

Summary:

- schema-valid: `9/11` (81.8%)
- asserted-field accuracy: `36/52` (69.2%)
- critical supported `intent` + `time_mode`: `11/16` correct
- ambiguity detection: `1/2`
- underspecified detection: PASS (`ambiguous=true`)
- unsupported-complex-recurrence detection: `0/1`
- internal-ID invention: `0` cases
- manual fallback: `7/11` (63.6%)
- latency: min `66.164s`, max `88.563s`, mean `77.882s`
- timeout count: `0`
- sum of runner case latencies: `856.701s`
- benchmark wall time: approximately `858s` from recorded start `20:46:59+02:00` to finish `21:01:17+02:00`
- domain counts before/after: Person `0→0`, Household `0→0`, ActivitySeries `0→0`
- primary AI attempts: `1` per case; benchmark replay: none

## Predeclared acceptance gates

- hard safety/identity/domain/cloud/real-data gates: **PASS**
- schema validity `100%`: **FAIL** (`9/11`)
- asserted-field accuracy `>=90%`: **FAIL** (`69.2%`)
- critical supported `intent` + `time_mode`: **FAIL** (`11/16`)
- ambiguous-person signal: **FAIL** (`ambiguous=false`)
- underspecified signal: **PASS** (`ambiguous=true`)
- unsupported-complex-recurrence signal: **FAIL** (`unsupported=false`)
- internal-ID invention: **PASS** (`0`)
- domain mutation: **PASS** (`NONE`)

## Semantic failure and optional hallucination profile

Schema validity again did not imply semantic correctness. Material findings outside or adjacent to the asserted denominator include:

- `concerned_person_text`: Qwen invented `responsibility=TEXT` from a sentence that only identifies `Personne Alpha` as the concerned person, then emitted `responsibility_text=null`; `ActivityCaptureProposal` correctly rejected the result.
- `text_responsibility`: Qwen selected `responsibility=TEXT` but omitted the required `responsibility_text`, additionally emitted `concerned_person_candidates=["Personne Bêta"]`, and used the unrelated label `ALL_DAY` for a timed closing task; the proposal was rejected.
- `underspecified`: while correctly setting `ambiguous=true`, Qwen still filled optional semantic detail (`time_mode=ALL_DAY`, `relative_date_expression="bientôt"`, candidate `"quelqu'un"`) that must remain non-authoritative and behind manual clarification.
- `unsupported_complex_recurrence`: it converted the unsupported monthly recurrence into `intent=ONE_OFF`, added `relative_date_expression="first_monday_of_month"` and `weekday=MONDAY`, but failed to set `unsupported=true`.

`HALLUCINATED_OPTIONAL_CONTENT` is therefore materially present and reinforces the proposal-only/manual-review boundary.

## Historical comparison

| Metric | Granite 4.1 3B | Ministral 3 3B | Qwen3.5 9B |
| --- | ---: | ---: | ---: |
| Schema validity | 63.6% | 100.0% | 81.8% |
| Asserted-field accuracy | 46.2% | 84.6% | 69.2% |
| Critical intent/time_mode | 7/16 | 15/16 | 11/16 |
| Ambiguity detection | 0/2 | 0/2 | 1/2 |
| Unsupported recurrence | 0/1 | 0/1 | 0/1 |
| Manual fallback | 90.9% | 72.7% | 63.6% |
| Mean latency | 44.992s | 33.365s | 77.882s |

Granite and Ministral were not replayed. Their published historical evidence remains immutable. Qwen improves the under-specified ambiguity signal relative to those historical runs, but it fails multiple mandatory qualification gates and is materially slower on this host than either historical candidate's mean latency.

## Disposition

`MODEL_RECOMMENDATION = REJECT_AND_RETURN_FOR_NEXT_MODEL_ARBITRATION`

`PROD_MODEL_APPROVAL = NOT AUTHORIZED`

`REAL_DATA_INFERENCE = NOT AUTHORIZED`

No fourth model is authorized or tested by #157. Project Lead arbitration is required for any next model/product step.
