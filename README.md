# Shipper CLI Provider Forge

![Shipper Banner](https://raw.githubusercontent.com/shippercli/assets/main/banner.png)

Laravel Forge provider plugin for Shipper CLI.

> **Status: partial.** Site creation and deployment use the current Forge API
> v2 through an injectable client. The site source must be configured in Forge
> because API v2 removed Git repository mutation endpoints. Destruction is
> ownership-safe and refuses to delete a site unless its `shipper-managed` tag
> is present. Databases, server lifecycle, rollback, and observability remain
> unsupported.

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

## License

MIT
