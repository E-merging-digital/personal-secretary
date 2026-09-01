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
-> stable + maintained + Drupal Security Team-covered contrib for production dependencies
-> Drupal AI Initiative primitives when AI-related
-> existing repository and standard-system primitives
-> EXTEND EXISTING
-> BUILD CUSTOM only with demonstrated gap
```

For relevant candidates, record maintenance/stability, Drupal compatibility,
Drupal Security Team coverage, fit with product boundaries, operational
complexity and testability.

Experimental, `-dev`, alpha, beta, RC or otherwise non-security-covered upstream
capabilities may still be researched, evaluated, prototyped or tested. Do not
reject emerging Drupal or Drupal AI Initiative capabilities merely because they
are not yet production-admissible.

Before making a non-security-covered or otherwise non-stable candidate a
production dependency, require a separate bounded explicit decision/exception
that records at least:

```text
necessity
risk
security/stability status
scope
upgrade/removal/re-evaluation trajectory
```

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
adequate primitive exists. Emerging Drupal AI capabilities may be evaluated
under the production-dependency boundary above.
