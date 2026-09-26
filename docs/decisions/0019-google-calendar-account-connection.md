# Decision 0019 — Google Calendar account connection

Status: ACCEPTED

Issue: #83
Materialization task: #84

## Ownership and authority

The authenticated Drupal User owns the external Google account connection.

The first and only provider in this slice is `google`.

`Person` does not own, authorize, or identify the external account.

One authenticated User may have at most one Google account connection.

## Durable connection metadata

The durable connection entity stores only:

- owner Drupal User;
- provider key `google`;
- Google OpenID Connect `sub`;
- exact granted connection scopes;
- connection state `CONNECTED` or `INVALID`;
- connection timestamp.

`NOT_CONNECTED` is represented by absence of a connection entity.

Email, display name and profile data are not provider identity and are not
persisted.

The semantic calendar target is `primary`.

A concrete Google calendar ID is never persisted.

## OAuth scopes

The exact requested connection scopes are:

- `openid`;
- `https://www.googleapis.com/auth/calendar.calendars.readonly`.

No Calendar event-read or event-write scope is requested.

The OAuth flow requests offline access.

Authorization state is ephemeral and bound to the authenticated Drupal User
who initiated the authorization attempt.

## Google verification

Successful connection completion requires:

1. valid authorization state for the current Drupal User;
2. a Google OIDC `sub`;
3. the exact allowed scopes;
4. successful `Calendars.get("primary")`.

Calendar listing is not performed.

Calendar event listing, reading, creation, update and deletion are outside
this decision.

## Secret and token boundaries

The OAuth application secret is obtained through Drupal Key using an external
production-compatible secret provider.

Delegated per-User OAuth token material is stored behind the OAuth2 Client
token-storage boundary and encrypted at rest using Easy Encryption.

Plaintext delegated tokens must not be persisted in configuration, database
fields, logs, or repository files.

Production private decryption material must remain outside both the database
and repository.

If a refresh response omits a replacement refresh token, the existing refresh
token remains authoritative and must be retained. A provider-supplied explicit
replacement may replace it.

## Lifecycle

Visible product states are:

- `NOT_CONNECTED`;
- `CONNECTED`;
- `INVALID`.

Visible product actions are:

- Connect when not connected;
- Reconnect only when invalid;
- Disconnect when connected or invalid.

Disconnect attempts provider revocation, but local cleanup does not depend on
remote revocation success. Local encrypted token material, connection metadata,
and ephemeral authorization context are always cleared.

The UI must not claim successful provider-side revocation when that result is
unknown.

## Explicit exclusions

#84 does not authorize:

- real Google credentials, accounts, tokens, or OAuth runtime proof;
- Google PHP SDK;
- calendar event list/read/create/update/delete;
- `CalendarProjectionCandidate` egress;
- calendar write authority;
- event-ID persistence or mapping;
- synchronization engine;
- queue;
- Microsoft;
- CalDAV;
- MCP;
- Flowdrop;
- AI;
- Playwright;
- a new CI workflow or gate.
