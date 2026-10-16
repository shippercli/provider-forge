<?php

declare(strict_types=1);

namespace ShipperCli\ProviderForge;

use RuntimeException;
use Laravel\Forge\Exceptions\ValidationException;
use ShipperCli\Contracts\DeploymentLogsProviderInterface;
use ShipperCli\Contracts\DeploymentProviderInterface;
use ShipperCli\Contracts\DeploymentStatusProviderInterface;
use ShipperCli\Contracts\ProviderCapabilitiesInterface;
use Throwable;

final class ForgeProvider implements DeploymentLogsProviderInterface, DeploymentProviderInterface, DeploymentStatusProviderInterface, ProviderCapabilitiesInterface
{
    /** @var array<string, mixed> */
    private readonly array $config;

    private string $lastError = '';

    /** @var array<string, string> */
    private array $generatedEnvironment = [];

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [], private readonly ?ForgeClientInterface $client = null)
    {
        $this->config = $config;
    }

    public function getName(): string
    {
        return 'forge';
    }

    public function capabilities(): array
    {
        return [
            'app_deploy' => ['state' => 'partial', 'limitations' => ['Forge API v2 can create and trigger a site, but repository installation must be configured in Forge because the v2 API removed Git mutation endpoints.']],
            'server_lifecycle' => ['state' => 'unsupported'],
            'domain_management' => ['state' => 'supported'],
            'ssl' => ['state' => 'supported'],
            'databases' => ['state' => 'supported'],
            'profiles' => ['state' => 'supported'],
            'background_workloads' => ['state' => 'supported'],
            'env' => ['state' => 'supported'],
            'observability' => ['state' => 'supported'],
            'rollback' => ['state' => 'unsupported'],
            'previews' => ['state' => 'partial', 'limitations' => ['Owned preview sites can be enumerated and removed, but Forge API v2 does not expose a safe database ownership association for automatic database cleanup.']],
        ];
    }

    public function validate(object $project, object $profile): array
    {
        $errors = [];
        try {
            $this->databaseEnvironmentPrefixes($project, $profile);
            $this->configuredDatabasePasswords($project, $profile);
        } catch (\InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        }

        if (! isset($this->config['api_token']) || $this->config['api_token'] === '') {
            $errors[] = 'Forge API token is required';
        }

        if (! isset($this->config['server_id']) || $this->config['server_id'] === '') {
            $errors[] = 'Forge server ID is required';
        }

        if (! isset($this->config['organization_slug']) || $this->config['organization_slug'] === '') {
            $errors[] = 'Forge organization slug is required for API v2';
        }

        $domain = $this->profileValue($profile, 'domain');
        if (! is_string($domain) || $domain === '') {
            $errors[] = 'Domain is required for profile';
        }

        return $errors;
    }

    public function plan(object $project, object $profile): array
    {
        $this->databaseEnvironmentPrefixes($project, $profile);
        $this->configuredDatabasePasswords($project, $profile);
        $serverId = $this->getServerId();
        $domainValue = $this->profileValue($profile, 'domain');
        $domain = \is_string($domainValue) ? $domainValue : '';

        $projectName = method_exists($project, 'name') ? $project->name() : 'unknown';
        $profileName = method_exists($profile, 'name') ? $profile->name() : 'unknown';
        $branch = method_exists($profile, 'branch') ? $profile->branch() : 'main';

        $blocked = null;
        $site = null;
        try {
            $this->assertDatabaseServer($project);
            $site = $this->findSite($serverId, $domain);
            if ($site !== null && ! $this->isOwnedSite($site, $project, $profile)) {
                $blocked = 'Existing Forge site lacks matching project/profile ownership. Review its identity and migrate ownership explicitly.';
            }
        } catch (Throwable) {
            $blocked = 'Unable to verify Forge site ownership or database engine. Check API access before planning changes.';
        }
        if ($blocked !== null) {
            return [
                'provider' => $this->getName(), 'project' => $projectName,
                'profile' => $profileName, 'branch' => $branch,
                'server_id' => $serverId, 'domain' => $domain,
                'blocked' => true,
                'actions' => ['BLOCKED: '.$blocked],
                'note' => 'No deployment actions are authorized by this plan.',
            ];
        }

        $actions = [$site === null ? "Create site for domain: {$domain}" : "Reuse owned site for domain: {$domain}"];

        if (method_exists($project, 'databases')) {
            $databases = $project->databases();
            if (! empty($databases)) {
                foreach ($databases as $database) {
                    $dbName = $this->interpolateDatabaseName($database->name(), $projectName, $profileName);
                    $actions[] = "Create database: {$dbName}";
                }
            }
        }

        if (method_exists($project, 'environment') || method_exists($profile, 'environment')) {
            $actions[] = 'Merge environment variables into the Forge site environment';
        }

        if (method_exists($project, 'queues')) {
            foreach ($project->queues() as $name => $queue) {
                if (! method_exists($queue, 'enabled') || $queue->enabled()) {
                    $actions[] = "Create or reuse queue worker: {$name}";
                }
            }
        }

        if (method_exists($project, 'cron')) {
            foreach ($project->cron() as $name => $cron) {
                if (! method_exists($cron, 'enabled') || $cron->enabled()) {
                    $actions[] = "Create or reuse scheduled job: {$name}";
                }
            }
        }

        if (method_exists($project, 'ssl') && $project->ssl()->enabled()) {
            $actions[] = 'Create or reuse a Let\'s Encrypt certificate';
        }

        $actions[] = 'Deploy site via Forge API';

        return [
            'provider' => $this->getName(),
            'project' => $projectName,
            'profile' => $profileName,
            'branch' => $branch,
            'server_id' => $serverId,
            'domain' => $domain,
            'blocked' => false,
            'actions' => $actions,
            'note' => 'Forge API v2 deploys the source already configured on the Forge site; Shipper does not mutate repository or branch settings.',
        ];
    }

    public function apply(object $project, object $profile): bool
    {
        $this->generatedEnvironment = [];
        $errors = $this->validate($project, $profile);
        if ($errors !== []) {
            $this->lastError = implode('; ', $errors);
            return false;
        }

        try {
            $serverId = $this->getServerId();
            $this->assertDatabaseServer($project);
            $domain = (string) $this->profileValue($profile, 'domain');
            $site = $this->findSite($serverId, $domain);
            if ($site === null) {
                $site = $this->forgeClient()->createSite($serverId, [
                    'domain' => $domain,
                    'project_type' => method_exists($project, 'type')
                        ? (string) $project->type()
                        : (string) ($this->config['project_type'] ?? 'php'),
                    'tags' => [$this->ownershipTag($project, $profile)],
                ]);
                $returnedDomain = $site['name'] ?? $site['domain'] ?? null;
                if (! \is_string($returnedDomain) || \trim($returnedDomain) !== $returnedDomain
                    || \strcasecmp($returnedDomain, $domain) !== 0
                    || (isset($site['domain']) && (! \is_string($site['domain']) || \strcasecmp($site['domain'], $domain) !== 0))) {
                    throw new RuntimeException('Forge created site did not confirm the requested domain.');
                }
            } elseif (! $this->isOwnedSite($site, $project, $profile)) {
                throw new RuntimeException('Forge site exists but is not owned by Shipper; refusing to modify it');
            }
            $siteId = $this->siteId($site);
            if ($siteId === null) {
                throw new RuntimeException('Forge returned a site without an ID');
            }
            $this->applyCapabilities($project, $profile, $serverId, $siteId, $domain);
            $this->forgeClient()->deploy($serverId, $siteId);
            $this->lastError = '';
            return true;
        } catch (Throwable $exception) {
            $this->lastError = $exception instanceof ValidationException
                ? $exception->getMessage().' '.json_encode($exception->errors(), JSON_UNESCAPED_SLASHES)
                : $exception->getMessage();
            return false;
        }
    }

    public function destroy(object $project, object $profile): bool
    {
        try {
            $domain = $this->profileValue($profile, 'domain');
            if (! is_string($domain) || $domain === '') {
                throw new RuntimeException('Domain is required for Forge destroy');
            }
            $site = $this->findSite($this->getServerId(), $domain);
            if ($site === null) {
                $this->lastError = '';
                return true;
            }
            if (! $this->isOwnedSite($site, $project, $profile)) {
                throw new RuntimeException('Forge site is not owned by Shipper; refusing to destroy it');
            }
            $siteId = $this->siteId($site);
            if ($siteId === null) {
                throw new RuntimeException('Forge returned a site without an ID');
            }
            $this->forgeClient()->deleteSite($this->getServerId(), $siteId);
            $this->lastError = '';
            return true;
        } catch (Throwable $exception) {
            $this->lastError = $exception->getMessage();
            return false;
        }
    }

    /** @return array<int, array{site_id: int, domain: string}> */
    public function listSites(object $project, object $profile): array
    {
        $sites = [];
        foreach ($this->forgeClient()->sites($this->getServerId()) as $site) {
            if (! $this->isOwnedSite($site, $project, $profile)) {
                continue;
            }

            $siteId = $this->siteId($site);
            $domain = $site['name'] ?? ($site['domain'] ?? null);
            if ($siteId === null || ! \is_string($domain) || $domain === '' || ! \ctype_digit($siteId)) {
                continue;
            }

            $sites[] = ['site_id' => (int) $siteId, 'domain' => $domain];
        }

        return $sites;
    }

    public function deleteSiteWithDatabases(object $project, object $profile, int $siteId): bool
    {
        foreach ($this->forgeClient()->sites($this->getServerId()) as $site) {
            if ($this->siteId($site) !== (string) $siteId) {
                continue;
            }
            if (! $this->isOwnedSite($site, $project, $profile)) {
                $this->lastError = 'Forge site is not owned by Shipper; refusing to destroy it';

                return false;
            }

            $this->forgeClient()->deleteSite($this->getServerId(), (string) $siteId);
            $this->lastError = '';

            return true;
        }

        $this->lastError = '';

        return true;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function getServerId(): string
    {
        $serverId = $this->config['server_id'] ?? '';
        if (\is_string($serverId)) {
            return $serverId;
        }
        if (\is_int($serverId)) {
            return (string) $serverId;
        }

        return '';
    }

    /** @return array<string, mixed> */
    public function status(object $project, object $profile): array
    {
        $site = $this->findSite($this->getServerId(), (string) $this->profileValue($profile, 'domain'));
        $siteId = $site === null ? null : $this->siteId($site);
        if ($siteId === null || ! $this->forgeClient() instanceof ForgeCapabilitiesClientInterface) {
            return [];
        }

        return $this->forgeCapabilitiesClient()->status($this->getServerId(), $siteId);
    }

    /** @return array<int, string> */
    public function logs(object $project, object $profile, int $lines = 100): array
    {
        $site = $this->findSite($this->getServerId(), (string) $this->profileValue($profile, 'domain'));
        $siteId = $site === null ? null : $this->siteId($site);
        if ($siteId === null || ! $this->forgeClient() instanceof ForgeCapabilitiesClientInterface) {
            return [];
        }

        $log = $this->forgeCapabilitiesClient()->applicationLog($this->getServerId(), $siteId);

        return array_slice(preg_split('/\R/', $log) ?: [], -max(1, $lines));
    }

    private function interpolateDatabaseName(string $name, string $projectName, string $profileName): string
    {
        $name = \str_replace('${PROJECT_NAME}', $projectName, $name);
        $name = \str_replace('${PROFILE}', $profileName, $name);

        return $name;
    }

    private function forgeClient(): ForgeClientInterface
    {
        return $this->client ?? new ForgeApiClient(
            (string) $this->config['organization_slug'],
            (string) $this->config['api_token'],
        );
    }

    private function forgeCapabilitiesClient(): ForgeCapabilitiesClientInterface
    {
        $client = $this->forgeClient();
        if (! $client instanceof ForgeCapabilitiesClientInterface) {
            throw new RuntimeException('Forge client does not support capability operations');
        }

        return $client;
    }

    private function applyCapabilities(object $project, object $profile, string $serverId, string $siteId, string $domain): void
    {
        $hasCapabilities = method_exists($project, 'databases')
            || method_exists($project, 'environment')
            || method_exists($profile, 'environment')
            || method_exists($project, 'queues')
            || method_exists($project, 'cron')
            || method_exists($project, 'ssl');
        if (! $hasCapabilities) {
            return;
        }
        $client = $this->forgeCapabilitiesClient();
        $this->applyDatabases($client, $project, $profile, $siteId);
        $this->applyEnvironment($client, $project, $profile, $serverId, $siteId);
        $this->applyQueues($client, $project, $serverId);
        $this->applyCron($client, $project, $serverId);
        $this->applySsl($client, $project, $serverId, $siteId, $domain);
    }

    private function databaseEngine(object $database): string
    {
        $type = method_exists($database, 'type') ? $database->type() : 'mysql';
        return match ($type) {
            'mysql', 'mariadb' => $type,
            'postgres', 'postgresql', 'pgsql' => 'postgres',
            default => throw new \InvalidArgumentException('Forge database declarations require mysql, mariadb or PostgreSQL.'),
        };
    }

    private function assertDatabaseServer(object $project, ?ForgeCapabilitiesClientInterface $client = null): void
    {
        $databases = method_exists($project, 'databases') ? $project->databases() : [];
        if ($databases === []) return;
        $client ??= $this->forgeCapabilitiesClient();
        $actual = $client->serverDatabaseType($this->getServerId());
        $family = match (true) {
            preg_match('/\Amysql[0-9]+\z/', $actual) === 1 => 'mysql',
            preg_match('/\Amariadb[0-9]+\z/', $actual) === 1 => 'mariadb',
            preg_match('/\Apostgres[0-9]+\z/', $actual) === 1 => 'postgres',
            default => throw new RuntimeException('Forge server database engine is unavailable or invalid.'),
        };
        foreach ($databases as $database) {
            $expected = $this->databaseEngine($database);
            if ($family !== $expected && ! ($expected === 'mysql' && $family === 'mariadb')) {
                throw new RuntimeException('Forge database declaration does not match the server database engine.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function parseCompleteEnvironment(string $content): array
    {
        $probe = '__SHIPPER_ENV_END_'.\bin2hex(\random_bytes(16));
        $values = \Dotenv\Dotenv::parse($content."\n".$probe."=1\n");
        if (($values[$probe] ?? null) !== '1') {
            throw new \RuntimeException('Application environment input is incomplete.');
        }
        unset($values[$probe]);
        return $values;
    }

    /** @return array<string, string> */
    private function configuredDatabasePasswords(object $project, object $profile): array
    {
        $configured = $this->configuredEnvironment($project, $profile);
        $passwords = [];
        foreach ($this->databaseEnvironmentPrefixes($project, $profile) as $prefix) {
            $key = $prefix.'_PASSWORD';
            if (! array_key_exists($key, $configured)) {
                continue;
            }
            if (! is_string($configured[$key]) || $configured[$key] === '') {
                throw new \InvalidArgumentException('Explicit Forge database passwords must be nonempty strings.');
            }
            $passwords[$key] = $configured[$key];
        }
        return $passwords;
    }

    private function applyDatabases(ForgeCapabilitiesClientInterface $client, object $project, object $profile, string $siteId = ''): void
    {
        if (! method_exists($project, 'databases')) {
            return;
        }

        $prefixes = $this->databaseEnvironmentPrefixes($project, $profile);
        $configuredPasswords = $this->configuredDatabasePasswords($project, $profile);
        $this->assertDatabaseServer($project, $client);
        $existing = $client->databases($this->getServerId());
        foreach ($project->databases() as $database) {
            if (! method_exists($database, 'name')) {
                continue;
            }
            $name = $this->interpolateDatabaseName($database->name(), $this->projectName($project), $this->profileName($profile));
            $username = method_exists($database, 'user') ? $this->interpolateDatabaseName($database->user(), $this->projectName($project), $this->profileName($profile)) : $name;
            if ($this->resourceByName($existing, $name) !== null) {
                try {
                    $available = [...$this->parseCompleteEnvironment($client->environment($this->getServerId(), $siteId)), ...$this->configuredEnvironment($project, $profile)];
                } catch (\Throwable) {
                    throw new RuntimeException('Cannot read or parse the Forge site environment before reusing an existing database.');
                }
                $prefix = $prefixes[$database->name()];
                if (($available[$prefix.'_DATABASE'] ?? null) !== $name
                    || ($available[$prefix.'_USERNAME'] ?? null) !== $username
                    || ! is_string($available[$prefix.'_HOST'] ?? null) || $available[$prefix.'_HOST'] === ''
                    || ! is_string($available[$prefix.'_PASSWORD'] ?? null) || $available[$prefix.'_PASSWORD'] === '') {
                    throw new RuntimeException("Existing Forge database {$name} requires matching {$prefix} connection settings and a password in the application environment.");
                }
                continue;
            }
            $payload = [
                'name' => $name,
                'user' => $username,
                'password' => $configuredPasswords[$prefixes[$database->name()].'_PASSWORD']
                    ?? (string) ($this->config['database_password'] ?? bin2hex(random_bytes(16))),
            ];
            $created = $client->createDatabase($this->getServerId(), $payload);
            $createdId = $created['id'] ?? null;
            $validId = (is_int($createdId) && $createdId > 0)
                || (is_string($createdId) && preg_match('/\A[1-9][0-9]*\z/', $createdId) === 1
                    && filter_var($createdId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false);
            if (! $validId || ($created['name'] ?? null) !== $name) {
                throw new RuntimeException('Forge did not confirm the requested database creation identity.');
            }
            $prefix = $prefixes[$database->name()];
            $this->generatedEnvironment = [...$this->generatedEnvironment,
                $prefix.'_HOST' => (string) ($this->config['database_host'] ?? '127.0.0.1'),
                $prefix.'_DATABASE' => $name,
                $prefix.'_USERNAME' => (string) $payload['user'],
                $prefix.'_PASSWORD' => (string) $payload['password'],
            ];
            if ($siteId !== '') {
                $client->updateEnvironment($this->getServerId(), $siteId, $this->generatedEnvironment);
            }
        }
    }

    /** @return array<string, string> */
    private function databaseEnvironmentPrefixes(object $project, object $profile): array
    {
        $databases = method_exists($project, 'databases')
            ? $project->databases() : [];
        $configured = $this->config['database_environment_prefixes'] ?? [];
        if (! \is_array($configured)) {
            throw new \InvalidArgumentException('Forge database_environment_prefixes must be a map of database names to environment prefixes.');
        }
        $prefixes = [];
        $resolvedNames = [];
        foreach ($databases as $database) {
            if (! \is_object($database) || ! \method_exists($database, 'name')) {
                throw new \InvalidArgumentException('Forge database declarations require a name.');
            }
            $this->databaseEngine($database);
            $name = $database->name();
            $resolved = $this->interpolateDatabaseName($name, $this->projectName($project), $this->profileName($profile));
            if (\in_array($resolved, $resolvedNames, true)) {
                throw new \InvalidArgumentException('Resolved Forge database names must be unique.');
            }
            $resolvedNames[] = $resolved;
            $prefix = $configured[$name] ?? (\count($databases) === 1 ? 'DB' : null);
            if (! \is_string($prefix) || \preg_match('/\A[A-Z_][A-Z0-9_]*\z/', $prefix) !== 1) {
                throw new \InvalidArgumentException("Forge database {$name} requires a valid database_environment_prefixes entry when multiple databases are declared.");
            }
            if (isset($prefixes[$name]) || \in_array($prefix, $prefixes, true)) {
                throw new \InvalidArgumentException('Forge database names and environment prefixes must be unique.');
            }
            $prefixes[$name] = $prefix;
        }

        return $prefixes;
    }

    private function applyEnvironment(ForgeCapabilitiesClientInterface $client, object $project, object $profile, string $serverId, string $siteId): void
    {
        $variables = $this->configuredEnvironment($project, $profile);
        $variables = [...$variables, ...$this->generatedEnvironment];
        if ($variables !== []) {
            $client->updateEnvironment($serverId, $siteId, $variables);
        }
    }

    /** @return array<string, string> */
    private function configuredEnvironment(object $project, object $profile): array
    {
        $variables = [];
        foreach ([$project, $profile] as $source) {
            if (method_exists($source, 'environment')) {
                $environment = $source->environment();
                if (is_object($environment) && method_exists($environment, 'variables')) {
                    $variables = array_merge($variables, $environment->variables());
                }
            }
        }
        return $variables;
    }

    private function applyQueues(ForgeCapabilitiesClientInterface $client, object $project, string $serverId): void
    {
        if (! method_exists($project, 'queues')) {
            return;
        }
        $existing = $client->backgroundProcesses($serverId);
        foreach ($project->queues() as $name => $queue) {
            if (method_exists($queue, 'enabled') && ! $queue->enabled()) {
                continue;
            }
            $connection = method_exists($queue, 'connection') ? $queue->connection() : 'database';
            $queueName = method_exists($queue, 'queue') ? $queue->queue() : 'default';
            $command = "php artisan queue:work {$connection} --queue={$queueName}";
            if ($this->resourceByCommand($existing, $command) !== null) {
                continue;
            }
            $client->createBackgroundProcess($serverId, [
                'name' => (string) $name,
                'command' => $command,
                'user' => 'forge',
                'processes' => method_exists($queue, 'processes') ? $queue->processes() : 1,
                'timeout' => method_exists($queue, 'timeout') ? $queue->timeout() : 60,
                'sleep' => method_exists($queue, 'sleep') ? $queue->sleep() : 30,
                'tries' => method_exists($queue, 'maxTries') ? $queue->maxTries() : 1,
            ]);
        }
    }

    private function applyCron(ForgeCapabilitiesClientInterface $client, object $project, string $serverId): void
    {
        if (! method_exists($project, 'cron')) {
            return;
        }
        $existing = $client->scheduledJobs($serverId);
        foreach ($project->cron() as $name => $cron) {
            if (method_exists($cron, 'enabled') && ! $cron->enabled()) {
                continue;
            }
            $command = method_exists($cron, 'command') ? $cron->command() : '';
            if ($this->resourceByCommand($existing, $command) !== null) {
                continue;
            }
            $client->createScheduledJob($serverId, [
                'name' => (string) $name,
                'command' => $command,
                'user' => method_exists($cron, 'user') ? $cron->user() : 'forge',
                'frequency' => method_exists($cron, 'frequency') ? $cron->frequency() : 'daily',
            ]);
        }
    }

    private function applySsl(ForgeCapabilitiesClientInterface $client, object $project, string $serverId, string $siteId, string $domain): void
    {
        if (! method_exists($project, 'ssl') || ! $project->ssl()->enabled()) {
            return;
        }
        $domains = $client->domains($serverId, $siteId);
        $siteDomain = $this->resourceByName($domains, $domain);
        if ($siteDomain === null) {
            $siteDomain = $client->createDomain($serverId, $siteId, ['name' => $domain]);
        }
        $domainId = $siteDomain['id'] ?? null;
        if (! is_int($domainId) && ! is_string($domainId)) {
            throw new RuntimeException('Forge returned a domain without an ID');
        }
        $active = $client->activeCertificate($serverId, $siteId, (string) $domainId);
        if (($active['active'] ?? false) === true || in_array($active['status'] ?? null, ['active', 'issued', 'installed'], true)) {
            return;
        }
        $client->createCertificate($serverId, $siteId, (string) $domainId, [
            'type' => $project->ssl()->type(),
        ]);
    }

    /** @param list<array<string, mixed>> $resources */
    private function resourceByName(array $resources, string $name): ?array
    {
        foreach ($resources as $resource) {
            if (($resource['name'] ?? $resource['domain'] ?? null) === $name) {
                return $resource;
            }
        }
        return null;
    }

    /** @param list<array<string, mixed>> $resources */
    private function resourceByCommand(array $resources, string $command): ?array
    {
        foreach ($resources as $resource) {
            if (($resource['command'] ?? null) === $command) {
                return $resource;
            }
        }
        return null;
    }

    private function projectName(object $project): string
    {
        return method_exists($project, 'name') ? $project->name() : 'project';
    }

    private function profileName(object $profile): string
    {
        return method_exists($profile, 'name') ? $profile->name() : 'profile';
    }

    private function profileValue(object $object, string $key): mixed
    {
        return method_exists($object, 'get') ? $object->get($key) : null;
    }

    /** @return array<string, mixed>|null */
    private function findSite(string $serverId, string $domain): ?array
    {
        foreach ($this->forgeClient()->sites($serverId) as $site) {
            if (($site['name'] ?? null) === $domain || ($site['domain'] ?? null) === $domain) {
                return $site;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $site */
    private function siteId(array $site): ?string
    {
        $id = $site['id'] ?? null;
        return is_int($id) || is_string($id) ? (string) $id : null;
    }

    /** @param array<string, mixed> $site */
    private function isOwnedSite(array $site, object $project, object $profile): bool
    {
        $tags = $site['tags'] ?? [];
        return is_array($tags) && in_array($this->ownershipTag($project, $profile), $tags, true);
    }

    private function ownershipTag(object $project, object $profile): string
    {
        return (string) ($this->config['ownership_tag'] ?? 'shipper-managed').'-'.substr(hash('sha256', json_encode([$this->projectName($project), $this->profileName($profile)], JSON_THROW_ON_ERROR)), 0, 20);
    }
}
