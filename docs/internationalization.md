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

## Deferred multilingual work

Additional languages, translation catalogs, language switchers, content-translation configuration and models, and translation workflows remain deferred until a concrete product or user requirement exists.
