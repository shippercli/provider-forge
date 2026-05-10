<?php

declare(strict_types=1);

namespace ShipperCli\ProviderForge;

use ShipperCli\Contracts\DeploymentProviderInterface;
use ShipperCli\Contracts\ShipperPluginInterface;

final class ForgeProvider implements DeploymentProviderInterface
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

        $actions[] = 'Deploy site via Forge API';

        return [
            'provider' => $this->getName(),
            'project' => $projectName,
            'profile' => $profileName,
            'branch' => $branch,
            'server_id' => $serverId,
            'domain' => $domain,
            'actions' => $actions,
            'note' => 'This will create a deployment on Forge server '.$serverId,
        ];
    }

    public function apply(object $project, object $profile): bool
    {
        return true;
    }

    public function destroy(object $project, object $profile): bool
    {
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

    private function interpolateDatabaseName(string $name, string $projectName, string $profileName): string
    {
        $name = \str_replace('${PROJECT_NAME}', $projectName, $name);
        $name = \str_replace('${PROFILE}', $profileName, $name);

        return $name;
    }
}