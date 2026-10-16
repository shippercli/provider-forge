<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use ShipperCli\ProviderForge\ForgeApiClient;
use ShipperCli\ProviderForge\ForgeInventoryClient;

it('requires persisted Forge environment values after an acknowledged write', function (string $mode): void {
    $variables = ['TOKEN' => 'literal-${PROJECT_NAME}-secret', 'EMPTY' => ''];
    $client = (new ReflectionClass(ForgeApiClient::class))->newInstanceWithoutConstructor();
    $expected = (new ReflectionMethod($client, 'mergeEnvironment'))->invoke($client, "KEEP=retained\n", $variables);
    $body = fn (string $content) => json_encode(['data' => ['attributes' => ['content' => $content]]]);
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], $body("KEEP=retained\n")), new Response(200, [], '{}'),
        new Response(200, [], $mode === 'unreadable' ? '{}' : $body($mode === 'ignored' ? "KEEP=retained\n" : $expected)),
    ]));
    $history = [];
    $stack->push(Middleware::history($history));
    (new ReflectionProperty($client, 'forge'))->setValue($client, new ForgeInventoryClient('token', new Client(['handler' => $stack])));
    (new ReflectionProperty($client, 'organizationSlug'))->setValue($client, 'org');
    $failure = null;
    try { $client->updateEnvironment('7', '55', $variables); }
    catch (RuntimeException $error) { $failure = $error; }
    expect($failure === null)->toBe($mode === 'valid')->and($history)->toHaveCount(3);
    if ($failure !== null) {
        expect($failure->getMessage())->not->toContain('literal-${PROJECT_NAME}-secret');
    }
})->with(['ignored', 'unreadable', 'valid']);
