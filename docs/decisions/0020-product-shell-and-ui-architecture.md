# Decision 0020 — Product shell and UI architecture

Status: ACCEPTED

Source evidence: #189
Architecture issue: #190
Materialization task: #192

## Problem

Personal Secretary already owns substantial domain and application behavior, but
the current user-facing experience exposes it as a collection of Drupal routes
and forms. The application needs one coherent, mobile-first product surface
without duplicating or replacing Drupal domain, authorization, identity,
mutation, recurrence, language, or PWA truth.

## Product presentation

The application presentation baseline is:

- one project-owned Drupal product theme;
- Drupal Core Single Directory Components (SDC);
- existing Drupal routes, forms, entities and services preserved;
- progressive enhancement for browser interactions.

No React, Vue, SPA framework, second authorization system, second mutation
system, or second domain layer is introduced.

The first representative implementation is the global product shell plus Today.
It is not a detached design-system project.

## Canvas and Canvas AI

Drupal Canvas is not adopted as a runtime dependency for this first application
shell slice. The decision is based on architecture fit and unnecessary runtime
dependency, not on instability.

Canvas AI is not adopted for this slice.

A later surface may evaluate Canvas separately where visual composition itself
has direct product value.

## Figma

Figma remains the serious representative design artifact for the slice. It must
cover only enough of shell + Today to validate navigation, hierarchy, spacing,
typography, cards/rows, empty state, responsive behavior, FR/EN and the relevant
role postures.

The existing artifact for #192 is preserved and must be reconciled with the
implementation before a human-acceptance candidate may be declared READY unless
a later Project Lead authority explicitly supersedes that external-tool gate.

Figma is not a runtime dependency and an external Figma tool quota does not
block independent repository implementation.

## Product home and shell

Today is the Personal Secretary product home.

The product logo/Home affordance routes to Today. Ordinary authenticated product
entry routes naturally to the product, rather than treating the Drupal User
profile as the application home.

Explicit account/profile access remains available when requested.

The shell provides persistent touch-first navigation to real existing product
destinations only. It does not manufacture routes to fill navigation.

## Role-aware Drupal chrome

The application distinguishes three presentation postures without creating a
new authorization model:

- product user: Personal Secretary shell, without Drupal administration chrome;
- product operator/editor: bounded domain-management capability and product
  shell, without site/config administration merely because of product
  permissions;
- true Drupal/site administrator: technical Drupal administration may remain
  available while the Personal Secretary shell remains usable.

Drupal permissions remain authoritative.

## Today

Today remains a derived composition from the existing Today service and current
domain services.

No Today persistence is introduced.

The shell may improve hierarchy, responsive composition, empty states, cards,
rows, links and localized presentation while preserving the existing Tasks,
Activities and Preparations truth.

Task lifecycle redesign, Activity detail, recurrence expansion, Preparation
AJAX redesign, Event domain work, calendar synchronization, push, finance and
assistant briefing are outside this decision.

## Languages

The bilingual architecture from #147 remains authoritative:

- Drupal Core language/locale;
- French default;
- English available;
- durable User preference;
- no custom language engine;
- user-entered content is not automatically translated.

The product shell must expose a discoverable language switcher.

## PWA

The privacy baseline from #132 remains authoritative:

- PWA start URL is Today;
- generic offline fallback remains available;
- personal HTML and Personal Secretary domain data are not cached for offline
  use;
- push and background sync are not introduced by this slice.

## Browser proof

Static and PHP proofs do not replace browser evidence for important interactive
UX.

Existing executable browser capabilities must be reused first. A small
Drupal-native FunctionalJavascript extension is acceptable when it fits the
existing stack. A new generic E2E platform is not justified solely for ceremony.

## Promotion

No production promotion is authorized by this decision or by #192 Delivery.

After an exact merge gate is separately authorized, one controlled production
promotion may be performed while preserving existing scheduler, SMTP, OAuth,
language, timezone and PWA semantics.
