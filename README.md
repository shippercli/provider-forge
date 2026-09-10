# Shipper CLI Provider Forge

![Shipper Banner](https://raw.githubusercontent.com/shippercli/assets/main/banner.png)

Laravel Forge provider plugin for Shipper CLI.

> **Status: partial.** The provider is discoverable and validates Forge
> configuration, but deployment mutations are deliberately unsupported until
> Forge API execution is implemented. `apply` and `destroy` return an explicit
> error rather than reporting a successful deployment.

## Installation

```bash
composer global require shippercli/provider-forge
```

## Requirements

- PHP ^8.3
- Shipper CLI
- Laravel Forge account

## License

MIT
