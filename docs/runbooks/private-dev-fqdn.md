# Private DEV HTTP Host contract — #235 / R8 static candidate

**Scope:** Product-owned, DRAFT PR only. This is a proposed static contract; it is **not** real private HTTPS, a browser acceptance, a DDEV runtime reload, or Google authorization.

## Chosen architecture — direct canonical DEV web upstream

Future independently authorized route:

```text
Browser / authorized NetBird peer
  -> https://ps-dev.internal.emergingdigital.be:8443
  -> DEV-only host-user Caddy bound to 100.74.44.220:8443
  -> http://127.0.0.1:33180 (WSL host network namespace)
  -> DDEV personal-secretary WEB container, port 80
  -> Drupal DEV
```

The WSL host's `127.0.0.1:33180` port was verified read-only under R7 to belong exclusively to the canonical rootless `ddev-personal-secretary-web` container, bound to WSL loopback (Docker `127.0.0.1:33180 -> 80/tcp`). The web container mounts the canonical Personal Secretary DEV checkout. These are **observed DEV port settings**, not portable CI port numbers or evidence of Windows port-forwarding isolation. A future operator must independently check the Windows/LAN/NetBird exposure boundary. Host-user Caddy must operate in the **same WSL host network namespace**; container-local `localhost` is not the host's `localhost`.

`.ddev/config.yaml` intentionally does **not** register `ps-dev.internal.emergingdigital.be` as `additional_fqdns` or a shared DDEV-router alias. This avoids later accidental Windows `hosts` overrides for the FQDN that should resolve **privately to NetBird 100.74.44.220**, not to Windows localhost. `personal-secretary.ddev.site` continues to use the existing DDEV router. No Windows `hosts` mapping, DNS/NetBird/TLS/Caddy service, firewall rule or public listener is provisioned here.

`web/sites/default/settings.php` is **unchanged**: inside `IS_DDEV_PROJECT=true` the exact Drupal trusted hosts remain `^.+\\.ddev\\.site$`, `^localhost$` and `^ps-dev\\.internal\\.emergingdigital\\.be$`. Outside DDEV no private trusted-host pattern or broad proxy trust is added. An unrecognized Host must be rejected by Drupal.

## Actual R7 read-only HTTP evidence

On the **canonical** DEV WSL host, using anonymous GET `/fr/personal-secretary/calendar/google`, explicit Host header, no cookies/auth and no redirect follow:

| Host | Direct WEB 127.0.0.1:33180 | Shared router 127.0.0.1:33380 |
| --- | --- | --- |
| `personal-secretary.ddev.site` | **403** | **403** |
| `ps-dev.internal.emergingdigital.be` | **403** | **404** |
| `unrelated.invalid` | **400** | **404** |

The direct web's **400** is Drupal trusted-host rejection; **404** is the separate shared-router negative control. A direct-web 403 means the protected Drupal route is present and denies an anonymous user, not that Google OAuth, login or HTTPS works. R7 had one Google connection CONNECTED, zero sync states and zero external shadows; **no provider call** was made.

## Synthetic GitHub Actions contract

The existing `Drupal` workflow boots isolated DDEV on its ephemeral Linux runner. After its synthetic Drupal bootstrap, it runs `scripts/verify-dev-private-host.php` to prove exact DEV-only/non-DEV trusted-host separation and no shared-router FQDN alias in tracked `.ddev/config.yaml`. For actual direct web requests, CI uses `ddev exec curl ... http://127.0.0.1:80/fr/personal-secretary/calendar/google`: **port 80 inside the running CI project's web container**, not the canonical host's port 33180. It requires anonymous Host **403/403/400**. The runner's DDEV shared router is separately tested for Host **403/404/404** as negative evidence that the private hostname was not registered. The test does not authenticate, send cookies, follow redirects, execute Google APIs, or assert real Windows file state.

Other Drupal, Composer, production trajectory, Git clean-tree and `Governance` checks stay in force. Exact-head natural CI on the R8 branch is needed before independent Project Lead review.

## Infrastructure dependency, trust boundaries and future activation

Infrastructure [#287, PR #289 DRAFT](https://github.com/E-merging-digital/infrastructure/pull/289) **still** pins Product HEAD `b855098dd08159eb14c7c302bf7694aa5b170268`, tree `62c3db148383463cef71f9244f0e5639e20de4ff`, and Caddy upstream `http://127.0.0.1:33380`. Product R7 used merged main HEAD `e3d22e6eab1000872fe4f292b93b28dce6d33f0f`, tree `dcbf6da4d98e740a4d4c5cd8a6643577e6d828b9`. **Only after** a separately authorized R8 merge must the Infrastructure Project Lead independently repin **all** Product SHA/tree references to the **new merged Product main**, switch the reverse-proxy upstream, validator, shell preflight, tests and runbook consistently to `http://127.0.0.1:33180`, and run new exact-head CI. No Infrastructure edit or HTTPS activation is authorized here.

A later HTTPS gate must prove exact NetBird/private DNS/peer access, listener bound only `100.74.44.220:8443`, signed/version-pinned Caddy binary, SAN and browser CA trust, server identity independent of PROD `:443`, and no unintended Windows localhost forwarding or public/LAN exposure. Caddy must preserve intended Host/SNI and external `https` origin with **:8443**. Drupal currently trusts `X-Forwarded-Proto` **only** if `DRUPAL_REVERSE_PROXY_HOST` resolves to explicitly approved proxy IPv4 peers; there is no present proof of correct reverse-proxy peer trust. Do not guess a Docker bridge IP or broadly trust `X-Forwarded-Host`, `X-Forwarded-Port` or arbitrary request IPs. Prove redirects, absolute links, secure host-only `Secure` / `HttpOnly` / `SameSite` cookies, no `Domain=.emergingdigital.be`, no shared PROD keys or sessions, before any human browser login. No original DEV UID1 ULI reuse: the previous DEV/PROD login boundary remains `REVIEW_REQUIRED`.

## Product canonical DEV propagation and rollback — NOT AUTHORIZED

The removal of the **tracked** `additional_fqdns` declaration does **not** prove that the currently running DDEV project has reloaded its routing config. R7 already proved the **direct WEB** Host 403 without DDEV restart. Later approved Product merge/DEV propagation may require a bounded DDEV config reload to retire the old in-memory alias; its actual side effects, host-file owner consent and rollback must be separately reviewed. Never restart the shared router merely for a test, write Windows/WSL hosts or run cron/Google to make the proxy work.

Before a future owner-authorized ingress, capture canonical HEAD/tree, eight original untracked-file digests, scoped ports/web container identity, Google 1 CONNECTED/0 sync/0 shadows and old/new/unknown HTTP evidence. On failed ingress, revert **only** separately approved DEV-only new Caddy user service, private route/DNS/cert and exactly any owner-authorized mapping; preserve PROD, unrelated DDEV services and Google data. Do not roll back approved Product code or run global DDEV cleanup implicitly.

Product [#231](https://github.com/E-merging-digital/personal-secretary/issues/231) remains **STOP**: no OAuth, login, ULI, actualiser/refresh, Google API, cron, queue or fixture mutation before new date-aware Product authority.

**R8 gate:** isolated Product branch + DRAFT PR only; no merge, canonical DEV propagation or Infrastructure action.
