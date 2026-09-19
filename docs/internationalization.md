# Internationalization

## Canonical configuration language

The canonical source language for repository-owned Drupal configuration is English (`en`). This is a repository/distribution invariant and does not follow an installation's runtime language automatically.

These concerns are intentionally separate:

- configuration source language: canonical repository value `en`;
- site default language: installation-specific;
- interface language: site- and user-specific.

An installation may therefore use a different site default language without changing the canonical repository configuration source language.

## Interface strings

Repository-owned user-facing strings must use the appropriate Drupal translation primitives, including `$this->t()`, `TranslatableMarkup`, and Twig translation mechanisms where applicable.

## User-authored domain data

User-entered domain values are not automatically translatable. This includes, for example:

- Person names;
- Household names or labels;
- ActivitySeries labels;
- PreparationRequirement instructions;
- similar user-authored domain values.

Entity and field translation is opt-in only when a concrete product requirement exists. The default product policy is not to make domain fields translatable mechanically.

## Enforcement mechanism

Configuration Language Lock is the current configuration-consistency enforcement mechanism. It locks the configuration source language to English (`en`) and does not follow the site default language.

This module is infrastructure for configuration consistency; it is not domain authority and does not define which product data should be translatable.

If Drupal Core later provides a mature and sufficient equivalent capability, prefer Core and remove the contributed dependency when that replacement is safe.

## Runtime bilingual product UI

The runtime product languages are French (`fr`) and English (`en`), with French as the site default. Drupal Core `language` and `locale` own negotiation, User preference persistence and interface translation. The explicit language switcher uses Core's language block and path prefixes `/fr` and `/en`.

Project-owned French translations are committed with the module and imported locally on both clean installation and the existing-install update path. Project-owned UI correctness therefore does not depend on a live translation server. `config_translation` is not enabled because #147 does not introduce translated repository configuration.

Language switching never translates user-authored domain values and never changes User timezone, ActivitySeries source timezone, UTC instants, recurrence, or original occurrence identity.
