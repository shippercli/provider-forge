<?php

use ShipperCli\ProviderForge\ForgeCapabilitiesClientInterface;
use ShipperCli\ProviderForge\ForgeProvider;

function forgeDatabaseProject(): object
{
    return new class {
        public function name(): string { return 'api'; }
        public function databases(): array {
            return array_map(static fn (string $name): object => new class($name) {
                public function __construct(private string $database) {}
                public function name(): string { return $this->database; }
                public function user(): string { return '${PROJECT_NAME}_${PROFILE}_'.$this->database; }
                public function type(): string { return 'mysql'; }
            }, ['main', 'analytics']);
        }
    };
}

it('delivers every generated Forge database connection with independent credentials', function (): void {
    $payloads = [];
    $client = $this->createMock(ForgeCapabilitiesClientInterface::class);
    $client->expects($this->once())->method('databases')->willReturn([]);
    $client->expects($this->exactly(2))->method('createDatabase')->willReturnCallback(static function (string $server, array $payload) use (&$payloads): array { $payloads[] = $payload; return []; });
    $provider = new ForgeProvider(['server_id' => 7, 'database_environment_prefixes' => ['main' => 'DB', 'analytics' => 'ANALYTICS_DB']]);
    $profile = new class { public function name(): string { return 'production'; } };
    (new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $client, forgeDatabaseProject(), $profile);
    $env = (new ReflectionProperty($provider, 'generatedEnvironment'))->getValue($provider);

    expect($env['DB_PASSWORD'])->toBe($payloads[0]['password'])
        ->and($env['ANALYTICS_DB_PASSWORD'])->toBe($payloads[1]['password'])
        ->and($env['DB_PASSWORD'])->not->toBe($env['ANALYTICS_DB_PASSWORD'])
        ->and($env['DB_USERNAME'])->toBe('api_production_main')
        ->and($env['ANALYTICS_DB_USERNAME'])->toBe('api_production_analytics')
        ->and($env['DB_HOST'])->toBe('127.0.0.1');
});

it('rejects ambiguous Forge database mappings before provider calls', function (array $prefixes): void {
    $client = $this->createMock(ForgeCapabilitiesClientInterface::class);
    $client->expects($this->never())->method('databases');
    $client->expects($this->never())->method('createDatabase');
    $provider = new ForgeProvider(['server_id' => 7, 'database_environment_prefixes' => $prefixes]);
    (new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $client, forgeDatabaseProject(), new stdClass);
})->with([[[]], [['main' => 'DB', 'analytics' => 'DB']], [['main' => 'DB', 'analytics' => "BAD\nKEY"]]])->throws(InvalidArgumentException::class);
