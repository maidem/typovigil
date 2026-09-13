# TypoVigil

Central dashboard for TYPO3 update and security monitoring.

Each monitored TYPO3 instance runs a small agent extension that reports its
installed core version and extensions to this hub. The hub matches those
against the official sources and shows what needs updating — and which of those
updates close a known security hole.

## Parts

| Package | Installed on | Purpose |
| --- | --- | --- |
| `maidemde/typovigil` | this hub | data model, report endpoint, upstream matching, backend module |
| `maidemde/typovigil-sitepackage` | this hub | frontend portal for customer access (felogin) |
| `maidemde/typovigil-agent` | every monitored instance | reports its package list |

The agent pushes; the hub never reaches into a monitored instance. Those
installations therefore need no publicly reachable endpoint of their own.

## Sources

| Source | Used for |
| --- | --- |
| `get.typo3.org/json` | TYPO3 core releases, including which ones are security releases |
| `repo.packagist.org/p2/{vendor}/{package}.json` | newest version of a Composer package |
| `packagist.org/api/security-advisories/` | known CVEs (aggregates the FriendsOfPHP database) |
| `extensions.typo3.org/api/v1/extension/{key}` | fallback for TER-only extensions without a Composer name |

## Severity

| Level | Meaning |
| --- | --- |
| `critical` | an advisory covers the installed version, or a newer core security release exists |
| `outdated` | a newer version is available, no known advisory |
| `ok` | up to date |

## Two views, on purpose different

The **backend module** (Site → TypoVigil) lists every package with its installed
and available version — this is the agency's view.

The **frontend portal** shows customers a traffic light and counts, but not the
package list. A complete inventory of vulnerable versions is an attack plan once
a customer account is compromised.

Customers only ever see projects assigned to them in the project record's
*Access* tab. The filter lives in `ProjectRepository`, and the controller checks
it again for the detail view: the project uid arrives as a URL argument, so page
permissions alone would not stop someone editing it.

## Local development

```bash
ddev start
ddev exec vendor/bin/typo3 extension:setup
```

Checks:

```bash
ddev exec php packages/typovigil/Tests/SeverityResolverTest.php
ddev exec php packages/typovigil/Tests/access-separation-check.php
ddev exec php packages/typovigil/Tests/config-check.php
ddev exec php packages/typovigil/Tests/autoload-check.php
```

## Registering a project

1. Backend → list module → create a record of type *Monitored project*
2. On save the access token is shown **once** in a flash message. Only its hash
   is stored; it cannot be retrieved later.
3. Enter that token, plus this hub's URL, in the agent extension configuration
   of the instance to be monitored.
4. Assign the frontend users who may see it in the *Access* tab.

## Production

Built by GitHub Actions into `ghcr.io/maidem/typovigil`, deployed on Coolify at
`https://typovigil.maidem.de`.

The Coolify deployment job is skipped until the repository variable
`TAILSCALE_READY` is set to `yes` and the two `TAILSCALE_OAUTH_*` secrets hold
real values — Coolify is only reachable inside the tailnet. Until then, deploy
by triggering it in Coolify directly; the image build runs regardless.

### Configuration files

`config/system/settings.php`, `additional.php` and `production.php` are all
versioned. They hold no secrets: the encryption key and install tool password
are read from the environment (`TYPO3_ENCRYPTION_KEY`,
`TYPO3_INSTALL_TOOL_PASSWORD_HASH`), the database credentials from
`TYPO3_DATABASE_*`.

`additional.php` no longer carries ddev's `#ddev-generated` marker, so ddev
leaves it alone; its ddev block still works and `production.php` skips itself
inside ddev.

### Things that cost time once

- **`extension:setup` rewrites `settings.php`** and bakes resolved `getenv()`
  values in as literals. After running it, check that the key and password are
  still read from the environment.
- **`AllowOverride None`** in the `php:apache` base image means
  `public/.htaccess` is never evaluated. The rules live in
  `/etc/apache2/conf-available/typo3.conf` instead, set up in the Dockerfile.
  `CGIPassAuth On` is part of that: without it the `Authorization` header never
  reaches `$_SERVER`, and every bearer token is rejected as invalid.
- **`/public/*` in `.gitignore`** excluded the Composer-generated `index.php`
  from the image, leaving Apache with nothing to serve.
- **An empty database** makes `extension:setup` fail with a confusing
  `TcaSchemaFactory` error. The entrypoint now runs `typo3 setup` first when
  `be_users` is missing.
- **`tx_typovigil_package` has no TCA**, so TYPO3 gives it no `uid` column.
  Rows are addressed by `(project, composer_name)`.
