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
credentials must already be available to the application.

## License

MIT
