<?php

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Laravel\Forge\Exceptions\RateLimitExceededException;
use ShipperCli\ProviderForge\ForgeInventoryClient;

it('validates reconciliation collection envelopes through the real SDK methods', function (string $method): void {
    $http = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], '{}')]))]);
    $sdk = new ForgeInventoryClient('token', $http);
    $args = $method === 'domains' ? ['org', 7, 12] : ['org', 7];
    iterator_to_array($sdk->{$method}(...$args)->lazy());
})->with(['databases', 'domains', 'backgroundProcesses', 'scheduledJobs'])->throws(RuntimeException::class, 'invalid resource inventory envelope');

it('rejects malformed site envelopes before SDK collection normalization', function (string $body): void {
    $http = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], $body)]))]);
    $sdk = new ForgeInventoryClient('token', $http);
    iterator_to_array($sdk->serverSites('org', 7)->lazy());
})->with(['{}', '{"message":"unexpected"}', '{"data":null}', '{"data":{"unexpected":[]}}'])->throws(RuntimeException::class, 'invalid resource inventory envelope');

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
})->throws(RuntimeException::class, 'invalid resource inventory envelope');

it('rejects JSON shape loss and contradictory inventory errors before SDK normalization', function (string $body): void {
    $http = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], $body)]))]);
    $sdk = new ForgeInventoryClient('token', $http);
    expect(fn () => iterator_to_array($sdk->serverSites('org', 7)->lazy()))
        ->toThrow(RuntimeException::class, 'invalid resource inventory envelope');
})->with([
    '{"data":{}}', '{"data":{"0":{"id":"1"}}}', '{"data":[[]]}',
    '{"data":[],"errors":["synthetic-secret"]}', '{"data":[],"error":"synthetic-secret"}',
    '{"data":[],"success":false}', '{"data":[],"meta":[]}',
    '{"data":[],"meta":{"next_cursor":42}}', '{"data":[],"meta":{"next_cursor":""}}',
]);

it('preserves plaintext responses outside protected inventory endpoints', function (): void {
    $http = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], 'server configuration text')]))]);
    $sdk = new ForgeInventoryClient('token', $http);
    expect($sdk->get('fixture/plaintext'))->toBe('server configuration text');
});

it('enforces request policy independently of injected client defaults', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"data":[]}')]));
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'allow_redirects' => true, 'http_errors' => true, 'timeout' => 99]);
    $sdk = (new ForgeInventoryClient('synthetic', $http))->setTimeout(3);
    expect($sdk->get('fixture'))->toBe(['data' => []])
        ->and($history[0]['options']['allow_redirects'])->toBeFalse()
        ->and($history[0]['options']['http_errors'])->toBeFalse()
        ->and($history[0]['options']['timeout'])->toBe(3);
});

it('preserves a numeric rate limit reset without error body data', function (): void {
    $stack = HandlerStack::create(new MockHandler([new Response(429, ['x-ratelimit-reset' => '1234567890'], 'synthetic-secret')]));
    $sdk = new ForgeInventoryClient('synthetic', new Client(['handler' => $stack]));
    $failure = null;
    try { $sdk->get('fixture'); } catch (RateLimitExceededException $exception) { $failure = $exception; }
    expect($failure)->toBeInstanceOf(RateLimitExceededException::class)
        ->and($failure->rateLimitResetsAt)->toBe(1234567890)
        ->and($failure->getMessage())->not->toContain('synthetic-secret');
});
