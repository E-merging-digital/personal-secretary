---
name: existing-capability-audit
description: Evaluate existing Drupal and standard capabilities before introducing substantive custom implementation.
---

# Existing capability audit

Use before any new substantive capability, abstraction, orchestrator or custom
mechanism.

## Evaluation order

```text
Drupal Core
-> Drupal APIs / Drush
-> Recipes
-> stable maintained contrib
-> Drupal AI Initiative primitives when AI-related
-> existing repository and standard-system primitives
-> EXTEND EXISTING
-> BUILD CUSTOM only with demonstrated gap
```

For relevant candidates, record maintenance/stability, Drupal compatibility,
security signals, fit with product boundaries, operational complexity and
testability.

## Required conclusion

```text
EXISTING_CAPABILITY_AUDIT

Candidates evaluated:
- ...

DECISION =
USE EXISTING
| EXTEND EXISTING
| BUILD CUSTOM

CUSTOM_GAP =
<required only for BUILD CUSTOM>
```

Do not treat lack of familiarity, lack of research, personal preference or sunk
cost in already-written custom code as a gap.

For AI-related work, Drupal AI is the default provider abstraction when a stable
adequate primitive exists.
