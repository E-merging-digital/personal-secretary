# Private production runtime contract

This document records the **application-level** runtime requirements for the
first private Personal Secretary production. Infrastructure implementation is
owned by `E-merging-digital/infrastructure`; the authoritative current
infrastructure boundary is issue `#15`, architecture comment `5649319908`.

This document does not prescribe Docker host layout, WSL management, NetBird
configuration, reverse proxy implementation, backup tooling, or database
restore commands.

## Environment roles and isolation

### DEV

DEV is for development only. The canonical project role is a Linux-native WSL
checkout at:

```text
/home/jmaerckaert/workspace/personal-secretary
```

It uses DDEV and the development configuration path.

DEV must not become the personal production runtime and the project must not be
moved to an NTFS-mounted working copy merely to share storage with Windows.

### Private PROD

PROD is the daily-use personal runtime. Infrastructure must provide it as a
separate lightweight Linux-native runtime with independent lifecycle and
storage.

The required isolation contract is:

```text
DEV DB != PROD DB
DEV containers != PROD containers
DEV volumes != PROD volumes
DEV lifecycle != PROD lifecycle
Agency runtime != Personal Secretary runtime
```

The current repository does not own the PROD container/host definition.
Host-specific Compose, WSL, NetBird and reverse-proxy artifacts belong to
Infrastructure unless they are explicitly delegated later.

## Production build and configuration path

The repository's existing production trajectory remains authoritative:

```text
composer install --no-dev
scripts/rebuild production
scripts/verify production
```

Production must keep development-only modules such as `devel` and
`security_review` disabled/absent and finish with no unexplained canonical
configuration drift.

DEV keeps DDEV and its development configuration split.

## Access and public ingress

Normal Personal Secretary UI access is private through the Infrastructure-owned
NetBird boundary.

```text
DIRECT_PUBLIC_DRUPAL_UI = NONE
PUBLIC_CALLBACK_REQUIREMENTS_NOW = NONE
```

Do not publish the full Drupal UI through public 80/443, DuckDNS/port
forwarding, or a generic public reverse proxy.

If a future integration such as Google Calendar proves that an externally
reachable OAuth callback or webhook is required, the project must first identify
the exact route and origin requirement. Infrastructure may then expose only the
narrow callback/webhook ingress needed for that integration. A generic webhook
platform or public Drupal application is not a current requirement.

The private origin supplied by Infrastructure must be suitable for the browser
security requirements of the privacy-safe PWA/Service Worker if PWA
installation is part of normal device usage.

## Persistent application data

MariaDB is the authoritative durable application state for the first PROD. It
contains Drupal state and Personal Secretary data including, among other things:

- Users and authentication-related application state;
- People and Households;
- ActivitySeries and revisions;
- ActivityExceptions;
- responsibility rules and overrides;
- time commitments;
- PersonalTasks;
- PreparationRequirements;
- PreparationCompletions;
- PreparationReminderDelivery state.

PROD also requires a separate writable `sites/default/files` runtime storage.
Current `main` has no identified product-managed user-upload/private-file
feature, so not every generated public file necessarily requires backup.
Infrastructure must nevertheless inventory the actual PROD files before backup
classification. Any non-recreatable file must be included in recovery.

If private-file storage is introduced later, it must be outside the public
webroot and included in the recovery contract.

Raw MariaDB datadir import is not a project-owned restore mechanism. Recovered
legacy datadirs are inputs for an Infrastructure-governed deliberate restore
only.

## Secret boundaries

No real secret value belongs in Git or exported Drupal configuration.

Current runtime secret categories:

```text
database credentials
Drupal hash_salt or equivalent runtime secret
```

When governed real SMTP is later enabled:

```text
SMTP password/token
```

When Google Calendar work is eventually resumed:

```text
OAuth client secret
at-rest encryption private key outside the database
```

Delegated per-user OAuth tokens are encrypted persisted application data, not
Git/config secrets.

NetBird enrollment/setup credentials are Infrastructure secrets and are not
application configuration.

Mandatory invariants:

```text
REAL_SECRET_IN_GIT = NO
RAW_SECRET_IN_EXPORTED_DRUPAL_CONFIG = NO
```

## Background jobs

The current product has one Personal Secretary background chain:

```text
Drupal cron
→ personal_secretary_cron()
→ enqueueDueReminders()
→ personal_secretary_preparation_reminder QueueWorker
```

No product-specific daemon, generic worker platform, or long-running queue
service is required by the current MVP. Infrastructure owns how normal Drupal
cron is scheduled in the runtime.

### Reminder scheduler activation gate

Current reminder semantics deliberately suppress blind replay. A certain
pre-send failure can persist `KNOWN_NOT_SUBMITTED`; only the bounded retry
contract may follow. Running the reminder chain in restored PROD before a real
outbound transport is ready can therefore consume reminder attempts without
delivering useful mail.

For real opted-in reminder consumption, PROD must be in exactly one of these
states:

```text
REMINDER_SCHEDULER = DISABLED_PENDING_TRANSPORT
```

or, after the separately governed real SMTP-provider proof:

```text
REMINDER_SCHEDULER = ENABLED_AND_PROVEN
```

Do not solve this by modifying user opt-in flags, deleting historical
PreparationReminderDelivery rows, or resetting retry state.

Core Drupal maintenance scheduling outside this product-specific reminder gate
remains an Infrastructure/runtime concern.

## Backup and recovery requirements

A live container or Docker volume is not a sufficient backup.

The recoverable PROD target is:

```text
Git repository
+ documented runtime/configuration contract
+ external secrets
+ portable database backup/export
+ required persistent files
= complete restoration
```

The database backup/export must exist outside the running database volume.
Required persistent files must be inventoried, and secret recovery must be
documented without storing secret values in Git.

Backup/recovery is owned by Infrastructure work `#6` and `#14`. Personal
Secretary considers a backup valid only after restoration has been materially
proven. DEV must remain independent and untouched by a PROD restore test.

## Restored-PROD product validation

After Infrastructure provisions PROD and performs the deliberate restore,
repository task `#141` validates product behavior against restored data. It
must reconcile product state before reminder scheduling is enabled, including
historical reminder delivery rows and opt-in state.

Product validation does not provision or repair Infrastructure.

## Validation baseline

The historical command:

```text
composer analyse:phpstan
```

is not present in current `composer.json` and its absence is not an
Infrastructure failure. Do not resurrect it solely for historical continuity.

Current authoritative repository validation materially consists of:

```text
composer validate --strict
composer audit --locked
existing Personal Secretary PHPUnit suite
Date Recur recurrence spike
scripts/rebuild development
scripts/verify development
scripts/rebuild production
scripts/verify production
git diff --check and repository drift checks
Governance workflow
Drupal workflow
```

A future static-analysis change requires a separate value-based Project Lead
decision.

## Migration boundary

The interim local PROD is intentionally migratable. A future dedicated
always-on host may take over the runtime, backup services and selected runners
without changing the logical application boundaries in this document.

Avoid application dependencies on Windows, WSL-specific paths, or the current
host identity.
