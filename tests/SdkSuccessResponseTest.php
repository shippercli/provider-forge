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
