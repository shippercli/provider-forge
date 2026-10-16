<?php

declare(strict_types=1);

namespace ShipperCli\ProviderForge;

use Laravel\Forge\Forge;
use RuntimeException;

final class ForgeApiClient implements ForgeCapabilitiesClientInterface
{
    private readonly Forge $forge;

    private int $deploymentTimeout = 300;

    private int $deploymentPollMilliseconds = 1000;

    public function __construct(private readonly string $organizationSlug, string $apiToken, int $timeout = 30, int $deploymentTimeout = 300)
    {
        if ($deploymentTimeout < 1 || $deploymentTimeout > 3600) {
            throw new \InvalidArgumentException('Forge deployment timeout must be between 1 and 3600 seconds.');
        }
        $this->deploymentTimeout = $deploymentTimeout;
        $this->forge = (new ForgeInventoryClient($apiToken))->setTimeout($timeout);
    }

    public function sites(string $serverId): array
    {
        $sites = [];
        $ids = [];
        $names = [];
        foreach ($this->iterable($this->forge->serverSites($this->organizationSlug, (int) $serverId)) as $site) {
            $data = $this->siteData($site);
            $id = (int) $data['id'];
            $name = \strtolower($data['name']);
            if (isset($ids[$id]) || isset($names[$name])) {
                throw new RuntimeException('Forge returned a duplicate site identity.');
            }
            $ids[$id] = true;
            $names[$name] = true;
            $sites[] = $data;
        }
        return $sites;
    }

    public function createSite(string $serverId, array $payload): array
    {
        $sitePayload = [
            'domain' => (string) ($payload['domain'] ?? ''),
            'type' => (string) ($payload['project_type'] ?? $payload['type'] ?? 'php'),
        ];
        if (isset($payload['tags']) && is_array($payload['tags'])) {
            $sitePayload['tags'] = array_values($payload['tags']);
        }

        $site = $this->forge->createSite($this->organizationSlug, (int) $serverId, $sitePayload);
        $data = $this->siteData($site);
        if (\trim($data['name']) !== $data['name'] || \strcasecmp($data['name'], $sitePayload['domain']) !== 0) {
            throw new RuntimeException('Forge created site did not confirm the requested domain.');
        }
        return $data;
    }

    public function deploy(string $serverId, string $siteId): void
    {
        $deadline = hrtime(true) / 1_000_000_000 + $this->deploymentTimeout;
        $deployment = $this->forge->createDeployment($this->organizationSlug, (int) $serverId, (int) $siteId);
        $id = $deployment->id;
        if (! is_int($id) || $id <= 0) throw new RuntimeException('Forge did not confirm a deployment identity.');
        while (true) {
            if (hrtime(true) / 1_000_000_000 >= $deadline) {
                throw new RuntimeException('Forge deployment timed out; the remote deployment may still be running.');
            }
            if ($deployment->id !== $id) throw new RuntimeException('Forge deployment identity changed while polling.');
            if ($deployment->status === 'finished') return;
            if (in_array($deployment->status, ['failed', 'failed-build', 'cancelled'], true)) {
                throw new RuntimeException('Forge deployment did not finish successfully ('.$deployment->status.').');
            }
            if (! in_array($deployment->status, ['pending', 'queued', 'deploying'], true)) {
                throw new RuntimeException('Forge returned an invalid deployment status.');
            }
            $remaining = $deadline - hrtime(true) / 1_000_000_000;
            if ($remaining > 0 && $this->deploymentPollMilliseconds > 0) {
                usleep((int) (min($remaining, $this->deploymentPollMilliseconds / 1000) * 1_000_000));
            }
            if (hrtime(true) / 1_000_000_000 >= $deadline) continue;
            $deployment = $this->forge->deployment($this->organizationSlug, (int) $serverId, (int) $siteId, $id);
        }
    }

    public function deleteSite(string $serverId, string $siteId): void
    {
        $this->forge->deleteSite($this->organizationSlug, (int) $serverId, (int) $siteId);
    }

    public function serverDatabaseType(string $serverId): string
    {
        $server = $this->forge->server($this->organizationSlug, (int) $serverId);
        $type = $server->databaseType;
        if (! is_string($type) || preg_match('/\A(?:mysql|mariadb|postgres)[0-9]+\z/', $type) !== 1) {
            throw new RuntimeException('Forge server database engine is unavailable or invalid.');
        }
        return $type;
    }

    public function databases(string $serverId): array
    {
        return $this->resources($this->forge->databases($this->organizationSlug, (int) $serverId), 'name');
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
        $existing = $this->environment($serverId, $siteId);
        $this->forge->updateSiteEnvironment(
            $this->organizationSlug,
            (int) $serverId,
            (int) $siteId,
            $this->mergeEnvironment($existing, $variables),
        );
        try {
            $stored = $this->parseEnvironment($this->environment($serverId, $siteId));
            foreach ($variables as $key => $value) {
                if (($stored[$key] ?? null) !== $value) {
                    throw new RuntimeException('Environment readback mismatch.');
                }
            }
        } catch (\Throwable) {
            throw new RuntimeException('Forge did not confirm the requested environment configuration.');
        }
    }

    public function environment(string $serverId, string $siteId): string
    {
        return $this->forge->siteEnvironment($this->organizationSlug, (int) $serverId, (int) $siteId);
    }

    public function domains(string $serverId, string $siteId): array
    {
        return $this->resources($this->forge->domains(
            $this->organizationSlug,
            (int) $serverId,
            (int) $siteId,
        ), 'name');
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

    public function activeCertificate(string $serverId, string $siteId, string $domainId): ?array
    {
        try {
            $certificate = $this->resourceData($this->forge->activeDomainCertificate(
                $this->organizationSlug,
                (int) $serverId,
                (int) $siteId,
                (int) $domainId,
            ));
            $id = $certificate['id'] ?? null;
            if ((! is_int($id) && ! is_string($id))
                || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new RuntimeException('Forge returned an invalid active certificate identity.');
            }

            return $certificate;
        } catch (\Laravel\Forge\Exceptions\NotFoundException) {
            return null;
        }
    }

    public function backgroundProcesses(string $serverId): array
    {
        return $this->resources($this->forge->backgroundProcesses(
            $this->organizationSlug,
            (int) $serverId,
        ), 'command');
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
        ), 'command');
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
        $id = $site->id ?? ($attributes['id'] ?? null);
        $name = $site->name ?? ($attributes['name'] ?? null);
        if ((! is_int($id) && ! is_string($id))
            || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || ! is_string($name) || trim($name) === '') {
            throw new RuntimeException('Forge returned a site without a valid identity.');
        }
        return [
            'id' => $id,
            'name' => $name,
            'tags' => $site->tags ?? ($attributes['tags'] ?? []),
            'repository' => $site->repository ?? ($attributes['repository'] ?? null),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function resources(mixed $resources, string $identityField): array
    {
        $result = [];
        $ids = [];
        foreach ($this->iterable($resources) as $resource) {
            $data = $this->resourceData($resource);
            if ((! is_int($data['id'] ?? null) && ! is_string($data['id'] ?? null))
                || filter_var($data['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                || ! is_string($data[$identityField] ?? null) || trim($data[$identityField]) === '') {
                throw new RuntimeException('Forge returned a resource without a valid reconciliation identity.');
            }
            $id = (int) $data['id'];
            if (isset($ids[$id])) {
                throw new RuntimeException('Forge returned a duplicate resource identity.');
            }
            $ids[$id] = true;
            $result[] = $data;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function resourceData(object $resource): array
    {
        $data = [];
        foreach (['id', 'name', 'domain', 'type', 'status', 'requestStatus', 'active', 'command', 'user', 'frequency', 'cron', 'tags'] as $key) {
            if (isset($resource->{$key})) {
                $data[$key] = $resource->{$key};
            }
        }

        return $data;
    }

    /** @param array<string, string> $variables */
    /** @return array<string, string> */
    private function parseEnvironment(string $content): array
    {
        try {
            $probe = '__SHIPPER_ENV_END_'.bin2hex(random_bytes(16));
            $parsed = \Dotenv\Dotenv::parse($content."\n".$probe."=1\n");
            if (($parsed[$probe] ?? null) !== '1') {
                throw new RuntimeException('Incomplete environment.');
            }
            unset($parsed[$probe]);
            return $parsed;
        } catch (\Throwable) {
            throw new RuntimeException('Cannot parse the existing Forge site environment before updating it.');
        }

    }

    private function mergeEnvironment(string $existing, array $variables): string
    {
        $this->parseEnvironment($existing);

        foreach ($variables as $key => $value) {
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $key) !== 1) {
                throw new \InvalidArgumentException('Invalid environment variable name.');
            }
            $line = $key.'='.$this->dotenvValue($value);
            $pattern = '/^(?:export[ \t]+)?'.preg_quote($key, '/').'[ \t]*=[ \t]*(?:"(?:\\\\.|[^"\\\\])*"|\x27[^\x27]*\x27|[^\r\n]*)[^\r\n]*(?:\r?\n|$)/m';
            if (preg_match($pattern, $existing) === 1) {
                $existing = (string) preg_replace_callback($pattern, static fn (): string => $line."\n", $existing);
            } else {
                $existing = rtrim($existing, "\n")."\n".$line."\n";
            }
        }

        return $existing;
    }

    private function dotenvValue(string $value): string
    {
        if (preg_match('/[\s#=\\\\"\x27$]/', $value) !== 1) {
            return $value;
        }

        return '"'.str_replace(
            ['\\', '"', '$', "\r", "\n"],
            ['\\\\', '\\"', '\\$', '\\r', '\\n'],
            $value,
        ).'"';
    }

    /** @return iterable<object> */
    private function iterable(mixed $resources): iterable
    {
        if ($resources instanceof \Laravel\Forge\CursorPaginator) {
            return $this->cursorItems($resources);
        }
        if (is_object($resources) && method_exists($resources, 'lazy')) {
            return $resources->lazy();
        }

        if (! is_iterable($resources)) {
            throw new RuntimeException('Forge returned an invalid resource collection.');
        }

        return $resources;
    }
    /** @return \Generator<int, object> */
    private function cursorItems(\Laravel\Forge\CursorPaginator $page): \Generator
    {
        $seen = [];
        do {
            $cursor = $page->nextCursor();
            if ($cursor !== null) {
                if (isset($seen[$cursor])) {
                    throw new RuntimeException('Forge returned a repeated pagination cursor.');
                }
                $seen[$cursor] = true;
            }
            yield from $page->items();
            $page = $page->nextPage();
        } while ($page !== null);
    }

}
