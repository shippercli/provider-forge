<?php

use ShipperCli\ProviderForge\ForgeCapabilitiesClientInterface;
use ShipperCli\ProviderForge\ForgeProvider;

function forgeEngineProject(string $type): object
{
    return new class($type) {
        public function __construct(private string $type) {}
        public function databases(): array { return [new class($this->type) {
            public function __construct(private string $type) {}
            public function name(): string { return 'main'; }
            public function type(): string { return $this->type; }
        }]; }
    };
}

it('rejects unsupported Forge schema engines in configuration validation', function (): void {
    expect((new ForgeProvider)->validate(forgeEngineProject('redis'), new stdClass))
        ->toContain('Forge database declarations require mysql, mariadb or PostgreSQL.');
});

it('matches declared database engines against observed server engines', function (string $declared, string $actual, bool $valid): void {
    $client = $this->createMock(ForgeCapabilitiesClientInterface::class);
    $client->expects($this->once())->method('serverDatabaseType')->with('7')->willReturn($actual);
    $client->expects($this->never())->method('createDatabase');
    $provider = new ForgeProvider(['server_id' => 7], $client);
    $failure = null;
    try { (new ReflectionMethod($provider, 'assertDatabaseServer'))->invoke($provider, forgeEngineProject($declared)); }
    catch (RuntimeException $error) { $failure = $error; }
    expect($failure === null)->toBe($valid);
})->with([
    ['mysql', 'mysql84', true], ['mysql', 'mariadb114', true], ['mariadb', 'mariadb114', true],
    ['postgresql', 'postgres18', true], ['postgres', 'postgres16', true], ['pgsql', 'postgres17', true],
    ['mariadb', 'mysql84', false], ['mysql', 'postgres18', false], ['pgsql', 'mysql84', false],
    ['mysql', '', false], ['mysql', 'unknown', false],
]);

it('blocks mismatched database engines before site or database mutations', function (): void {
    $client = $this->createMock(ForgeCapabilitiesClientInterface::class);
    $client->expects($this->exactly(2))->method('serverDatabaseType')->willReturn('postgres18');
    foreach (['sites', 'createSite', 'createDatabase', 'updateEnvironment', 'deploy'] as $method) {
        $client->expects($this->never())->method($method);
    }
    $provider = new ForgeProvider(['api_token' => 'synthetic', 'server_id' => 7, 'organization_slug' => 'org'], $client);
    $profile = new class { public function get(string $key): mixed { return $key === 'domain' ? 'fixture.test' : null; } };
    expect($provider->apply(forgeEngineProject('mysql'), $profile))->toBeFalse()
        ->and($provider->getLastError())->toContain('does not match');
    expect($provider->plan(forgeEngineProject('mysql'), $profile)['blocked'])->toBeTrue();
});

it('validates server identity and engine before SDK hydration', function (array $data): void {
    $client = forgeSuccessClient([new \GuzzleHttp\Psr7\Response(200, [], json_encode(['data' => $data], JSON_THROW_ON_ERROR))]);
    expect(fn () => $client->serverDatabaseType('7'))->toThrow(RuntimeException::class, 'invalid resource response envelope');
})->with([
    [['id' => '8', 'attributes' => ['database_type' => 'mysql84']]],
    [['id' => '7', 'attributes' => []]],
    [['id' => '7', 'attributes' => ['database_type' => ['synthetic-secret']]]],
    [['id' => '7', 'attributes' => ['database_type' => 'mysql84junk']]],
]);
