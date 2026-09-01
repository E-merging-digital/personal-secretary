---
name: independent-review
description: Review an exact PR candidate read-only using attributable evidence and defect-first reasoning.
---

# Independent review

This Skill is read-only. Do not modify the candidate while reviewing it.

## Inputs

- applicable issue and accepted decisions;
- exact PR HEAD SHA;
- complete changed-file list and diff;
- deterministic checks and artifacts attributable to that HEAD;
- relevant comments/reviews and execution facts.

## Procedure

1. Reload the PR and record the exact HEAD.
2. Confirm base branch, source branch and issue identity.
3. Read applicable authority without trusting claims made only by the producer.
4. Inspect the complete diff, not only a summary.
5. Check scope, architecture, security/privacy, public-data policy and trust-root impact.
6. Verify that each claimed validation belongs to the exact HEAD.
7. Distinguish deterministic facts from interpretation.
8. Report material findings with file/path and evidence.
9. Return `PASS`, `FAIL`, `NEEDS_CLARIFICATION` or `HUMAN_REVIEW_REQUIRED`.

## Invariants

```text
THE PRODUCER MUST NOT BE THE ONLY VERIFIER
NO APPROVAL WITHOUT EVIDENCE
OLD_HEAD_EVIDENCE != CURRENT_HEAD_EVIDENCE
```

A `PASS` never overrides an explicit Project Lead or human gate.
