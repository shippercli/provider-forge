<?php

declare(strict_types=1);

namespace ShipperCli\ProviderForge;

use Laravel\Forge\Forge;
use RuntimeException;

final class ForgeApiClient implements ForgeCapabilitiesClientInterface
{
    private readonly Forge $forge;

    public function __construct(private readonly string $organizationSlug, string $apiToken, int $timeout = 30)
    {
        $this->forge = (new Forge($apiToken))->setTimeout($timeout);
    }

    public function sites(string $serverId): array
    {
        $sites = [];
        foreach ($this->forge->serverSites($this->organizationSlug, (int) $serverId) as $site) {
            $sites[] = $this->siteData($site);
        }
        return $sites;
    }

    public function createSite(string $serverId, array $payload): array
    {
        $site = $this->forge->createSite($this->organizationSlug, (int) $serverId, [
            'domain' => (string) ($payload['domain'] ?? ''),
            'type' => 'php',
        ]);
        return $this->siteData($site);
    }

    public function deploy(string $serverId, string $siteId): void
    {
        $this->forge->createDeployment($this->organizationSlug, (int) $serverId, (int) $siteId);
    }

    public function deleteSite(string $serverId, string $siteId): void
    {
        $this->forge->deleteSite($this->organizationSlug, (int) $serverId, (int) $siteId);
    }

    public function databases(string $serverId): array
    {
        return $this->resources($this->forge->databases($this->organizationSlug, (int) $serverId));
    }

    public function createDatabase(string $serverId, array $payload): array
    {
        return $this->resourceData($this->forge->createDatabase(
            $this->organizationSlug,
            (int) $serverId,
            $payload,
        ));
    }

    public function deleteDatabase(string $serverId, string $databaseId): void
    {
        $this->forge->deleteDatabase($this->organizationSlug, (int) $serverId, (int) $databaseId);
    }

    public function updateEnvironment(string $serverId, string $siteId, array $variables): void
    {
        $existing = $this->forge->siteEnvironment($this->organizationSlug, (int) $serverId, (int) $siteId);
        $this->forge->updateSiteEnvironment(
            $this->organizationSlug,
            (int) $serverId,
            (int) $siteId,
            $this->mergeEnvironment($existing, $variables),
        );
    }

    public function domains(string $serverId, string $siteId): array
    {
        return $this->resources($this->forge->domains(
            $this->organizationSlug,
            (int) $serverId,
            (int) $siteId,
        ));
    }

    public function createDomain(string $serverId, string $siteId, array $payload): array
    {
        return $this->resourceData($this->forge->createDomain(
            $this->organizationSlug,
            (int) $serverId,
            (int) $siteId,
            $payload,
        ));
    }

    public function createCertificate(string $serverId, string $siteId, string $domainId, array $payload): void
    {
        $this->forge->createCertificate(
            $this->organizationSlug,
            (int) $serverId,
            (int) $siteId,
            (int) $domainId,
            $payload,
        );
    }

    public function backgroundProcesses(string $serverId): array
    {
        return $this->resources($this->forge->backgroundProcesses(
            $this->organizationSlug,
            (int) $serverId,
        ));
    }

    public function createBackgroundProcess(string $serverId, array $payload): array
    {
        return $this->resourceData($this->forge->createBackgroundProcess(
            $this->organizationSlug,
            (int) $serverId,
            $payload,
        ));
    }

    public function scheduledJobs(string $serverId): array
    {
        return $this->resources($this->forge->scheduledJobs(
            $this->organizationSlug,
            (int) $serverId,
        ));
    }

    public function createScheduledJob(string $serverId, array $payload): array
    {
        return $this->resourceData($this->forge->createScheduledJob(
            $this->organizationSlug,
            (int) $serverId,
            $payload,
        ));
    }

    public function status(string $serverId, string $siteId): array
    {
        return $this->forge->deploymentStatus(
            $this->organizationSlug,
            (int) $serverId,
            (int) $siteId,
        );
    }

    public function applicationLog(string $serverId, string $siteId): string
    {
        return $this->forge->siteApplicationLog(
            $this->organizationSlug,
            (int) $serverId,
            (int) $siteId,
        );
    }

    /** @return array<string, mixed> */
    private function siteData(object $site): array
    {
        $attributes = property_exists($site, 'attributes') && is_array($site->attributes) ? $site->attributes : [];
        return [
            'id' => $site->id ?? ($attributes['id'] ?? null),
            'name' => $site->name ?? ($attributes['name'] ?? null),
            'tags' => $site->tags ?? ($attributes['tags'] ?? []),
            'repository' => $site->repository ?? ($attributes['repository'] ?? null),
        ];
    }

    /** @param iterable<object> $resources @return list<array<string, mixed>> */
    private function resources(iterable $resources): array
    {
        $result = [];
        foreach ($resources as $resource) {
            $result[] = $this->resourceData($resource);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function resourceData(object $resource): array
    {
        $data = [];
        foreach (['id', 'name', 'domain', 'type', 'status', 'command', 'user', 'frequency', 'cron', 'tags'] as $key) {
            if (isset($resource->{$key})) {
                $data[$key] = $resource->{$key};
            }
        }

        return $data;
    }

    /** @param array<string, string> $variables */
    private function mergeEnvironment(string $existing, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $line = $key.'='.$this->dotenvValue($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            if (preg_match($pattern, $existing) === 1) {
                $existing = (string) preg_replace($pattern, $line, $existing);
            } else {
                $existing = rtrim($existing, "\n")."\n".$line."\n";
            }
        }

        return $existing;
    }

    private function dotenvValue(string $value): string
    {
        return preg_match('/[\s#=]/', $value) === 1 ? '"'.str_replace('"', '\\"', $value).'"' : $value;
    }
}
