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
an independent generated password unless a password is configured. An application
`<PREFIX>_PASSWORD` (such as `DB_PASSWORD`) takes precedence over the provider-wide
`database_password` fallback; profile application values override project values.
The selected password is sent unchanged to creation and application delivery.
Explicit per-database passwords must be nonempty strings; invalid values fail
before provisioning. Database names, users and hosts remain declaration/provider
controlled.
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

### SDK transport boundaries

The provider's Forge SDK wrapper disables redirects on every request, applies the
configured timeout, and handles non-2xx statuses before interpreting successful
response data. It retains the SDK's exception types (including certificate 404
absence handling) while replacing server error bodies with a status-only message.
Numeric rate-limit reset information is preserved. Transport errors expose a
fixed diagnostic without raw request URLs or underlying exception messages.
Successful responses and application logs are not automatically redacted.

`python3 tests/http/test_responses.py` exercises the actual Guzzle/SDK path using
synthetic credentials and loopback HTTP. It covers GET/POST across five redirect
and eight error statuses with JSON/text bodies, verifies no redirect endpoint is
contacted, and checks successful decoding. The original wrapper followed a 301
and exposed a credential echoed in a 400 error; the fixed wrapper rejects both.
This runs in CI and does not establish live Forge API compatibility.


### Distribution archive checks

Composer archive exclusions and Git export attributes omit installed dependencies,
tests, workflow files, root environment/auth configuration, and PHPUnit caches and
configuration. Runtime source, package metadata, documentation, and assets remain
available. `python3 tests/package/verify-dist.py` checks Composer and Git exports,
then loads all declared runtime classes from an extracted ZIP with an overridden
class map. Dependency classes come from the installed checkout; this check does
not perform a fresh production dependency install or live deployment. CI runs it
with the existing provider tests. Git export verification uses current worktree
attributes; committed attributes determine subsequent GitHub source archives.

### Inventory JSON shape checks

The protected collection endpoints validate JSON before associative-array
normalization: the envelope must be an object, `data` an array, and each record
an object. Explicit error fields or an unsuccessful `success` flag reject the
read. If supplied, `meta` must be an object and `next_cursor` either null or a
nonempty trimmed string. This preserves the distinction between `data: []`
(valid empty inventory) and `data: {}` (invalid shape). Missing optional metadata
remains supported. The guard applies to site, database, background-process,
scheduled-job and domain collections; plaintext responses outside those
endpoints retain their existing behavior. Diagnostics never echo the API error
payload. These checks do not establish a remote snapshot or live compatibility.

The provider adapter also detects repeated cursors within each inventory
traversal, rejecting self-loops and longer cycles without returning accumulated
partial records. Unique cursor chains, including empty intermediate pages,
remain supported. Cursor tracking is local to each read and diagnostics do not
echo cursor contents. The SDK itself is unchanged; direct SDK `lazy()` consumers
outside the provider adapter do not gain this protection. A server that emits
unbounded unique cursors is not bounded by this cycle check, and inventory reads
still do not provide transactional consistency.

Provider inventory reads reject repeated resource IDs across all cursor pages.
Site discovery also rejects duplicate site names regardless of letter case,
so ambiguous records cannot be selected as a first match. Separate workload
records may share a command; uniqueness is based on their IDs. Invalid reads
throw before the accumulated inventory is returned. This is observation-time
validation and does not lock or snapshot the remote account.

Raw collection IDs are checked before SDK hydration: only positive integers or
canonical positive decimal strings within PHP's integer range are accepted.
Fractions, booleans, exponents, whitespace and trailing text cannot become valid
IDs through coercion. If JSON API attributes also contain an ID, it must be
valid and equal to the top-level identity before the SDK flattens attributes.
This protects the collection endpoints; detail/create responses remain subject
to their own resource checks and are not covered by this collection guard.

Create responses for sites, databases, domains, background processes, scheduled
jobs and certificates, plus active certificate/detail lookups, validate the
original JSON object and ID before SDK hydration. Explicit API errors,
nonobject data, malformed IDs and conflicting attribute IDs are rejected.
Protected detail responses must match the ID in the requested URL. Certificate
404 handling remains the sole absence signal. Validation failures do not prove
a create request had no effect: inspect the account before retrying a creation.
This validates response identity, not full requested configuration or completed
provisioning. Other endpoint families retain their existing checks.

Site creation acknowledgements must confirm the requested domain (matching
letter case is not required). Both the API adapter and provider apply path
reject a mismatched or padded returned domain before configuration/deployment;
a valid ID alone is insufficient. Rejection does not delete the returned site
or automatically retry creation. Inspect the account before retrying because
the original request may have created a resource. Domain confirmation does not
prove all settings, ownership tags, provisioning completion or public serving.

### Complete environment input

Existing application environments must parse completely before their database
credentials are trusted. An unfinished double-quoted value at the end of the
file is rejected even if valid credentials appear earlier; phpdotenv otherwise
silently drops that unfinished value. Complete multiline passwords remain
supported. The internal completeness probe is removed from parsed values.

### Database engine selection

Forge's published OpenAPI `CreateDatabaseRequest` supports `name`, `user`,
`password` and backup configuration IDs. Its `user`/`password` fields create a
user alongside the schema; a separate user-create call is not required for this
path. It does not select an engine per schema. Shipper no longer sends a `type`
field to this operation.

Plan and apply inspect the server's `database_type` before authorizing database
work; apply checks before site creation, and database reconciliation checks again
before inventory/creation. The server response must have the requested identity
and a readable engine. Declarations support `mysql`, `mariadb`, and PostgreSQL
aliases `postgres`, `postgresql`, `pgsql`. A MySQL declaration also works with a
MariaDB server through the compatible protocol; an explicit MariaDB declaration
requires MariaDB. PostgreSQL declarations require PostgreSQL. Mismatches and
unsupported engines block deployment rather than silently creating another
engine's schema. Shipper does not change the server engine.

Custom `ForgeCapabilitiesClientInterface` implementations must now implement
`serverDatabaseType(string $serverId): string`, returning the observed Forge
engine identifier (such as `mysql84`, `mariadb114` or `postgres18`). An API read
cannot prevent a later concurrent server change; live connectivity remains a
separate verification step.

API reference: <https://forge.laravel.com/api/docs.openapi> (checked 2026-10-16).

### Partial database provisioning

During normal apply, each confirmed new database connection is delivered to the
site environment before the next database is provisioned. If a later database
fails, already delivered credentials remain available to a fresh apply attempt;
existing unrelated environment values are retained. A failed environment update
stops further creation. This is incremental application state, not an atomic
multi-database transaction: a connection may become visible before later steps
succeed, and earlier resources are not automatically rolled back.

Creation followed by a failed or ambiguous credential-delivery request still
requires recovery review. No durable secret journal or cross-operation lock is
provided by this change. Keep explicitly configured passwords in a secret
manager and do not delete/recreate production data to bypass missing credentials.

### Deployment completion

The official client waits for the deployment ID returned by Forge API v2 rather
than reporting success when a deployment is merely queued. Only `finished` is
successful. `failed`, `failed-build` and `cancelled` fail; `pending`, `queued` and
`deploying` continue polling. Invalid identities/statuses and explicit response
errors fail before being accepted as progress or success.

`deployment_timeout` is an integer from 1 to 3600 seconds, defaulting to 300.
Polling uses a monotonic deadline; responses arriving after expiry cannot report
success. In-flight HTTP requests still obey the SDK transport timeout, and local
timeout does not cancel a remote deployment. Inspect the tracked deployment before
retrying an ambiguous outcome. Live account permissions and timing remain unverified.

Custom `ForgeClientInterface` implementations must make `deploy()` wait for
confirmed completion or throw on failure; returning after enqueueing does not
satisfy this contract. The signature remains `void`. The simulated API tests of
the official client do not verify third-party client implementations.

### Deployment status output

The official client's status result retains only the JSON API resource ID/type
and `attributes.status`/`attributes.started_at`, omitting raw response extras.
Missing or explicitly failed envelopes and invalid state shapes fail before the
SDK can default them to empty data. The documented nullable status is retained;
it does not mean completion. Timestamp/shape checks follow the current
[Forge OpenAPI contract](https://forge.laravel.com/api/docs.openapi).

Consumers depending on arbitrary status resource fields must stop doing so.
Explicit log/output methods are unchanged and may return sensitive application
output; this status boundary does not sanitize third-party client implementations.
