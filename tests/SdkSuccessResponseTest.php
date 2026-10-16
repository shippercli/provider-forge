<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
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
