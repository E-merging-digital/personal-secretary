# Decision 0021 — Taxonomie Event / Activity et contrat Google Calendar

Status: **ACCEPTED**

Decision issue: #200
Source evidence: #189
Parent recovery epic: #191
Authority: PROJECT_LEAD_200_DECISION_AUTHORITY_R1 / 5900915427
Materialization branch: work/issue-200-event-calendar-decision

## Context

Personal Secretary possède déjà des vérités métier distinctes pour le travail
autonome, les activités planifiées, leurs occurrences effectives et les
préparatifs.

Les décisions acceptées établissent notamment :

~~~text
PersonalTask =
independent actionable work

ActivitySeries =
time-bounded activity/event

ordinary Activity occurrence =
calculated / no persistent occurrence entity

ActivityException =
explicit cancel/reschedule of one audited occurrence

PreparationRequirement =
preparation around an ActivitySeries

CalendarAccountConnection =
authenticated Drupal User-owned Google account connection
~~~

Decision 0010 fixe l'identité d'une occurrence autour de la série, de la révision
gouvernante et de original_occurrence_key. Une occurrence ordinaire reste calculée
et n'est pas matérialisée comme entité métier.

Decision 0014 sépare explicitement TimeCommitment, éligibilité calendrier et
autorité d'un provider externe.

Decision 0016 sépare PersonalTask du domaine des activités planifiées.

Decision 0019 matérialise une connexion Google Calendar possédée par le Drupal
User authentifié avec seulement :

~~~text
openid
https://www.googleapis.com/auth/calendar.calendars.readonly
~~~

Aucun event-read, event-write, identifiant d'événement externe, sync token ou
moteur de synchronisation n'est aujourd'hui autorisé.

L'acceptance humaine #189 a en plus révélé deux besoins produit :

1. quelque chose comme un anniversaire est un événement à retenir, pas une tâche
   à compléter ;
2. Personal Secretary doit à terme connaître les événements pertinents du
   calendrier réel de l'utilisateur, y compris ceux qui naissent directement dans
   Google Calendar, tout en conservant sa propre vérité locale pour ce qu'il crée.

Cette décision fixe la taxonomie produit et le contrat de synchronisation avant
toute lecture ou écriture d'événement Google.

#200 ne réalise aucune I/O événement Google.

## Decision

La décision est :

~~~text
TASK DOMAIN =
PersonalTask

SCHEDULED DOMAIN =
ActivitySeries

EVENT MODEL =
explicit classification / subtype of ActivitySeries

NEW EVENT ENTITY =
NO

ORDINARY OCCURRENCE ENTITY =
NO

SOURCE-OF-TRUTH MODEL =
bidirectional awareness with explicit origin ownership and conflict rules

FIRST IMPLEMENTATION DIRECTION =
manual outbound only

GOOGLE EVENT READ IN #200 =
NONE

GOOGLE EVENT WRITE IN #200 =
NONE

OAUTH SCOPE ESCALATION IN #200 =
NONE

SYNC ENGINE IN #200 =
NONE
~~~

Event est donc l'option B de #200 : une classification sémantique explicite
d'ActivitySeries.

ActivitySeries reste la primitive locale canonique pour les éléments planifiés
créés dans Personal Secretary.

Aucun gap de capacité ne justifie une nouvelle entité Event : ActivitySeries
porte déjà l'identité stable, le temps, le mode all-day, le fuseau source,
Date Recur, les révisions sémantiques et les exceptions d'occurrence.

Une future implémentation pourra matérialiser une propriété explicite du type :

~~~text
scheduled_kind =
ACTIVITY | EVENT
~~~

Cette classification ne doit jamais être déduite de la présence ou de l'absence
de responsabilité, de préparatifs, de Person concernée, de lieu ou de
TimeCommitment.

## User-facing taxonomy

### Task / Tâche

Une Tâche est quelque chose à faire et éventuellement à compléter.

Exemples :

- acheter des piles ;
- appeler le dentiste ;
- remettre un document.

Le domaine canonique reste :

~~~text
Task -> PersonalTask
~~~

Une date d'échéance n'est pas une durée d'événement.

Une tâche ne devient pas un Event uniquement parce qu'elle possède une date.

### Event / Événement

Un Événement est quelque chose qui se produit, est planifié ou doit être retenu à
une date/heure ou sur un ou plusieurs jours civils.

Exemples :

- anniversaire ;
- rendez-vous ;
- réunion de conseil ;
- commémoration.

Le domaine canonique local est :

~~~text
Event -> ActivitySeries classified as EVENT
~~~

Un Event :

- peut être timed ou all-day ;
- peut être unique ou récurrent ;
- n'a pas de lifecycle de completion ;
- ne requiert pas de responsable effectif ;
- ne requiert pas de préparation ;
- ne signifie pas automatiquement que toute sa fenêtre occupe le temps de
  l'utilisateur ;
- peut à terme recevoir une politique de reminder propre aux événements ;
- peut être éligible à une projection calendrier selon une politique explicite
  distincte de TimeCommitment.

### Activity / Activité

Une Activité est un élément planifié auquel Personal Secretary peut associer les
sémantiques Household plus riches déjà présentes.

Exemples :

- cours de tennis d'Eva ;
- activité familiale ;
- activité qui nécessite un responsable ou un préparatif.

Le domaine canonique est :

~~~text
Activity -> ActivitySeries classified as ACTIVITY
~~~

Une Activity peut participer à :

- concerned Persons ;
- effective responsibility ;
- PreparationRequirement ;
- TimeCommitment ;
- projection calendrier.

Ces capacités restent orthogonales. Elles ne définissent pas à elles seules le
type Activity.

### Invariants de présentation et de domaine

Les concepts suivants restent indépendants :

~~~text
VISIBLE_IN_PERSONAL_SECRETARY_AGENDA
!= OCCUPIES_USER_TIME
!= ELIGIBLE_FOR_EXTERNAL_CALENDAR_PROJECTION
!= REMINDER_ENABLED
!= HAS_RESPONSIBILITY
!= HAS_PREPARATIONS
~~~

Decision 0014 continue donc de gouverner l'éligibilité calendrier existante basée
sur TimeCommitment pour les use cases d'occupation de temps.

Une future politique Event ne doit pas redéfinir silencieusement TimeCommitment.

## Domain mapping

Aucune entité Event distincte n'est créée.

ActivitySeries offre déjà :

- identité stable et UUID ;
- révisions sémantiques ;
- timed ;
- all-day ;
- timezone source ;
- récurrence via Date Recur ;
- timeline effective ;
- original_occurrence_key ;
- cancel/reschedule via ActivityException.

Un anniversaire ou autre Event simple peut donc réutiliser exactement ce socle
sans recevoir de fausses sémantiques de PersonalTask, responsabilité ou
préparation.

Les récurrences mensuelles, nth-weekday ou annuelles devront utiliser les
capacités Date Recur/RRULE lorsqu'elles seront exposées par le produit.

Personal Secretary ne crée pas de second moteur RRULE.

Les occurrences ordinaires restent calculées. Le fait que Google expose des
ressources d'instance n'est pas une raison de créer une entité locale
ActivityOccurrence.

## Source of truth

Le modèle cible est :

~~~text
BIDIRECTIONAL TRUTH
WITH
EXPLICIT ORIGIN OWNERSHIP
AND
EXPLICIT CONFLICT RULES
~~~

Il ne s'agit pas d'un last-write-wins générique.

### Élément créé dans Personal Secretary

Pour un ActivitySeries créé dans Personal Secretary :

~~~text
CANONICAL OWNER =
Personal Secretary
~~~

Personal Secretary possède :

- titre ;
- start/end ;
- all-day ;
- source timezone ;
- recurrence ;
- révisions de série ;
- exception/replanification d'occurrence ;
- annulation locale ;
- lieu ;
- lifecycle local.

Google est une projection de cette vérité.

Une modification distante ne réécrit jamais silencieusement ActivitySeries.

### Élément créé dans Google

Pour un événement né dans Google Calendar puis rendu visible à Personal
Secretary :

~~~text
CANONICAL CALENDAR OWNER =
Google
~~~

Le premier modèle inbound doit être une représentation/provider projection
read-only appartenant à Google.

Il ne doit pas fabriquer automatiquement :

- ActivitySeries ;
- Household ;
- ResponsibilityRule ;
- PreparationRequirement ;
- TimeCommitment.

Une éventuelle opération future "Adopter dans Personal Secretary" nécessitera une
autorité explicite et ne sera jamais implicite pendant une synchronisation.

### Ownership par champ

Pour un élément Personal Secretary-origin :

~~~text
title =
Personal Secretary

time =
Personal Secretary

all-day =
Personal Secretary

recurrence =
Personal Secretary

occurrence exception =
Personal Secretary

cancellation/deletion intent =
Personal Secretary

responsibility =
Personal Secretary only

preparations =
Personal Secretary only
~~~

Pour un élément Google-origin :

~~~text
title =
Google

time =
Google

all-day =
Google

recurrence =
Google

occurrence exception =
Google

remote cancellation/deletion =
Google

responsibility =
not inferred from Google

preparations =
not inferred from Google
~~~

## Sync direction

Les trois directions sont des capacités différentes.

### OUTBOUND — Personal Secretary -> Google

Décision :

~~~text
FIRST AUTHORIZED IMPLEMENTATION DIRECTION =
OUTBOUND
~~~

Le premier slice choisi plus bas est un export manuel, borné et explicite.

La préférence produit exprimée pendant l'acceptance pour une projection
automatique par défaut reste une étape ultérieure, après preuve du contrat
manuel, consentement explicite au scope d'écriture et définition de la politique
d'opt-in.

### INBOUND — Google -> Personal Secretary

Décision cible :

~~~text
EVENTUALLY REQUIRED =
YES

FIRST SLICE =
NO
~~~

Inbound est nécessaire pour que le planning futur voie les événements pertinents
qui n'ont pas été créés dans Personal Secretary.

Le premier inbound devra être read-only et provider-owned.

Il ne convertira pas automatiquement les événements Google en ActivitySeries.

### BIDIRECTIONAL

Décision cible :

~~~text
EVENTUAL BIDIRECTIONAL AWARENESS =
YES

GENERIC TWO-WAY SYNC ENGINE =
NO

FIRST SLICE =
NO
~~~

La bidirectionnalité résulte de l'origin ownership, des mappings et des règles de
conflit, pas d'une permission accordée aux deux systèmes d'écraser chaque champ.

## External identity

#200 ne persiste aucun identifiant externe.

### Identité Personal Secretary autoritative

L'identité locale exacte d'une occurrence reste :

~~~text
ActivitySeries stable identity / UUID
+
governing ActivitySeries revision
+
original_occurrence_key
~~~

Une replanification conserve original_occurrence_key.

L'heure effective n'est jamais une nouvelle identité.

### Target Google calendar ID

Pour le premier slice :

~~~text
SEMANTIC TARGET =
primary
~~~

Decision 0019 reste compatible : aucun concrete Google calendar ID n'a besoin
d'être persisté pour cibler primary.

Pour un futur multi-calendar, un concrete calendar ID deviendra du transport
metadata lié à la connexion utilisateur.

Il ne deviendra pas identité métier.

### Google event ID

Le Google event ID est la clé provider nécessaire pour relire/muter la ressource
distante correspondante.

Il est autoritatif pour la mutation distante, mais reste du transport metadata du
point de vue du domaine Personal Secretary.

### iCalUID

iCalUID est un identifiant d'interopérabilité/corrélation.

Il ne remplace pas calendar ID + Google event ID comme clé de mutation provider.

Il ne remplace jamais l'identité locale ActivitySeries/original_occurrence_key.

### ETag / version

ETag/version est du metadata de concurrence.

Il sert à détecter qu'un objet distant a changé avant une mise à jour.

Il n'est pas identité métier.

### Sync token

Un sync token est un curseur provider pour l'inbound incrémental.

Il appartient à un flux compte/calendrier, pas à ActivitySeries ou à une
occurrence.

Il ne devient jamais une identité de domaine.

### Recurring Google identity

Pour les événements récurrents Google, les éléments de transport pertinents sont :

~~~text
recurringEventId
originalStartTime
instance event ID
~~~

Google définit originalStartTime comme l'instant d'origine de l'instance selon la
récurrence du parent ; il reste stable même lorsque l'instance est déplacée.

Cette sémantique est compatible avec la doctrine Personal Secretary :

~~~text
original occurrence identity
!= effective moved time
~~~

originalStartTime reste toutefois du provider metadata et ne remplace pas
original_occurrence_key.

## Recurrence mapping

Personal Secretary reste autoritatif pour la récurrence locale.

Aucun second moteur RRULE n'est créé.

### Single event

Un ActivitySeries non récurrent projeté vers Google correspond à un événement
Google unique.

### Recurring series

Une série locale récurrente se projette sur un recurring event Google uniquement
si la récurrence locale peut être exprimée sans perte de sens dans le contrat
provider.

L'adapter dérive le provider recurrence depuis Date Recur/RRULE.

Il ne recalcule pas la récurrence par un nouveau moteur.

Les instances Google ordinaires ne sont pas matérialisées comme nouvelles
entités métier locales.

### Moved occurrence

Un ActivityException reschedule conserve :

~~~text
local original_occurrence_key
~~~

et modifie uniquement la fenêtre effective.

Le provider adapter doit cibler l'instance Google correspondant à l'identité
originale, puis créer une exception d'instance avec la nouvelle fenêtre.

L'heure déplacée est effective truth, pas identité.

### Cancelled occurrence

Une annulation locale reste un ActivityException.

La future projection Google doit annuler/supprimer uniquement l'instance distante
correspondante.

Elle ne réécrit pas la RRULE locale et ne crée pas une occurrence locale
persistante.

### Series edit

Une modification sémantique de série qui concerne toute la série peut mettre à
jour le recurring master Google lorsque le contrat reste équivalent.

### Future-only recurrence edit

Une révision locale future-only ne doit pas être aplatie comme si toute
l'historique était une seule série mutable.

Lorsque le provider exige un comportement "this and following", l'adapter doit
préserver la borne effective locale, typiquement par :

1. limitation du segment recurring précédent à la borne ;
2. création d'un nouveau recurring segment pour la nouvelle révision.

Il ne modifie pas chaque occurrence future individuellement.

Les exceptions locales à travers la borne restent soumises aux règles existantes
d'orphelinage et réconciliation.

### All-day occurrence

All-day reste une sémantique civile :

~~~text
Google start.date =
inclusive local civil start date

Google end.date =
exclusive civil end date
~~~

Aucune fausse heure minuit n'est introduite.

### Timezone-aware timed occurrence

Les événements timed utilisent :

- l'instant effectif ;
- la timezone source canonique.

L'UTC peut être une représentation de transport, mais ne remplace pas la timezone
source pour la récurrence et la présentation.

## Conflict policy

Le contrat est origin-aware.

Aucun last-write-wins silencieux n'est autorisé.

### Local edit after export

Pour un élément Personal Secretary-origin :

~~~text
LOCAL DOMAIN =
authoritative

REMOTE PROJECTION =
stale until explicitly synchronized
~~~

Une future mise à jour distante n'est permise automatiquement que si ETag/version
prouve que Google n'a pas été modifié indépendamment.

### Remote edit after export

Si la projection Google d'un élément Personal Secretary-origin a changé à
distance :

~~~text
AUTO-IMPORT INTO LOCAL DOMAIN =
NO

SILENT REMOTE OVERWRITE =
NO

RESULT =
explicit conflict
~~~

Une future UI pourra proposer, sous autorité séparée :

- re-projeter la vérité locale ;
- adopter explicitement certains champs Google.

### Simultaneous local + remote changes

~~~text
LAST WRITE WINS =
NO

AUTOMATIC MERGE =
NO

RESULT =
explicit conflict
~~~

Responsibility et Preparation n'entrent jamais dans le merge Google.

### Remote deletion

La suppression distante d'une projection Personal Secretary-origin :

- ne supprime pas ActivitySeries ;
- n'annule pas la vérité locale ;
- rend le mapping remote-missing/conflicted ;
- exige une décision explicite avant ré-export.

### Local deletion/cancellation

Une mutation locale valide reste réussie indépendamment de Google.

Quand la propagation outbound sera autorisée, elle constituera une étape provider
séparée.

Un échec Google ne rollback pas une vérité locale correctement mutée.

### Target calendar removed

Le target devient indisponible.

La vérité locale reste inchangée.

Il n'y a pas de fallback automatique vers un autre calendrier.

### Permission revoked

La synchronisation s'arrête.

Aucun scope supplémentaire n'est demandé silencieusement.

La vérité locale reste utilisable.

### Recurring occurrence moved remotely

Pour une série Personal Secretary-origin :

~~~text
REMOTE MOVE =
conflict

AUTO-CREATE LOCAL ActivityException =
NO
~~~

Pour une série Google-origin, la projection read-only suit Google en conservant
son identité provider.

### Series recurrence changed remotely

Pour une série Personal Secretary-origin :

~~~text
REMOTE RECURRENCE CHANGE =
conflict

AUTO-REWRITE ActivitySeries =
NO
~~~

Pour une série Google-origin, le read model suit Google.

## OAuth scopes

#200 modifie zéro scope OAuth.

~~~text
OAUTH SCOPE ESCALATION =
NONE
~~~

Les scopes ci-dessous définissent seulement les besoins futurs minimaux.

### ACCOUNT CONNECTION SCOPE

Decision 0019 reste inchangée :

~~~text
openid
https://www.googleapis.com/auth/calendar.calendars.readonly
~~~

Objet :

- identité Google du compte ;
- vérification des propriétés du semantic primary calendar.

Ces scopes n'autorisent pas la lecture ou l'écriture d'événements.

### CALENDAR LIST SCOPE

Pour un futur chooser/multi-calendar :

~~~text
https://www.googleapis.com/auth/calendar.calendarlist.readonly
~~~

Ce scope n'est pas requis pour le premier slice outbound qui cible primary.

### EVENT READ SCOPE

Pour lire les événements des calendriers pertinents accessibles à
l'utilisateur :

~~~text
https://www.googleapis.com/auth/calendar.events.readonly
~~~

Ce scope reste distinct de calendar metadata/list.

Le scope owned-only read ne suffit pas comme contrat inbound général puisque le
besoin produit futur inclut potentiellement des calendriers accessibles mais non
possédés.

### EVENT WRITE SCOPE

Pour le premier outbound borné vers le primary calendar possédé par le User :

~~~text
https://www.googleapis.com/auth/calendar.events.owned
~~~

Ce scope permet de voir, créer, modifier et supprimer les événements sur les
Google calendars possédés par l'utilisateur.

Si un futur use case doit écrire dans des calendriers accessibles mais non
possédés, une décision séparée devra justifier le scope plus large :

~~~text
https://www.googleapis.com/auth/calendar.events
~~~

Le scope global calendar n'est pas la valeur par défaut.

### Consent

Tout nouveau capability demandant un scope supplémentaire requiert un consentement
explicite.

Une connexion Decision 0019 existante n'est jamais considérée comme si elle
possédait déjà event-read ou event-write.

Aucune connexion n'est silencieusement upgradée.

## Privacy/security boundary

Les événements de calendrier sont des données personnelles sensibles.

L'egress doit être explicite et minimisé.

### Données exportables dans un scénario outbound

Lorsque le capability choisi l'exige :

~~~text
title =
YES

effective start/end =
YES

all-day civil dates =
YES

source timezone =
YES

location =
YES when explicitly part of the export contract

recurrence =
YES only when recurrence export is authorized
~~~

### Données non exportées par défaut

~~~text
responsible Person =
NO

concerned Person names =
NO by default

PreparationRequirement =
NO

PreparationCompletion =
NO

Household identity =
NO

Drupal User ID =
NO

internal database IDs =
NO

authorization grants =
NO

internal audit/lifecycle payloads =
NO
~~~

Attendees ne sont jamais inférés automatiquement depuis concerned Persons ou
responsibility.

Aucune description Google riche n'est synthétisée depuis Household,
responsibility ou preparation.

Un futur opaque integration identifier peut être stocké comme private provider
metadata si le mapping l'exige, mais les IDs internes bruts ne sont jamais
présentés comme contenu visible de l'événement Google.

### Token and credential ownership

La connexion Google et les credentials OAuth délégués restent possédés par le
Drupal User authentifié.

Ils n'appartiennent ni à Person ni à Household.

Les frontières de secret, chiffrement et refresh-token de Decision 0019 restent
autoritatives.

Les futurs mappings/cursors sont account-scoped.

## Disconnect semantics

Decision 0019 reste autoritative : disconnect est local-first.

### Disconnect

~~~text
LOCAL ActivitySeries =
PRESERVED

PersonalTask =
PRESERVED

GOOGLE TOKEN =
CLEARED

CalendarAccountConnection =
CLEARED

ACTIVE GOOGLE I/O =
STOPPED

REMOTE GOOGLE EVENTS =
NOT SILENTLY DELETED
~~~

Les futurs sync cursors et mappings deviennent inertes.

Ils ne peuvent plus autoriser aucune lecture ou écriture.

Leur mécanisme exact de rétention ou tombstone sera défini avec leur future
persistance ; #200 n'en crée aucun.

### Reconnect

Reconnect exige une nouvelle autorisation OAuth explicite.

Un mapping précédent ne peut être réactivé automatiquement que si le futur
contrat prouve au minimum :

- même provider ;
- même Google subject ;
- même cible ;
- ressource distante toujours cohérente.

Un reconnect vers un autre Google subject ne réutilise jamais d'ancien mapping.

En l'absence de preuve sûre, une réconciliation explicite est requise.

### Token expiration

L'expiration seule déclenche le refresh selon le contrat OAuth existant.

Si le refresh échoue durablement, la connexion devient invalide et les I/O Google
s'arrêtent.

Aucune donnée locale n'est supprimée.

### OAuth revocation

La révocation provider est traitée comme perte d'autorité externe.

La vérité locale reste inchangée.

La reconnexion exige un nouveau consentement.

## Alternatives rejected

### A — Event comme simple mot d'interface sans classification explicite

Rejeté.

Le produit aura besoin de distinguer durablement un Event simple d'une Activity
riche pour :

- création produit ;
- politique reminder ;
- politique de projection calendrier ;
- defaults futurs.

Cette différence ne peut pas être devinée depuis Responsibility ou Preparation.

### C — Nouvelle entité persistante Event

Rejeté.

Aucun gap exact n'est démontré.

ActivitySeries possède déjà les invariants de planification les plus difficiles :
temps, all-day, timezone, recurrence, revisions et exceptions.

Une seconde entité dupliquerait ces invariants et créerait deux vérités calendrier
locales.

### Google comme unique source de vérité

Rejeté.

Personal Secretary doit conserver sa vérité locale et ses sémantiques
responsibility/preparation, et rester utilisable quand Google ne l'est pas.

### Personal Secretary-only + fire-and-forget export comme architecture cible

Rejeté.

Le planning futur doit aussi voir les événements Google-origin.

Un simple export one-way laisserait une vue incomplète de la journée.

### Bidirectional unrestricted last-write-wins

Rejeté.

Il mélangerait sans bornes :

- autorité compte ;
- event read ;
- event write ;
- conflits ;
- recurrence ;
- adoption domaine.

### Persistent ordinary occurrences

Rejeté.

Le modèle calculé actuel suffit.

La représentation d'instances chez Google ne justifie pas une nouvelle table
d'occurrences locales.

### Second RRULE engine

Rejeté.

Date Recur et le modèle ActivitySeries/ActivityException restent autoritatifs.

## Explicit exclusions

#200 introduit exactement zéro des éléments suivants :

~~~text
GOOGLE EVENT READ =
NONE

GOOGLE EVENT WRITE =
NONE

OAUTH SCOPE ESCALATION =
NONE

SYNC ENGINE =
NONE

QUEUE =
NONE

WEBHOOK =
NONE

CALDAV =
NONE

MICROSOFT 365 =
NONE

NEW EVENT ENTITY =
NONE

NEW ORDINARY OCCURRENCE ENTITY =
NONE

NEW RRULE ENGINE =
NONE

PRODUCT UI REDESIGN =
NONE

SCHEDULER CHANGE =
NONE

TASK DOMAIN CHANGE =
NONE

PREPARATION DOMAIN CHANGE =
NONE

PROD =
NONE

MERGE =
NONE
~~~

## Compatibility/migration impact

#200 est documentation-only.

~~~text
SCHEMA CHANGE =
NONE

CODE CHANGE =
NONE

CONFIG CHANGE =
NONE

DATA MIGRATION =
NONE

OAUTH CHANGE =
NONE
~~~

Le comportement actuel d'ActivitySeries reste inchangé.

Si scheduled_kind est matérialisé ultérieurement, les ActivitySeries existants
doivent conserver par défaut la sémantique ACTIVITY.

Aucune ligne existante n'est reclassifiée automatiquement depuis responsibility,
preparation, TimeCommitment ou autre état associé.

PersonalTask reste inchangé.

ActivityException et original_occurrence_key restent inchangés.

Decision 0014 reste autoritative pour l'éligibilité calendrier existante.

Decision 0019 reste autoritative pour la connexion Google actuelle.

Une future capacité Event qui exige un scope supplémentaire déclenchera une
nouvelle autorisation explicite ; les connexions historiques ne seront pas
considérées comme déjà autorisées.

## First bounded implementation slice

La première tranche d'implémentation sélectionnée après cette décision est :

~~~text
MANUAL OUTBOUND EXPORT
OF ONE NON-RECURRING LOCAL ActivitySeries OCCURRENCE
TO THE CONNECTED USER'S semantic primary GOOGLE CALENDAR
~~~

Cette tranche future devra rester bornée à :

- action utilisateur explicite ;
- un Drupal User connecté ;
- semantic target primary ;
- une occurrence locale non récurrente ;
- Personal Secretary comme source de vérité ;
- scope futur minimal calendar.events.owned ;
- action serveur autorisée et CSRF-safe ;
- mapping provider durable suffisant pour empêcher un blind duplicate export ;
- Google event ID et ETag traités comme integration metadata ;
- aucun recurring sync ;
- aucun inbound read ;
- aucun update loop ;
- aucune propagation automatique de delete/cancel ;
- aucune queue ;
- aucun webhook ;
- aucun background sync engine ;
- aucun automatic export default à ce stade.

Cette tranche est volontairement plus étroite que la préférence produit finale
d'export automatique.

Elle doit prouver séparément :

1. account authority ;
2. explicit scope consent ;
3. privacy/egress boundary ;
4. local-to-provider identity mapping ;
5. provider failure behavior.

Après seulement cette preuve, Project Lead pourra autoriser un slice distinct
pour inbound read, automatic projection ou recurring synchronization.

#200 n'implémente pas cette première tranche.

## External references verified for this decision

Google Calendar API scope documentation was verified on 2026-09-30.

Relevant provider facts used by this ADR:

- calendar.events.readonly reads events on calendars accessible to the User;
- calendar.events.owned reads/writes events on calendars the User owns;
- calendar.calendarlist.readonly reads the subscribed calendar list;
- recurringEventId links an instance to its recurring parent;
- originalStartTime uniquely identifies the recurring instance even after it is
  moved;
- Google documents "this and following" style recurrence changes by trimming the
  old recurring event and creating a new recurring event.

These provider facts do not create any runtime dependency or authority in #200.
