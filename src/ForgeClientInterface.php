<?php

declare(strict_types=1);

namespace ShipperCli\ProviderForge;

interface ForgeClientInterface
{
    /** @return list<array<string, mixed>> */
    public function sites(string $serverId): array;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createSite(string $serverId, array $payload): array;

    public function deploy(string $serverId, string $siteId): void;

    public function deleteSite(string $serverId, string $siteId): void;
}
