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
    $client->expects($this->exactly(2))->method('createDatabase')->willReturnCallback(static function (string $server, array $payload) use (&$payloads): array { $payloads[] = $payload; return ['id' => count($payloads), 'name' => $payload['name']]; });
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


it('rejects Forge database creation acknowledgements for an unconfirmed resource', function (array $response): void {
    $client = $this->createMock(ForgeCapabilitiesClientInterface::class);
    $client->expects($this->once())->method('databases')->willReturn([]);
    $client->expects($this->once())->method('createDatabase')->willReturn($response);
    $provider = new ForgeProvider(['server_id' => 7, 'database_environment_prefixes' => ['main' => 'DB', 'analytics' => 'OTHER_DB']]);
    $error = null;
    try {
        (new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $client, forgeDatabaseProject(), new stdClass);
    } catch (RuntimeException $exception) { $error = $exception; }
    expect($error)->toBeInstanceOf(RuntimeException::class)
        ->and($error?->getMessage())->toBe('Forge did not confirm the requested database creation identity.')
        ->and((new ReflectionProperty($provider, 'generatedEnvironment'))->getValue($provider))->toBe([]);
})->with([[[]], [['id' => 9, 'name' => 'foreign']], [['id' => 0, 'name' => 'main']],
    [['id' => '9junk', 'name' => 'main']], [['id' => 9]], [['id' => 9, 'name' => 'main ']]]);

it('checks database names after actual Forge SDK response hydration', function (string $returnedName, bool $valid): void {
    $client = forgeSuccessClient([
        new \GuzzleHttp\Psr7\Response(200, [], '{"data":[],"meta":{"next_cursor":null}}'),
        new \GuzzleHttp\Psr7\Response(200, [], json_encode(['data' => ['id' => '21', 'type' => 'databases', 'attributes' => ['name' => $returnedName]]], JSON_THROW_ON_ERROR)),
        new \GuzzleHttp\Psr7\Response(200, [], json_encode(['data' => ['id' => '21', 'type' => 'databases', 'attributes' => ['name' => $returnedName, 'status' => 'installed']]], JSON_THROW_ON_ERROR)),
    ]);
    $project = new class {
        public function databases(): array { return [new class {
            public function name(): string { return 'main'; }
            public function user(): string { return 'application'; }
        }]; }
    };
    $provider = new ForgeProvider(['server_id' => 7, 'database_password' => 'synthetic-literal-secret']);
    $failure = null;
    try {
        (new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $client, $project, new stdClass);
    } catch (RuntimeException $error) { $failure = $error; }
    $environment = (new ReflectionProperty($provider, 'generatedEnvironment'))->getValue($provider);
    if ($valid) {
        expect($failure)->toBeNull()->and($environment['DB_DATABASE'])->toBe('main')
            ->and($environment['DB_PASSWORD'])->toBe('synthetic-literal-secret');
    } else {
        expect($failure?->getMessage())->toBe('Forge did not confirm the requested database creation identity.')
            ->and($environment)->toBe([]);
    }
})->with([['main', true], ['foreign', false]]);

it('uses explicit Forge passwords before provider-wide defaults without changing secret bytes', function (): void {
    $secret = 'literal-${PROJECT_NAME}-${PROFILE}-+%:@';
    $profile = new class($secret) {
        public function __construct(private string $secret) {}
        public function environment(): object { return new class($this->secret) {
            public function __construct(private string $secret) {}
            public function variables(): array { return ['DB_PASSWORD' => $this->secret]; }
        }; }
    };
    $project = new class {
        public function name(): string { return 'api'; }
        public function databases(): array { return forgeDatabaseProject()->databases(); }
        public function environment(): object { return new class {
            public function variables(): array { return ['DB_PASSWORD' => 'project-default']; }
        }; }
    };
    $client = $this->createMock(ForgeCapabilitiesClientInterface::class);
    $client->expects($this->once())->method('databases')->willReturn([]);
    $passwords = [];
    $client->expects($this->exactly(2))->method('createDatabase')->willReturnCallback(function ($server, $payload) use (&$passwords): array {
        $passwords[$payload['name']] = $payload['password'];
        return ['id' => count($passwords), 'name' => $payload['name']];
    });
    $provider = new ForgeProvider(['server_id' => 7, 'database_password' => 'provider-default',
        'database_environment_prefixes' => ['main' => 'DB', 'analytics' => 'ANALYTICS_DB']]);
    (new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $client, $project, $profile);
    $environment = (new ReflectionProperty($provider, 'generatedEnvironment'))->getValue($provider);
    expect($passwords['main'])->toBe($secret)->and($environment['DB_PASSWORD'])->toBe($secret)
        ->and($passwords['analytics'])->toBe('provider-default')
        ->and($environment['ANALYTICS_DB_PASSWORD'])->toBe('provider-default');
});

it('rejects invalid explicit Forge passwords before database inventory or creation', function (mixed $password): void {
    $profile = new class($password) {
        public function __construct(private mixed $password) {}
        public function environment(): object { return new class($this->password) {
            public function __construct(private mixed $password) {}
            public function variables(): array { return ['ANALYTICS_DB_PASSWORD' => $this->password]; }
        }; }
    };
    $client = $this->createMock(ForgeCapabilitiesClientInterface::class);
    $client->expects($this->never())->method('databases');
    $client->expects($this->never())->method('createDatabase');
    $provider = new ForgeProvider(['server_id' => 7, 'database_environment_prefixes' => ['main' => 'DB', 'analytics' => 'ANALYTICS_DB']]);
    expect(fn () => (new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $client, forgeDatabaseProject(), $profile))
        ->toThrow(InvalidArgumentException::class, 'Explicit Forge database passwords must be nonempty strings.');
    expect($provider->validate(forgeDatabaseProject(), $profile))->toContain('Explicit Forge database passwords must be nonempty strings.');
    expect($provider->apply(forgeDatabaseProject(), $profile))->toBeFalse()
        ->and($provider->getLastError())->toContain('Explicit Forge database passwords must be nonempty strings.');
})->with([[''], [null], [false], [123]]);
