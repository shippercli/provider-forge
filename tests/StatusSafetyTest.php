<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use ShipperCli\ProviderForge\ForgeApiClient;
use ShipperCli\ProviderForge\ForgeInventoryClient;

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
