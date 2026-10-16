<?php

use GuzzleHttp\Psr7\Response;

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
