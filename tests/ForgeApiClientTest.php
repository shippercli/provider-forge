<?php

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Laravel\Forge\Exceptions\ForbiddenException;
use ShipperCli\ProviderForge\ForgeApiClient;
use ShipperCli\ProviderForge\ForgeInventoryClient;

function forgeSuccessClient(array $responses): ForgeApiClient
{
    $http = new Client(['http_errors' => false, 'handler' => HandlerStack::create(new MockHandler($responses))]);
    $client = (new ReflectionClass(ForgeApiClient::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($client, 'forge'))->setValue($client, new ForgeInventoryClient('token', $http));
    (new ReflectionProperty($client, 'organizationSlug'))->setValue($client, 'org');

    return $client;
}

it('preserves valid JSON API inventory identities through SDK hydration', function (string $method, string $field): void {
    $response = new Response(200, [], json_encode([
        'data' => [['id' => '21', 'type' => 'resource', 'attributes' => [$field => 'example']]],
        'meta' => ['next_cursor' => null],
    ], JSON_THROW_ON_ERROR));
    $client = forgeSuccessClient([$response]);
    $args = $method === 'domains' ? ['7', '12'] : ['7'];
    $items = $client->{$method}(...$args);

    expect($items)->toHaveCount(1)->and($items[0]['id'])->toBe(21)
        ->and($items[0][$field])->toBe('example');
})->with([
    ['sites', 'name'], ['databases', 'name'], ['domains', 'name'],
    ['backgroundProcesses', 'command'], ['scheduledJobs', 'command'],
]);

it('preserves ownership tags and all site pages through the adapter', function (): void {
    $responses = [];
    foreach ([1, 2] as $page) {
        $responses[] = new Response(200, [], json_encode([
            'data' => [['id' => (string) $page, 'type' => 'sites', 'attributes' => [
                'name' => "site{$page}.example.com", 'tags' => ['scoped-ownership'],
            ]]],
            'meta' => ['next_cursor' => $page === 1 ? 'next-page' : null],
        ], JSON_THROW_ON_ERROR));
    }
    $items = forgeSuccessClient($responses)->sites('7');
    expect(array_column($items, 'id'))->toBe([1, 2])
        ->and(array_column($items, 'tags'))->toBe([['scoped-ownership'], ['scoped-ownership']]);
});

it('preserves valid active certificate state through the adapter', function (): void {
    $response = new Response(200, [], json_encode(['data' => [
        'id' => '31', 'type' => 'certificates', 'attributes' => ['active' => true, 'status' => 'installed'],
    ]], JSON_THROW_ON_ERROR));
    $certificate = forgeSuccessClient([$response])->activeCertificate('7', '12', '13');
    expect($certificate['id'])->toBe(31)->and($certificate['active'])->toBeTrue()
        ->and($certificate['status'])->toBe('installed');
});

it('rejects repeated resource IDs across cursor pages', function (string $method): void {
    $responses = [];
    foreach (['next', null] as $cursor) {
        $responses[] = new Response(200, [], json_encode(['data' => [['id' => '21', 'type' => 'resource',
            'attributes' => ['name' => 'fixture', 'command' => 'fixture']]], 'meta' => ['next_cursor' => $cursor]]));
    }
    $args = $method === 'domains' ? ['7', '12'] : ['7'];
    expect(fn () => forgeSuccessClient($responses)->{$method}(...$args))
        ->toThrow(RuntimeException::class, 'duplicate');
})->with(['sites', 'databases', 'domains', 'backgroundProcesses', 'scheduledJobs']);

it('rejects ambiguous site names even when IDs differ', function (): void {
    $response = new Response(200, [], json_encode(['data' => [
        ['id' => '1', 'type' => 'sites', 'attributes' => ['name' => 'fixture.test']],
        ['id' => '2', 'type' => 'sites', 'attributes' => ['name' => 'FIXTURE.test']],
    ], 'meta' => ['next_cursor' => null]]));
    expect(fn () => forgeSuccessClient([$response])->sites('7'))->toThrow(RuntimeException::class, 'duplicate');
});

it('preserves distinct workload identities sharing one command', function (string $method): void {
    $response = new Response(200, [], json_encode(['data' => [
        ['id' => '1', 'type' => 'resource', 'attributes' => ['command' => 'php artisan schedule:run']],
        ['id' => '2', 'type' => 'resource', 'attributes' => ['command' => 'php artisan schedule:run']],
    ], 'meta' => ['next_cursor' => null]]));
    expect(array_column(forgeSuccessClient([$response])->{$method}('7'), 'id'))->toBe([1, 2]);
})->with(['scheduledJobs', 'backgroundProcesses']);

it('rejects malformed raw IDs before Forge SDK hydration', function (string $method, mixed $id): void {
    $response = new Response(200, [], json_encode(['data' => [['id' => $id, 'type' => 'resource',
        'attributes' => ['name' => 'fixture', 'command' => 'fixture']]], 'meta' => ['next_cursor' => null]]));
    $args = $method === 'domains' ? ['7', '12'] : ['7'];
    expect(fn () => forgeSuccessClient([$response])->{$method}(...$args))
        ->toThrow(RuntimeException::class, 'invalid resource inventory envelope');
})->with(['sites', 'databases', 'domains', 'backgroundProcesses', 'scheduledJobs'])
    ->with([21.5, true, '21junk', '2.1e1', ' 21', '021', null]);

it('rejects identity shadowing in JSON API attributes', function (mixed $id): void {
    $response = new Response(200, [], json_encode(['data' => [['id' => '21', 'type' => 'sites',
        'attributes' => ['id' => $id, 'name' => 'fixture']]], 'meta' => ['next_cursor' => null]]));
    expect(fn () => forgeSuccessClient([$response])->sites('7'))
        ->toThrow(RuntimeException::class, 'invalid resource inventory envelope');
})->with([22, 21.5]);

it('checks raw identity integer bounds without truncating valid IDs', function (bool $overflow): void {
    $id = (string) PHP_INT_MAX.($overflow ? '0' : '');
    $response = new Response(200, [], json_encode(['data' => [['id' => $id, 'type' => 'sites',
        'attributes' => ['name' => 'fixture']]], 'meta' => ['next_cursor' => null]]));
    $client = forgeSuccessClient([$response]);
    if ($overflow) {
        expect(fn () => $client->sites('7'))->toThrow(RuntimeException::class, 'invalid resource inventory envelope');
    } else {
        expect($client->sites('7')[0]['id'])->toBe(PHP_INT_MAX);
    }
})->with([true, false]);

function certificateLookupClient(int $status): ForgeApiClient
{
    $http = new Client(['http_errors' => false, 'handler' => HandlerStack::create(new MockHandler([new Response($status, [], '{}')]))]);
    $client = (new ReflectionClass(ForgeApiClient::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($client, 'forge'))->setValue($client, new ForgeInventoryClient('token', $http));
    (new ReflectionProperty($client, 'organizationSlug'))->setValue($client, 'org');

    return $client;
}

it('treats only explicit certificate absence as a missing certificate', function (): void {
    expect(certificateLookupClient(404)->activeCertificate('7', '12', '13'))->toBeNull();
});

it('propagates certificate permission failures instead of inviting creation', function (): void {
    certificateLookupClient(403)->activeCertificate('7', '12', '13');
})->throws(ForbiddenException::class);

it('rejects malformed successful certificate responses instead of inviting creation', function (): void {
    certificateLookupClient(200)->activeCertificate('7', '12', '13');
})->throws(RuntimeException::class, 'invalid active certificate identity');

it('rejects repeated Forge cursors instead of continuing an inventory loop', function (string $method, array $cursors): void {
    $responses = [];
    foreach ($cursors as $index => $cursor) {
        $responses[] = new Response(200, [], json_encode(['data' => [['id' => (string) ($index + 1),
            'type' => 'resource', 'attributes' => ['name' => 'fixture-'.$index, 'command' => 'fixture-'.$index]]],
            'meta' => ['next_cursor' => $cursor]]));
    }
    $client = forgeSuccessClient($responses);
    $args = $method === 'domains' ? ['7', '12'] : ['7'];
    expect(fn () => $client->{$method}(...$args))->toThrow(RuntimeException::class, 'repeated pagination cursor');
})->with(['sites', 'databases', 'domains', 'backgroundProcesses', 'scheduledJobs'])
    ->with(['self loop' => [['synthetic-cursor', 'synthetic-cursor']],
        'cycle' => [['synthetic-first', 'synthetic-second', 'synthetic-first']]]);

it('preserves unique cursor chains including empty intermediate pages', function (string $method): void {
    $responses = [new Response(200, [], '{"data":[],"meta":{"next_cursor":"first"}}')];
    foreach ([['second', '1'], [null, '2']] as [$cursor, $id]) {
        $responses[] = new Response(200, [], json_encode(['data' => [['id' => $id, 'type' => 'resource',
            'attributes' => ['name' => 'fixture-'.$id, 'command' => 'fixture-'.$id]]], 'meta' => ['next_cursor' => $cursor]]));
    }
    $client = forgeSuccessClient($responses);
    $args = $method === 'domains' ? ['7', '12'] : ['7'];
    expect(array_column($client->{$method}(...$args), 'id'))->toBe([1, 2]);
})->with(['sites', 'databases', 'domains', 'backgroundProcesses', 'scheduledJobs']);

it('preserves existing secrets and serializes literal environment values', function (): void {
    $client = (new ReflectionClass(ForgeApiClient::class))->newInstanceWithoutConstructor();
    $merge = new ReflectionMethod($client, 'mergeEnvironment');
    $result = $merge->invoke($client, "DB_PASSWORD=retained\nAPP_ENV=local\n", [
        'APP_ENV' => 'production',
        'TOKEN' => '$literal',
        'QUOTE' => "'literal'",
        'MULTILINE' => "first\nsecond",
    ]);

    expect($result)->toContain('DB_PASSWORD=retained')
        ->toContain('APP_ENV=production')
        ->not->toContain('APP_ENV=local')
        ->toContain('TOKEN="\$literal"')
        ->toContain('QUOTE="\'literal\'"')
        ->toContain('MULTILINE="first\nsecond"');
});

it('rejects environment names that could inject additional assignments', function (): void {
    $client = (new ReflectionClass(ForgeApiClient::class))->newInstanceWithoutConstructor();
    (new ReflectionMethod($client, 'mergeEnvironment'))->invoke($client, '', ["BAD\nNAME" => 'value']);
})->throws(InvalidArgumentException::class);

it('replaces complete multiline and exported assignments while preserving other content', function (): void {
    $client = (new ReflectionClass(ForgeApiClient::class))->newInstanceWithoutConstructor();
    $existing = "# retained comment\nexport TOKEN=\"old\nvalue\"\nKEEP='untouched'\n";
    $result = (new ReflectionMethod($client, 'mergeEnvironment'))->invoke($client, $existing, [
        'TOKEN' => 'replacement',
        'PATH_VALUE' => 'C:\\deployment\\app',
        'SECRET' => "literal\$value # with \"quotes\"\nand newline",
    ]);

    expect(\Dotenv\Dotenv::parse($result))->toBe([
        'TOKEN' => 'replacement',
        'KEEP' => 'untouched',
        'PATH_VALUE' => 'C:\\deployment\\app',
        'SECRET' => "literal\$value # with \"quotes\"\nand newline",
    ])->and($result)->toContain('# retained comment');
});

it('refuses malformed existing content before rendering a replacement', function (string $existing): void {
    $client = (new ReflectionClass(ForgeApiClient::class))->newInstanceWithoutConstructor();
    expect(fn () => (new ReflectionMethod($client, 'mergeEnvironment'))->invoke($client, $existing, ['TOKEN' => 'replacement']))
        ->toThrow(RuntimeException::class, 'Cannot parse the existing Forge site environment before updating it.');
})->with(['TOKEN="unfinished', "KEEP=retained\nBROKEN='unfinished", "INVALID NAME=value\n"]);

function forgeResourceResponseCall(object $client, string $method): mixed
{
    return match ($method) {
        'createSite' => $client->createSite('7', ['domain' => 'fixture.test']),
        'createDatabase' => $client->createDatabase('7', ['name' => 'fixture']),
        'createDomain' => $client->createDomain('7', '12', ['name' => 'fixture.test']),
        'createBackgroundProcess' => $client->createBackgroundProcess('7', ['command' => 'fixture']),
        'createScheduledJob' => $client->createScheduledJob('7', ['command' => 'fixture']),
        'createCertificate' => $client->createCertificate('7', '12', '13', []),
        'activeCertificate' => $client->activeCertificate('7', '12', '13'),
    };
}

it('rejects malformed create and certificate identities before SDK hydration', function (string $method, string $body): void {
    $client = forgeSuccessClient([new Response(200, [], $body)]);
    expect(fn () => forgeResourceResponseCall($client, $method))->toThrow(RuntimeException::class, 'invalid');
})->with(['createSite', 'createDatabase', 'createDomain', 'createBackgroundProcess', 'createScheduledJob', 'createCertificate', 'activeCertificate'])
    ->with(['{"data":{"id":21.5,"attributes":{"name":"fixture.test","command":"fixture"}}}',
        '{"data":{"id":"21","attributes":{"id":22,"name":"fixture.test","command":"fixture"}}}',
        '{"data":{"id":"21","attributes":{"name":"fixture.test","command":"fixture"}},"errors":["synthetic-secret"]}',
        '{}', '{"data":[]}', '{"data":null}']);

it('preserves valid create and certificate acknowledgements', function (string $method): void {
    $body = '{"data":{"id":"21","type":"resource","attributes":{"name":"fixture.test","command":"fixture","active":true,"status":"installed"}}}';
    $result = forgeResourceResponseCall(forgeSuccessClient([new Response(200, [], $body), new Response(200, [], $body)]), $method);
    if ($method === 'createCertificate') expect($result)->toBeNull();
    else expect($result['id'])->toBe(21);
})->with(['createSite', 'createDatabase', 'createDomain', 'createBackgroundProcess', 'createScheduledJob', 'createCertificate', 'activeCertificate']);

it('rejects detail responses for a different requested identity', function (string $path): void {
    $http = new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
        new Response(200, [], '{"data":{"id":"22","attributes":{"name":"fixture"}}}'),
    ]))]);
    $sdk = new \ShipperCli\ProviderForge\ForgeInventoryClient('synthetic', $http);
    expect(fn () => $sdk->get($path))->toThrow(RuntimeException::class, 'invalid resource response envelope');
})->with(['orgs/org/servers/7/sites/21', 'orgs/org/servers/7/database/schemas/21',
    'orgs/org/servers/7/background-processes/21', 'orgs/org/servers/7/scheduled-jobs/21',
    'orgs/org/servers/7/sites/12/domains/21']);

it('rejects a created site acknowledgement for another domain', function (): void {
    $response = new Response(200, [], '{"data":{"id":"21","attributes":{"name":"other.test"}}}');
    expect(fn () => forgeSuccessClient([$response])->createSite('7', ['domain' => 'fixture.test']))
        ->toThrow(RuntimeException::class, 'requested domain');
});

function forgeStatusFixture(string $body): ForgeApiClient
{
    $client = (new ReflectionClass(ForgeApiClient::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($client, 'organizationSlug'))->setValue($client, 'org');
    (new ReflectionProperty($client, 'forge'))->setValue($client, new ForgeInventoryClient('token', new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], $body)]))])));
    return $client;
}

it('omits raw response extras from Forge deployment status', function (): void {
    $client = forgeStatusFixture(json_encode(['data' => [
        'id' => '21', 'type' => 'deploymentStatuses',
        'attributes' => ['status' => 'finished', 'started_at' => '2026-10-16T12:00:00Z', 'env' => 'synthetic-status-secret'],
        'logs' => 'synthetic-status-secret',
    ]]));
    $status = $client->status('7', '55');
    expect(json_encode($status))->not->toContain('synthetic-status-secret')
        ->and($status)->toBe(['id' => '21', 'type' => 'deploymentStatuses', 'attributes' => ['status' => 'finished', 'started_at' => '2026-10-16T12:00:00Z']]);
});

it('rejects unreadable or failed deployment status envelopes', function (string $body): void {
    expect(fn () => forgeStatusFixture($body)->status('7', '55'))
        ->toThrow(RuntimeException::class, 'Forge returned an invalid deployment status response.');
})->with(['{}', '{"data":[]}', '{"data":{"id":"21","type":"deploymentStatuses","attributes":{}}}',
    '{"success":false,"data":{"id":"21","type":"deploymentStatuses","attributes":{"status":"finished","started_at":null}}}',
    '{"data":{"id":"21","type":"deploymentStatuses","attributes":{"status":"unknown","started_at":null}}}']);


it('preserves a documented explicit null deployment status', function (): void {
    $status = forgeStatusFixture('{"data":{"id":"21","type":"deploymentStatuses","attributes":{"status":null,"started_at":"2026-10-16T12:00:00Z"}}}')->status('7', '55');
    expect($status['attributes']['status'])->toBeNull();
});

it('rejects incomplete resource identities before reconciliation matching', function (): void {
    $client = (new ReflectionClass(ForgeApiClient::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($client, 'resources');
    foreach (['name', 'command'] as $field) {
        foreach ([['id' => 12], ['id' => 0, $field => 'example'], [$field => 'example']] as $record) {
            try {
                $method->invoke($client, [(object) $record], $field);
                throw new LogicException('Invalid inventory was accepted.');
            } catch (RuntimeException $exception) {
                expect($exception->getMessage())->toContain('reconciliation identity');
            }
        }
        expect($method->invoke($client, [(object) ['id' => 12, $field => 'example']], $field))
            ->toBe([['id' => 12, $field => 'example']]);
    }
});

it('rejects Forge site records without valid identities', function (array $data): void {
    $client = (new ReflectionClass(ForgeApiClient::class))->newInstanceWithoutConstructor();
    (new ReflectionMethod($client, 'siteData'))->invoke($client, (object) $data);
})->with(array_map(static fn (array $record): array => [$record], [[], ['id' => 12], ['name' => 'example.test'], ['id' => 0, 'name' => 'example.test'], ['id' => 'invalid', 'name' => 'example.test'], ['id' => 12, 'name' => '']]))->throws(RuntimeException::class, 'valid identity');

it('rejects invalid Forge collections instead of treating them as empty', function (mixed $data): void {
    $client = (new ReflectionClass(ForgeApiClient::class))->newInstanceWithoutConstructor();
    (new ReflectionMethod($client, 'iterable'))->invoke($client, $data);
})->with([null, 'invalid', false, new stdClass])->throws(RuntimeException::class, 'invalid resource collection');

it('accepts explicit empty collections and valid site identities', function (): void {
    $client = (new ReflectionClass(ForgeApiClient::class))->newInstanceWithoutConstructor();
    expect((new ReflectionMethod($client, 'iterable'))->invoke($client, []))->toBe([]);
    $data = (new ReflectionMethod($client, 'siteData'))->invoke($client, (object) ['id' => 12, 'name' => 'example.test']);
    expect($data['id'])->toBe(12)->and($data['name'])->toBe('example.test');
});
