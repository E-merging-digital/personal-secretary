---
name: delivery-task
description: Execute one authorized repository task from live authority through exact-head PR evidence.
---

# Delivery task

Use this Skill for a concrete authorized modifying Task.

## Procedure

1. Reload `main`, the modifying Task issue and latest comments; also reload parent Epic/Decision authority when applicable.
2. Read `AGENTS.md`, applicable accepted decisions and `docs/workflow.md`.
3. Confirm one modifying Task issue, one canonical branch and one canonical PR.
4. Read `docs/operations/execution-capabilities.md` when execution routing matters.
5. Use the simplest sufficient surface. Do not call Codex unless a real execution gap requires it.
6. Implement only the authorized scope.
7. Validate proportionally to the diff and risk.
8. Inspect the complete diff and unexpected files.
9. Open or update the canonical PR.
10. Reload the exact PR HEAD and associate evidence with that SHA.
11. Return trust-root work to Project Lead before merge; otherwise follow repository merge authority.
12. Reload `main` after any authorized merge.

Epic and Decision issues may carry intent or authority and may be materialized by
a dedicated modifying Task/PR; they do not require their own branch/PR unless
explicitly converted into modifying executable work.

## Fail closed

Stop and return the contradiction when authority, scope, identity, dependency or
human gate is materially ambiguous.
