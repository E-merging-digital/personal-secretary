# 0014 — Time commitment et éligibilité calendrier provider-agnostic

Status: **ACCEPTED**
Decision authority: GitHub issue #80
Project Lead acceptance: comment 5541761747
Materialization task: #81

## Context

Personal Secretary sait désormais résoudre `CurrentPerson`, calculer les
`EffectiveOccurrence` après annulation/replanification et calculer la
`EffectiveResponsibility`. Le fait qu'une occurrence concerne la Person courante
ne prouve toutefois pas que toute sa fenêtre occupe son temps.

Decision 0003 impose qu'un calendrier externe reste une projection filtrée et
reconstructible depuis la vérité métier Drupal-owned. L'occupation temporelle doit
donc devenir une vérité de domaine explicite avant toute future intégration de
provider.

## Decision

```text
TIME_COMMITMENT = separate revisionable TimeCommitmentRule
SCOPE = stable ActivitySeries identity
PERSON_REFERENCE = NONE
SERIES_REVISION_REFERENCE = NONE
INITIAL_MODE = FULL_OCCURRENCE only
NO_MATCHING_RULE = NONE / NOT_ELIGIBLE
ONE_MATCHING_RULE = FULL_OCCURRENCE
AMBIGUOUS_MATCH = FAIL_CLOSED
MATCH_TIME = EffectiveOccurrence.effectiveUtcStart
RULE_INTERVAL = [effective_from, effective_until)
OCCURRENCE_TIME_COMMITMENT_OVERRIDE = DEFERRED
DEFAULT_EXISTING_DATA = NOT_ELIGIBLE
AUTOMATIC_BACKFILL = NONE
```

`NONE` n'est pas persisté : il est représenté par l'absence de règle applicable.
Une règle `FULL_OCCURRENCE` signifie que, si la Person courante est aussi la
responsable effective de l'occurrence, toute la fenêtre effective de cette
occurrence occupe son temps.

### Pourquoi une règle séparée

Une modification de `ActivitySeries` crée une révision sémantique. Or l'identité
exacte utilisée par `ActivityException` et `ResponsibilityOverride` contient le
contexte de révision de série. Une révision créée uniquement pour changer une
propriété calendrier provoquerait donc un churn d'identité et pourrait rendre des
cibles futures orphelines ou inertes sans modification de récurrence.

L'occupation temporelle reste également orthogonale à la responsabilité. La
stocker sur `ResponsibilityRule` ou `ResponsibilityOverride` obligerait les chemins
de mutation de responsabilité ponctuelle, récurrente et de changement d'horaire
à copier ou traduire un état qui ne leur appartient pas.

`TimeCommitmentRule` référence donc uniquement l'identité stable de
`ActivitySeries`.

### Lifecycle

Une règle est une Content Entity Drupal Core fieldable et revisionable. Elle porte :

```text
series
mode = full_occurrence
effective_from UTC
effective_until UTC optional
lifecycle_persisted_at
```

Le lifecycle est borné :

```text
NONE -> FULL = create one rule
FULL -> NONE = new revision with effective_until
FULL -> FULL equivalent = NOOP
overlap = REJECT at mutation boundary
ambiguous persisted overlap = FAIL_CLOSED at resolution
in-place semantic rewrite = NO
```

Le cutoff est exclusif. Aucune référence à une Person ou à une révision de série
n'est stockée dans la règle.

### Éligibilité calendrier

Le calcul est déterministe et provider-agnostic :

```text
EffectiveOccurrence
-> EffectiveResponsibility
-> effective TimeCommitmentRule at EffectiveOccurrence.effectiveUtcStart
-> CurrentPerson comparison
```

Une occurrence est éligible uniquement si :

```text
EffectiveResponsibility.state == ASSIGNED
AND responsiblePersonId == CurrentPerson.id
AND TimeCommitment == FULL_OCCURRENCE
=> ELIGIBLE
```

Tout autre état valide est `NOT_ELIGIBLE`. Un état de commitment ambigu ou
corrompu échoue en fail closed.

Responsabilité, préparation, appartenance Household, label, IA ou provider ne
peuvent jamais inférer implicitement `FULL_OCCURRENCE`.

### Reschedule et cancel

Un reschedule conserve l'identité originale existante et réévalue le commitment
à `EffectiveOccurrence.effectiveUtcStart`. Si l'occurrence est éligible, sa fenêtre
occupée est la fenêtre effective déplacée :

```text
effectiveUtcStart -> effectiveUtcEnd
```

Une annulation ne produit aucun `EffectiveOccurrence`, donc aucun candidat de
projection.

### CalendarProjectionCandidate

Le premier slice matérialise uniquement un value object dérivé :

```text
CalendarProjectionCandidate =
DERIVED
IMMUTABLE
NON_PERSISTED
PROVIDER_AGNOSTIC
RECONSTRUCTIBLE
```

Il réutilise l'identité exacte existante : série, contexte de révision gouvernante,
`originalOccurrenceKey`, contexte temporel original et fenêtre effective. Il
contient aussi `CurrentPerson`, le label d'activité et l'identité de la
`TimeCommitmentRule` ayant prouvé l'éligibilité.

Il ne contient aucun provider, compte externe, identifiant d'événement externe,
OAuth, credential, sync token, approval ou execution receipt.

```text
ORDINAL_ONLY_IDENTITY = NO
PROJECTION_PERSISTENCE = NONE
```

### Déploiement

Fresh install : Drupal Core découvre normalement la nouvelle Content Entity et
installe son schéma avec le module.

Existing install : l'update de #81 installe explicitement le nouveau type fieldable
via `EntityDefinitionUpdateManager::installFieldableEntityType(...)`, avec une
définition d'entité et des définitions de stockage de champs figées dans le hook
d'update. Il n'utilise ni SQL custom ni backfill.

Après upgrade :

```text
TimeCommitmentRule schema = installed
TimeCommitmentRule rows = 0
existing ActivitySeries = NOT_CALENDAR_ELIGIBLE
existing domain state/revisions = unchanged
```

### Séparation d'autorité

```text
CALENDAR_ELIGIBLE != AUTHORIZED_TO_SEND
DOMAIN_ELIGIBILITY != EXTERNAL_ACCOUNT_AUTHORITY
EXTERNAL_ACCOUNT_AUTHORITY != CREDENTIAL != APPROVAL
EXTERNAL_EGRESS_AUTHORIZED = NO
```

La liaison User -> Person de Decision 0013 reste une identité métier, jamais une
autorité Google, Microsoft, CalDAV ou autre.

## Explicit exclusions

```text
GOOGLE_CALENDAR = NONE
MICROSOFT_365 = NONE
CALDAV = NONE
OAUTH = NONE
CREDENTIALS = NONE
EXTERNAL_EVENT_ID = NONE
EXTERNAL_WRITE = NONE
SYNC_ENGINE = NONE
QUEUE = NONE
RECEIPTS = NONE
EGRESS = NONE
MCP = NONE
FLOWDROP = NONE
AI = NONE
OCCURRENCE_TIME_COMMITMENT_OVERRIDE = NONE
PARTIAL_WINDOW = NONE
TRAVEL_BEFORE_AFTER = NONE
PICKUP_DROPOFF = NONE
CUSTOM_OFFSETS = NONE
```
