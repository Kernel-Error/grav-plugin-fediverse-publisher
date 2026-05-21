# Security policy

## Supported versions

This plugin is currently in **pre-1.0 development**. Only the most
recent tagged release receives security fixes; older pre-releases
will not get patched.

Once v1.0 ships, the supported-version policy will be revisited.

## Reporting a vulnerability

The `/activitypub/inbox` endpoint accepts unauthenticated POSTs from
the internet and runs HTTP-signature verification on them — that is
the most security-sensitive surface in this plugin. Please do not
file public issues for vulnerabilities in that path.

**Private disclosure:** email <kernel-error@kernel-error.com> with
"fediverse-publisher" in the subject. PGP is welcome — fingerprint at
<https://www.kernel-error.de/>.

I aim to acknowledge within 5 working days and to coordinate a fix +
disclosure timeline from there. There is no bounty program; this is a
hobby project.

## Out of scope

- Attacks that require an already-compromised Grav admin account.
- Self-DoS via misconfiguration (e.g. an admin enabling the plugin
  without TLS).
- Federation oddities caused by remote-server bugs that are
  internally consistent on our side (file an issue, not an advisory).
