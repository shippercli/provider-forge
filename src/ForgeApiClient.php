<?php

declare(strict_types=1);

namespace ShipperCli\ProviderForge;

use Laravel\Forge\Forge;
use RuntimeException;

final class ForgeApiClient implements ForgeClientInterface
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
}
