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
| `maidemde/typovigil-sitepackage` | this hub | frontend for customer access (felogin) |
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

## Local development

```bash
ddev start
ddev exec vendor/bin/typo3 extension:setup
```

Run the self-checks:

```bash
ddev exec php packages/typovigil/Tests/SeverityResolverTest.php
ddev exec php packages/typovigil/Tests/autoload-check.php
```

## Configuration files

`config/system/settings.php` is not versioned — it holds the encryption key and
the local database credentials.

`config/system/production.php` is versioned and carries the Coolify/Docker
configuration (database from `TYPO3_DATABASE_*`, reverse proxy settings). It is
loaded from `additional.php` and skips itself inside ddev.

The `#ddev-generated` marker was removed from `additional.php` on purpose so
ddev no longer overwrites it; its ddev block is still intact.

## Registering a project

1. Backend → list module → create a record of type *Monitored project*
2. On save the access token is shown **once** in a flash message. Only its hash
   is stored; it cannot be retrieved later.
3. Enter that token, plus this hub's URL, in the agent extension configuration
   of the instance to be monitored.
