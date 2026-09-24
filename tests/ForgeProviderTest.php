<?php

use ShipperCli\ProviderForge\ForgePlugin;
use ShipperCli\ProviderForge\ForgeProvider;
use ShipperCli\Contracts\CapabilityManifest;

it('registers the forge provider through the plugin manifest', function () {
    expect((new ForgePlugin())->providers())->toBe([
        'forge' => ForgeProvider::class,
    ]);
});

it('publishes the current forge capability states', function () {
    $capabilities = (new ForgeProvider())->capabilities();

    expect($capabilities['app_deploy']['state'])->toBe('partial')
        ->and($capabilities['server_lifecycle']['state'])->toBe('unsupported')
        ->and($capabilities['profiles']['state'])->toBe('supported');
});

it('conforms to the shared capability manifest contract', function () {
    expect(CapabilityManifest::from((new ForgeProvider())->capabilities())->toArray())
        ->toBe((new ForgeProvider())->capabilities());
});

it('creates and deploys a site through the client', function () {
    $client = new class implements \ShipperCli\ProviderForge\ForgeClientInterface {
        public array $calls = [];
        public function sites(string $serverId): array { $this->calls[] = ['sites', $serverId]; return []; }
        public function createSite(string $serverId, array $payload): array { $this->calls[] = ['createSite', $serverId, $payload]; return ['id' => 12, 'name' => 'example.test', 'tags' => ['shipper-managed']]; }
        public function deploy(string $serverId, string $siteId): void { $this->calls[] = ['deploy', $serverId, $siteId]; }
        public function deleteSite(string $serverId, string $siteId): void { $this->calls[] = ['deleteSite', $serverId, $siteId]; }
    };
    $provider = new ForgeProvider(['api_token' => 'token', 'server_id' => 7, 'organization_slug' => 'shipper'], $client);
    $project = new class { public function get(string $key): mixed { return $key === 'repository' ? 'shippercli/example' : null; } };
    $profile = new class { public function get(string $key): mixed { return ['domain' => 'example.test', 'repository' => 'shippercli/example', 'branch' => 'main'][$key] ?? null; } };

    expect($provider->apply($project, $profile))->toBeTrue()
        ->and($client->calls[1][0])->toBe('createSite')
        ->and($client->calls[2][0])->toBe('deploy');
});

it('refuses to destroy an unowned site', function () {
    $client = new class implements \ShipperCli\ProviderForge\ForgeClientInterface {
        public function sites(string $serverId): array { return [['id' => 12, 'name' => 'example.test', 'tags' => []]]; }
        public function createSite(string $serverId, array $payload): array { return []; }
        public function deploy(string $serverId, string $siteId): void {}
        public function deleteSite(string $serverId, string $siteId): void {}
    };
    $provider = new ForgeProvider(['api_token' => 'token', 'server_id' => 7, 'organization_slug' => 'shipper'], $client);
    $profile = new class { public function get(string $key): mixed { return $key === 'domain' ? 'example.test' : null; } };

    expect($provider->destroy(new stdClass(), $profile))->toBeFalse()
        ->and($provider->getLastError())->toContain('not owned');
});

it('applies configured Forge capabilities through the extended client', function () {
    $client = new class implements \ShipperCli\ProviderForge\ForgeCapabilitiesClientInterface {
        public array $calls = [];

        public function sites(string $serverId): array { return []; }
        public function createSite(string $serverId, array $payload): array { return ['id' => 12, 'name' => 'example.test', 'tags' => ['shipper-managed']]; }
        public function deploy(string $serverId, string $siteId): void { $this->calls[] = ['deploy']; }
        public function deleteSite(string $serverId, string $siteId): void {}
        public function databases(string $serverId): array { return []; }
        public function createDatabase(string $serverId, array $payload): array { $this->calls[] = ['database', $payload]; return ['id' => 20, 'name' => $payload['name']]; }
        public function deleteDatabase(string $serverId, string $databaseId): void {}
        public function updateEnvironment(string $serverId, string $siteId, array $variables): void { $this->calls[] = ['environment', $variables]; }
        public function domains(string $serverId, string $siteId): array { return [['id' => 30, 'name' => 'example.test']]; }
        public function createDomain(string $serverId, string $siteId, array $payload): array { return ['id' => 30, 'name' => $payload['name']]; }
        public function createCertificate(string $serverId, string $siteId, string $domainId, array $payload): void { $this->calls[] = ['certificate', $payload]; }
        public function backgroundProcesses(string $serverId): array { return []; }
        public function createBackgroundProcess(string $serverId, array $payload): array { $this->calls[] = ['worker', $payload]; return []; }
        public function scheduledJobs(string $serverId): array { return []; }
        public function createScheduledJob(string $serverId, array $payload): array { $this->calls[] = ['cron', $payload]; return []; }
        public function status(string $serverId, string $siteId): array { return []; }
        public function applicationLog(string $serverId, string $siteId): string { return ''; }
    };
    $project = new class {
        public function name(): string { return 'demo'; }
        public function databases(): array { return [new class {
            public function name(): string { return 'demo_db'; }
            public function user(): string { return 'demo'; }
            public function type(): string { return 'mysql'; }
        }]; }
        public function environment(): object { return new class { public function variables(): array { return ['APP_ENV' => 'production']; } }; }
        public function queues(): array { return ['worker' => new class {
            public function enabled(): bool { return true; }
            public function connection(): string { return 'redis'; }
            public function queue(): string { return 'default'; }
            public function processes(): int { return 2; }
            public function timeout(): int { return 60; }
            public function maxTries(): int { return 3; }
            public function sleep(): int { return 10; }
        }]; }
        public function cron(): array { return ['schedule' => new class {
            public function enabled(): bool { return true; }
            public function command(): string { return 'php artisan schedule:run'; }
            public function user(): string { return 'forge'; }
            public function frequency(): string { return '* * * * *'; }
        }]; }
        public function ssl(): object { return new class {
            public function enabled(): bool { return true; }
            public function type(): string { return 'letsencrypt'; }
        }; }
    };
    $profile = new class {
        public function get(string $key): mixed { return $key === 'domain' ? 'example.test' : null; }
        public function environment(): object { return new class { public function variables(): array { return ['APP_DEBUG' => 'false']; } }; }
    };

    $provider = new ForgeProvider(['api_token' => 'token', 'server_id' => 7, 'organization_slug' => 'shipper'], $client);

    expect($provider->apply($project, $profile))->toBeTrue()
        ->and(array_column($client->calls, 0))->toContain('database', 'environment', 'worker', 'cron', 'certificate', 'deploy');
});
