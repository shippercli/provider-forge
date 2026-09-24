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
            'ssl' => ['state' => 'partial', 'limitations' => ['SSL intent can be described, but certificate management is not implemented.']],
            'databases' => ['state' => 'partial', 'limitations' => ['Database names can be interpolated in plans, but database creation is not implemented.']],
            'profiles' => ['state' => 'supported'],
            'background_workloads' => ['state' => 'unsupported'],
            'env' => ['state' => 'unsupported'],
            'observability' => ['state' => 'unsupported'],
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
