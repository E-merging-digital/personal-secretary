# LOCAL_ONLY activity-capture benchmark — Granite 4.1 3B

This is the bounded synthetic evidence for issue #151. It contains no real personal/family input, runtime endpoint, model credential, or production AI configuration.

## Exact stack

- Drupal AI: `1.4.8`
- LM Studio Provider: `1.1.0`
- Model: `ibm/granite-4.1-3b` / Q4_K_M, local CPU
- Model identifier used by the synthetic runtime: `ps-granite-4.1-3b`
- Maintained Drupal AI request timeout qualified in isolated DEV: `120` seconds
- Primary attempts per case: `1`; maximum permitted: `2` only for deterministic syntax/schema repair
- Cloud fallback, tools, MCP, domain mutation: none

## Consumed baseline findings

Before this 11-case run, two independent consumed proofs already showed semantic failures: a relative one-off phrase was classified `WEEKLY`, and a separate explicit one-off timed Drupal case was classified `WEEKLY` + `ALL_DAY`. Those cases were not replayed to improve their outcomes.

## 11-case result

| Case | Schema | Asserted accuracy | Latency | Manual fallback | Failed asserted fields |
| --- | --- | ---: | ---: | --- | --- |
| `one_off_timed` | PASS | 100.0% | 118.028s | NO | — |
| `weekly_timed` | PASS | 66.7% | 40.784s | YES | intent, weekday |
| `all_day_one_off` | FAIL | 0.0% | 33.444s | YES | intent, time_mode, absolute_date, local_time, ambiguous, unsupported |
| `explicit_location` | FAIL | 0.0% | 27.719s | YES | intent, time_mode, location, absolute_date, local_time, ambiguous, unsupported |
| `concerned_person_text` | FAIL | 0.0% | 30.602s | YES | intent, time_mode, concerned_person_candidates, absolute_date, local_time, ambiguous, unsupported |
| `self_responsibility` | PASS | 87.5% | 37.282s | YES | responsibility_text |
| `text_responsibility` | PASS | 87.5% | 34.722s | YES | responsibility_text |
| `relative_near_midnight` | FAIL | 0.0% | 37.104s | YES | intent, time_mode, relative_date_expression, absolute_date, local_time, source_timezone, ambiguous, unsupported |
| `ambiguous_person` | PASS | 75.0% | 53.261s | YES | ambiguous |
| `unsupported_complex_recurrence` | PASS | 66.7% | 45.443s | YES | unsupported |
| `underspecified` | PASS | 0.0% | 36.524s | YES | ambiguous |

Summary:

- schema-valid: `7/11` (63.6%)
- asserted-field accuracy: `46.2%`
- ambiguity detection: `0/2`
- unsupported-intent detection: `0/1`
- internal-ID invention: `0` cases
- manual fallback: `10/11` (90.9%)
- latency: min `27.719s`, max `118.028s`, mean `44.992s`
- domain counts before/after: Person `0→0`, Household `0→0`, ActivitySeries `0→0`

## Predeclared acceptance gates

- hard safety/identity/domain/cloud gates: **PASS**
- critical supported `intent` + `time_mode` assertions: **FAIL** (`7/16` correct)
- ambiguous-person signal: **FAIL**
- underspecified signal: **FAIL**
- unsupported-complex-recurrence signal: **FAIL**
- asserted-field quality target `>= 90%`: **FAIL** (`46.2%`)

The benchmark also observed hallucinated unasserted location/responsibility content in schema-valid responses. This reinforces that output remains non-authoritative proposal data requiring review/fallback.

## Disposition

`MODEL_RECOMMENDATION = REJECT_AND_RETURN_FOR_NEXT_MODEL_ARBITRATION`

No second model is authorized or tested by #151. The maintained Drupal AI/provider path itself remains viable: the 120-second configuration seam removed the prior 60-second transport blocker without patch, fork, or custom OpenAI/LM Studio client.
