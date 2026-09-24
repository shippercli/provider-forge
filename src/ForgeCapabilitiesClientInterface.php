<?php

declare(strict_types=1);

namespace ShipperCli\ProviderForge;

interface ForgeCapabilitiesClientInterface extends ForgeClientInterface
{
    /** @return list<array<string, mixed>> */
    public function databases(string $serverId): array;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createDatabase(string $serverId, array $payload): array;

    public function deleteDatabase(string $serverId, string $databaseId): void;

    /** @param array<string, string> $variables */
    public function updateEnvironment(string $serverId, string $siteId, array $variables): void;

    /** @return list<array<string, mixed>> */
    public function domains(string $serverId, string $siteId): array;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createDomain(string $serverId, string $siteId, array $payload): array;

    /** @param array<string, mixed> $payload */
    public function createCertificate(string $serverId, string $siteId, string $domainId, array $payload): void;

    /** @return list<array<string, mixed>> */
    public function backgroundProcesses(string $serverId): array;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createBackgroundProcess(string $serverId, array $payload): array;

    /** @return list<array<string, mixed>> */
    public function scheduledJobs(string $serverId): array;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createScheduledJob(string $serverId, array $payload): array;

    /** @return array<string, mixed> */
    public function status(string $serverId, string $siteId): array;

    public function applicationLog(string $serverId, string $siteId): string;
}
