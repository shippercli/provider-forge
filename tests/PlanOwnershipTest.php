<?php

use ShipperCli\ProviderForge\ForgeClientInterface;
use ShipperCli\ProviderForge\ForgeCapabilitiesClientInterface;
use ShipperCli\ProviderForge\ForgeProvider;

it('plans only after ownership inspection and blocks unsafe or unreadable targets', function (string $mode): void {
    $client = new class($mode) implements ForgeClientInterface {
        public array $calls = [];
        public function __construct(private string $mode) {}
        public function sites(string $serverId): array {
            $this->calls[] = 'sites';
            if ($this->mode === 'error') throw new RuntimeException('sensitive API details');
            if ($this->mode === 'new') return [];
            $tag = 'shipper-managed-'.substr(hash('sha256', json_encode(['api', 'preview'], JSON_THROW_ON_ERROR)), 0, 20);
            return [['id' => 12, 'name' => 'example.test', 'tags' => $this->mode === 'owned' ? [$tag] : []]];
        }
        public function createSite(string $serverId, array $payload): array { $this->calls[] = 'create'; return []; }
        public function deploy(string $serverId, string $siteId): void { $this->calls[] = 'deploy'; }
        public function deleteSite(string $serverId, string $siteId): void { $this->calls[] = 'delete'; }
    };
    $project = new class { public function name(): string { return 'api'; } };
    $profile = new class {
        public function name(): string { return 'preview'; }
        public function get(string $key): mixed { return $key === 'domain' ? 'example.test' : null; }
    };
    $plan = (new ForgeProvider(['server_id' => 7], $client))->plan($project, $profile);
    expect($client->calls)->toBe(['sites'])
        ->and($plan['blocked'])->toBe(in_array($mode, ['unowned', 'error'], true));
    if ($plan['blocked']) {
        expect($plan['actions'])->toHaveCount(1)
            ->and($plan['actions'][0])->toStartWith('BLOCKED:')
            ->not->toContain('sensitive API details');
    } else {
        expect($plan['actions'][0])->toContain($mode === 'owned' ? 'Reuse owned site' : 'Create site');
    }
})->with(['new', 'owned', 'unowned', 'error']);

it('plan blocks an unmarked existing Forge database and discloses exact-scope adoption', function (bool $consent): void {
    $client = $this->createMock(ForgeCapabilitiesClientInterface::class);
    $client->method('serverDatabaseType')->willReturn('mysql84');
    $client->method('sites')->willReturn([['id' => '55', 'name' => 'api.example.com', 'tags' => ['shipper-managed-'.substr(hash('sha256', json_encode(['api', 'production'], JSON_THROW_ON_ERROR)), 0, 20)]]]);
    $client->method('databases')->willReturn([['id' => 9, 'name' => 'api_production']]);
    $client->method('environment')->willReturn("DB_HOST=127.0.0.1\nDB_DATABASE=api_production\nDB_USERNAME=api_production\nDB_PASSWORD=synthetic-secret");
    $project = new class {
        public function name(): string { return 'api'; }
        public function databases(): array { return [new class { public function name(): string { return 'api_production'; } public function user(): string { return 'api_production'; } }]; }
    };
    $profile = new class($consent) {
        public function __construct(private bool $consent) {}
        public function name(): string { return 'production'; }
        public function get(string $key): mixed { return match ($key) { 'domain' => 'api.example.com', 'forge' => $this->consent ? ['adopt_existing_databases' => [['project' => 'api', 'profile' => 'production', 'name' => 'api_production']]] : null, default => null }; }
    };
    $plan = (new ForgeProvider(['server_id' => 7], $client))->plan($project, $profile);
    expect($plan['blocked'])->toBe(! $consent);
    if ($consent) expect($plan['actions'])->toContain('Adopt existing Forge database: api_production');
})->with([false, true]);
