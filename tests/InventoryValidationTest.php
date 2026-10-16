<?php

use ShipperCli\ProviderForge\ForgeApiClient;
use ShipperCli\ProviderForge\ForgeInventoryClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

it('rejects malformed site envelopes before SDK collection normalization', function (string $body): void {
    $http = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], $body)]))]);
    $sdk = new ForgeInventoryClient('token', $http);
    iterator_to_array($sdk->serverSites('org', 7)->lazy());
})->with(['{}', '{"message":"unexpected"}', '{"data":null}', '{"data":{"unexpected":[]}}'])->throws(RuntimeException::class, 'invalid site inventory envelope');

it('accepts an explicit empty site envelope through the SDK', function (): void {
    $http = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], '{"data":[],"meta":{"next_cursor":null}}')]))]);
    $sdk = new ForgeInventoryClient('token', $http);
    expect(iterator_to_array($sdk->serverSites('org', 7)->lazy()))->toBe([]);
});

it('rejects a malformed later cursor page instead of accepting partial inventory', function (): void {
    $http = new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, [], '{"data":[],"meta":{"next_cursor":"next-page"}}'),
        new Response(200, [], '{"message":"unexpected"}'),
    ]))]);
    $sdk = new ForgeInventoryClient('token', $http);
    iterator_to_array($sdk->serverSites('org', 7)->lazy());
})->throws(RuntimeException::class, 'invalid site inventory envelope');

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
