<?php

declare(strict_types=1);

namespace ShipperCli\ProviderForge;

use ShipperCli\Contracts\DeploymentProviderInterface;
use ShipperCli\Contracts\ProviderCapabilitiesInterface;

final class ForgeProvider implements DeploymentProviderInterface, ProviderCapabilitiesInterface
{
    /** @var array<string, mixed> */
    private readonly array $config;

    private string $lastError = '';

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
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
            'app_deploy' => ['state' => 'partial', 'limitations' => ['Deployment execution is not implemented yet.']],
            'server_lifecycle' => ['state' => 'unsupported'],
            'domain_management' => ['state' => 'partial', 'limitations' => ['Domains are validated and shown in plans, but Forge API mutation is not implemented.']],
            'ssl' => ['state' => 'partial', 'limitations' => ['SSL intent can be described, but certificate management is not implemented.']],
            'databases' => ['state' => 'partial', 'limitations' => ['Database names can be interpolated in plans, but database creation is not implemented.']],
            'profiles' => ['state' => 'partial', 'limitations' => ['Profiles are accepted for validation and planning, but cannot be deployed through Forge yet.']],
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

        if (method_exists($profile, 'get')) {
            $domain = $profile->get('domain');
            if ($domain === null || $domain === '') {
                $errors[] = 'Domain is required for profile';
            }
        }

        return $errors;
    }

    public function plan(object $project, object $profile): array
    {
        $serverId = $this->getServerId();
        $domain = '';

        if (method_exists($profile, 'get')) {
            $domainValue = $profile->get('domain');
            $domain = \is_string($domainValue) ? $domainValue : '';
        }

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

        $actions[] = 'Deploy site via Forge API when deployment execution is implemented';

        return [
            'provider' => $this->getName(),
            'project' => $projectName,
            'profile' => $profileName,
            'branch' => $branch,
            'server_id' => $serverId,
            'domain' => $domain,
            'actions' => $actions,
            'note' => 'This describes the intended Forge deployment; execution is not implemented.',
        ];
    }

    public function apply(object $project, object $profile): bool
    {
        $this->lastError = 'Forge apply is not implemented';

        return false;
    }

    public function destroy(object $project, object $profile): bool
    {
        $this->lastError = 'Forge destroy is not implemented';

        return false;
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
}
