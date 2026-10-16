# Shipper CLI Provider Forge

![Shipper Banner](https://raw.githubusercontent.com/shippercli/assets/main/banner.png)

Laravel Forge provider plugin for Shipper CLI.

> **Status: capability-aware.** Site creation and deployment use the current
> Forge API v2 through an injectable client. The site source must be configured
> in Forge because API v2 removed Git repository mutation endpoints. Apply now
> provisions configured databases, environment variables, queue workers,
> scheduled jobs, and certificates when those resources are present in the
> project configuration. Destruction remains ownership-safe and refuses to
> delete a site unless its `shipper-managed` tag is present. Server lifecycle
> and deployment rollback are not exposed by the Forge API v2 contract.

Owned preview sites are exposed to Shipper's orphan-cleanup flow and can be
removed only when the ownership tag is present. Forge API v2 does not expose a
safe association between server-level databases and sites, so preview cleanup
does not delete databases automatically.

## Installation

```bash
composer global require shippercli/provider-forge
```

## Requirements

- PHP ^8.3
- Shipper CLI
- Laravel Forge account with an API v2 organization slug

Configure `api_token`, `organization_slug`, and `server_id`, plus
`ownership_tag`. The default ownership tag is `shipper-managed`; do not remove
it from a Shipper-managed site if cleanup is required.

## Site ownership scope

New sites receive a project/profile-scoped ownership tag. The tag is the
configured `ownership_tag` prefix (default `shipper-managed`), a hyphen, and the
first 20 hexadecimal characters of the SHA-256 hash of the JSON-encoded
`[projectName, profileName]` pair:

```php
$tag = $prefix.'-'.substr(hash('sha256', json_encode([$projectName, $profileName], JSON_THROW_ON_ERROR)), 0, 20);
```

Apply, destroy, and preview cleanup require this exact tag. The old generic
`shipper-managed` tag is insufficient because it does not establish which
deployment owns the site. Existing generic-tagged sites fail closed until an
operator reviews the site and explicitly adds the appropriate scoped tag through
Forge. Review the server, domain, project, and profile before migrating a tag;
the provider does not automatically adopt or retag existing sites.

## Database connection environments

A single new database supplies `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, and
`DB_PASSWORD`. For multiple databases, map every declared database name to a
distinct uppercase environment prefix in the provider configuration:

```yaml
database_environment_prefixes:
  main: DB
  analytics: ANALYTICS_DB
```

The second database supplies `ANALYTICS_DB_HOST`, `ANALYTICS_DB_DATABASE`,
`ANALYTICS_DB_USERNAME`, and `ANALYTICS_DB_PASSWORD`. The application must be
configured to consume those variables. `database_host` defaults to `127.0.0.1`;
set it explicitly if the server's database endpoint differs. Each database gets
an independent generated password unless `database_password` is explicitly set.
Re-apply leaves existing database passwords unchanged and merges configured
environment variables into the site's existing environment. Existing database
credentials must already be available in the existing site environment or its
configured overrides. Reuse requires a matching database name and username, a
host, and a nonempty password. Missing, mismatched, or malformed connection
settings block deployment without creating a database or rotating its password.

## License

MIT

### Plan ownership inspection

Planning reads Forge site inventory and requires API access. A matching owned
site is reported as reuse; a missing site is reported as create. An unmanaged
or differently scoped collision, or an inventory read failure, produces
`blocked: true` and a single blocked action instead of deployment actions.
Planning performs no writes. Database and workload reconciliation prerequisites
still require separate validation during apply.
