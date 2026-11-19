<?php

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Laravel\Forge\Exceptions\RateLimitExceededException;
use ShipperCli\ProviderForge\ForgeInventoryClient;

it('redacts underlying transport failure details', function (): void {
    $failure = new \GuzzleHttp\Exception\ConnectException(
        'synthetic connection detail',
        new \GuzzleHttp\Psr7\Request('GET', 'https://forge.example.test/fixture'),
    );
    $http = new Client(['handler' => HandlerStack::create(new MockHandler([$failure]))]);
    $sdk = new ForgeInventoryClient('token', $http);

    expect(fn () => $sdk->get('fixture/plaintext'))->toThrow(
        RuntimeException::class,
        'Forge API transport failed; check connectivity, TLS, and timeout settings.',
    );
});
