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
