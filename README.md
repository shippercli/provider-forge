# Shipper CLI Provider Forge

![Shipper Banner](https://raw.githubusercontent.com/shippercli/assets/main/banner.png)

Laravel Forge provider plugin for Shipper CLI.

> **Status: capability-aware.** Site creation and deployment use the current
> Forge API v2 through an injectable client. The site source must be configured
> in Forge because API v2 removed Git repository mutation endpoints. Apply now
> provisions configured databases, environment variables, queue workers,
> scheduled jobs, certificates, PHP versions, and NGINX configuration when
> those resources are present in the project configuration. Destruction remains
> ownership-safe and refuses to delete a site unless its `shipper-managed` tag
> is present. Server lifecycle can create, reuse, and optionally clean up
> servers carrying the `shipper-managed-server` ownership tag. Deployment
> rollback remains outside this provider contract.

## Installation

```bash
composer global require shippercli/provider-forge
```

## Requirements

- PHP ^8.3
- Shipper CLI
- Laravel Forge account with an API v2 organization slug

Configure `api_token`, `organization_slug`, and either `server_id` or a
`server` lifecycle map. Lifecycle-created servers receive the
`shipper-managed-server` tag and are deleted only when `cleanup: true`; site
cleanup still uses the `shipper-managed` tag.

## License

MIT
