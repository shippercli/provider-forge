<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Laravel\Forge\Exceptions\RateLimitExceededException;
use ShipperCli\ProviderForge\ForgeInventoryClient;

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
