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

it('requires existing Forge database credentials without creating or rotating databases', function (string $content, bool $valid): void {
    $client = $this->createMock(ForgeCapabilitiesClientInterface::class);
    $client->expects($this->once())->method('databases')->willReturn([['name' => 'main']]);
    $client->expects($this->once())->method('environment')->with('7', '55')->willReturn($content);
    $client->expects($this->never())->method('createDatabase');
    $project = new class {
        public function databases(): array { return [new class {
            public function name(): string { return 'main'; }
            public function user(): string { return 'api'; }
        }]; }
    };
    $provider = new ForgeProvider(['server_id' => 7]);
    try {
        (new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $client, $project, new stdClass, '55');
        expect($valid)->toBeTrue();
    } catch (RuntimeException $exception) {
        expect($valid)->toBeFalse()->and($exception->getMessage())->not->toContain('retained-secret');
    }
    expect((new ReflectionProperty($provider, 'generatedEnvironment'))->getValue($provider))->toBe([]);
})->with([
    ["DB_HOST=127.0.0.1\nDB_DATABASE=main\nDB_USERNAME=api\nDB_PASSWORD='retained-secret'", true],
    ["DB_HOST=127.0.0.1\nDB_DATABASE=main\nDB_USERNAME=api", false],
    ["DB_HOST=127.0.0.1\nDB_DATABASE=other\nDB_USERNAME=api\nDB_PASSWORD=retained-secret", false],
    ["DB_PASSWORD=\"unterminated", false],
]);


it('rejects Forge database names that collide after interpolation before API access', function (): void {
    $project = new class {
        public function name(): string { return 'api'; }
        public function databases(): array { return array_map(static fn (string $name): object => new class($name) {
            public function __construct(private string $databaseName) {}
            public function name(): string { return $this->databaseName; }
        }, ['${PROJECT_NAME}_${PROFILE}', 'api_production']); }
    };
    $profile = new class { public function name(): string { return 'production'; } };
    $client = $this->createMock(ForgeCapabilitiesClientInterface::class);
    $client->expects($this->never())->method('databases');
    $client->expects($this->never())->method('createDatabase');
    $provider = new ForgeProvider(['server_id' => 7, 'database_environment_prefixes' => ['${PROJECT_NAME}_${PROFILE}' => 'DB', 'api_production' => 'OTHER_DB']]);
    expect(fn () => (new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $client, $project, $profile))
        ->toThrow(InvalidArgumentException::class, 'Resolved Forge database names must be unique.');
    expect($provider->validate($project, $profile))->toContain('Resolved Forge database names must be unique.');
    expect(fn () => $provider->plan($project, $profile))->toThrow(InvalidArgumentException::class, 'Resolved Forge database names must be unique.');
    expect($provider->apply($project, $profile))->toBeFalse()
        ->and($provider->getLastError())->toContain('Resolved Forge database names must be unique.');
});
