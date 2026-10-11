# Private DEV FQDN admission — #235

**R1 is a static candidate only.** No real DDEV settings propagation, browser session, DNS/NetBird policy, TLS CA trust, cache rebuild, production changes or Google refresh.

## Intended DEV-only host

`ps-dev.internal.emergingdigital.be` is added as one exact `additional_fqdns` entry in `.ddev/config.yaml`. DDEV v1.25.4 supports `additional_fqdns`, distinct from `additional_hostnames` (the latter automatically appends `.ddev.site`). This does not establish private DNS or TLS trust. DDEV can update its operator's `/etc/hosts` during a later `ddev start`; the future canonical DEV propagation and any host-file effects require a separate Project Lead/operator approval. Existing `personal-secretary.ddev.site` and `localhost` must remain accessible.

The Drupal trusted-host expression `^ps-dev\\.internal\\.emergingdigital\\.be$` belongs **only** inside `IS_DDEV_PROJECT=true`. Outside DDEV the settings do not gain a new trusted-host pattern, proxy IP, cookie domain or route.

## Synthetic CI acceptance

Existing GitHub Actions `Drupal` workflow already provisions DDEV 1.25.4 on ephemeral GitHub runners. Immediately after the synthetic development bootstrap, it executes `scripts/verify-dev-private-host.php` to assert the exact DDEV allowlist, original host acceptance, spoofed/unrelated Host denial, and no DEV-only trusted-host leakage into non-DDEV settings. Then it checks the DDEV router with anonymous HTTP only: canonical `.ddev.site` returns 403, new FQDN returns 403, and unrelated Host returns 404 for the existing protected Google status path. No Google API, login, ULI, OAuth, credentials or real user data are involved. A GitHub-runner synthetic success does not imply canonical DEV runtime propagation.

## Infrastructure dependency and real HTTPS

Infrastructure [#287 / draft PR #289](https://github.com/E-merging-digital/infrastructure/pull/289) has exact **old Product main pins**: HEAD `b855098dd08159eb14c7c302bf7694aa5b170268`, tree `62c3db148383463cef71f9244f0e5639e20de4ff` in its contract, shell preflight, validator and runbook. On a separately approved future **Product merge**, Infrastructure must re-pin **every occurrence** to the *new merged Product main HEAD/tree* and rerun exact-head natural CI; never loosen guards or silently deploy against an unapproved revision.

The proposed private origin is `https://ps-dev.internal.emergingdigital.be:8443`. DDEV's router needs to accept its exact Host before the DEV-owned Caddy ingress can be started, with private DNS/NetBird TCP8443, exact SAN, approved CA trust and systemd sandbox covered by later Infrastructure authority. Drupal already configures `DRUPAL_REVERSE_PROXY_HOST` opt-in to trust **X-Forwarded-Proto only** from explicitly resolved proxy IPv4 addresses. This R1 does not guess any new proxy address or broaden X-Forwarded-Host/Port trust. The Infrastructure proxy must forward the real Host, maintain the external :8443 origin semantics and prove correct redirects, scheme, port and host-only cookie scope with a future authorized human browser test. A mismatched generated URL or trust boundary is an R5 STOP, not grounds for implicit broadening.

## Future canonical DEV handoff and rollback (NOT executed)

1. New explicit Product Project Lead authority after PR review and human merge decision; record original canonical DEV git HEAD/tree, tracked worktree, exact DDEV config and protected-route behavior before materializing.
2. Confirm only the approved new FQDN is admitted and DDEV's local hosts-file side effects and router restart are understood; preserve old `.ddev.site` paths. Avoid DNS/hosts/NetBird/TLS mutation without its owner authority.
3. Confirm anonymous new Host status is exactly 403 and original DDEV Host still 403; unrelated Host 404. Fail closed on unexpected external redirects, incorrect backend, or PROD access.
4. If failed, restore only the authorized new DEV FQDN/settings additions to pre-change state through separately approved operator commands, check that DDEV's generated hostname mapping is restored and verify pre-existing Host remains available. No PROD rollback actions.
5. Revalidate Product main HEAD/tree and update Infra PR #289 pinned dependency separately before any authorized HTTPS runtime activation. Personal Secretary #231 remains STOP; old dated Google test authorization is expired.

**R1:** Branch + DRAFT PR only, no merge, no canonical DEV propagation, no infrastructure HTTPS start, no Google action.
