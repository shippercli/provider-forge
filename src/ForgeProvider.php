<?php

declare(strict_types=1);

namespace ShipperCli\ProviderForge;

use RuntimeException;
use ShipperCli\Contracts\DeploymentProviderInterface;
use ShipperCli\Contracts\ProviderCapabilitiesInterface;
use Throwable;

final class ForgeProvider implements DeploymentProviderInterface, ProviderCapabilitiesInterface
{
    /** @var array<string, mixed> */
    private readonly array $config;

    private string $lastError = '';

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
            'previews' => ['state' => 'unsupported'],
        ];
    }

    public function validate(object $project, object $profile): array
    {
        $errors = [];

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
        $serverId = $this->getServerId();
        $domainValue = $this->profileValue($profile, 'domain');
        $domain = \is_string($domainValue) ? $domainValue : '';

        $projectName = method_exists($project, 'name') ? $project->name() : 'unknown';
        $profileName = method_exists($profile, 'name') ? $profile->name() : 'unknown';
        $branch = method_exists($profile, 'branch') ? $profile->branch() : 'main';

        $actions = ["Create or find site for domain: {$domain}"];

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
            'actions' => $actions,
            'note' => 'Forge deployment is executed through the configured API client.',
        ];
    }

    public function apply(object $project, object $profile): bool
    {
        $errors = $this->validate($project, $profile);
        if ($errors !== []) {
            $this->lastError = implode('; ', $errors);
            return false;
        }

        try {
            $serverId = $this->getServerId();
            $domain = (string) $this->profileValue($profile, 'domain');
            $site = $this->findSite($serverId, $domain);
            if ($site === null) {
                $site = $this->forgeClient()->createSite($serverId, [
                    'domain' => $domain,
                    'project_type' => 'php',
                    'tags' => [$this->ownershipTag()],
                ]);
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
            $this->lastError = $exception->getMessage();
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
            if (! $this->isOwnedSite($site)) {
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

    public function logs(object $project, object $profile): string
    {
        $site = $this->findSite($this->getServerId(), (string) $this->profileValue($profile, 'domain'));
        $siteId = $site === null ? null : $this->siteId($site);
        if ($siteId === null || ! $this->forgeClient() instanceof ForgeCapabilitiesClientInterface) {
            return '';
        }

        return $this->forgeCapabilitiesClient()->applicationLog($this->getServerId(), $siteId);
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
        $this->applyDatabases($client, $project);
        $this->applyEnvironment($client, $project, $profile, $serverId, $siteId);
        $this->applyQueues($client, $project, $serverId);
        $this->applyCron($client, $project, $serverId);
        $this->applySsl($client, $project, $serverId, $siteId, $domain);
    }

    private function applyDatabases(ForgeCapabilitiesClientInterface $client, object $project): void
    {
        if (! method_exists($project, 'databases')) {
            return;
        }

        $existing = $client->databases($this->getServerId());
        foreach ($project->databases() as $database) {
            if (! method_exists($database, 'name')) {
                continue;
            }
            $name = $this->interpolateDatabaseName($database->name(), $this->projectName($project), '');
            if ($this->resourceByName($existing, $name) !== null) {
                continue;
            }
            $payload = [
                'name' => $name,
                'type' => method_exists($database, 'type') ? $database->type() : 'mysql',
                'user' => method_exists($database, 'user') ? $database->user() : $name,
                'password' => (string) ($this->config['database_password'] ?? bin2hex(random_bytes(16))),
            ];
            $client->createDatabase($this->getServerId(), $payload);
        }
    }

    private function applyEnvironment(ForgeCapabilitiesClientInterface $client, object $project, object $profile, string $serverId, string $siteId): void
    {
        $variables = [];
        foreach ([$project, $profile] as $source) {
            if (method_exists($source, 'environment')) {
                $environment = $source->environment();
                if (method_exists($environment, 'variables')) {
                    $variables = array_merge($variables, $environment->variables());
                }
            }
        }
        if ($variables !== []) {
            $client->updateEnvironment($serverId, $siteId, $variables);
        }
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
    private function isOwnedSite(array $site): bool
    {
        $tags = $site['tags'] ?? [];
        return is_array($tags) && in_array($this->ownershipTag(), $tags, true);
    }

    private function ownershipTag(): string
    {
        return (string) ($this->config['ownership_tag'] ?? 'shipper-managed');
    }
}
