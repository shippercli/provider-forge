<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use ShipperCli\ProviderForge\ForgeApiClient;
use ShipperCli\ProviderForge\ForgeInventoryClient;

function forgeDeploymentFixture(array $responses, array &$history): ForgeApiClient
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $client = (new ReflectionClass(ForgeApiClient::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($client, 'forge'))->setValue($client, new ForgeInventoryClient('token', new Client(['handler' => $stack])));
    (new ReflectionProperty($client, 'organizationSlug'))->setValue($client, 'org');
    (new ReflectionProperty($client, 'deploymentPollMilliseconds'))->setValue($client, 0);
    return $client;
}

function forgeDeploymentResponse(string $status, string $id = '21'): Response
{
    return new Response(200, [], json_encode(['data' => ['id' => $id, 'type' => 'deployments', 'attributes' => ['status' => $status]]]));
}

it('waits for the created Forge deployment and propagates terminal failure', function (string $status, bool $success): void {
    $history = [];
    $client = forgeDeploymentFixture([forgeDeploymentResponse('queued'), forgeDeploymentResponse($status)], $history);
    $failure = null;
    try { $client->deploy('7', '55'); }
    catch (RuntimeException $error) { $failure = $error; }
    expect($failure === null)->toBe($success)->and($history)->toHaveCount(2);
    expect((string) $history[1]['request']->getUri())->toEndWith('/servers/7/sites/55/deployments/21');
})->with([['finished', true], ['failed', false], ['failed-build', false], ['cancelled', false]]);


it('tracks queued and deploying states without switching deployment identity', function (): void {
    $history = [];
    $client = forgeDeploymentFixture([forgeDeploymentResponse('queued'), forgeDeploymentResponse('deploying'), forgeDeploymentResponse('finished')], $history);
    $client->deploy('7', '55');
    expect($history)->toHaveCount(3);
    foreach (array_slice($history, 1) as $request) {
        expect((string) $request['request']->getUri())->toEndWith('/deployments/21');
    }
});

it('rejects malformed deployment acknowledgments before SDK hydration', function (string $body): void {
    $history = [];
    $client = forgeDeploymentFixture([new Response(200, [], $body)], $history);
    expect(fn () => $client->deploy('7', '55'))->toThrow(RuntimeException::class, 'invalid deployment response');
    expect($history)->toHaveCount(1);
})->with(['{}', '{"data":{"id":"21junk","type":"deployments","attributes":{"status":"finished"}}}',
    '{"data":{"id":"21","type":"deployments","attributes":{"status":"unknown"}}}',
    '{"success":false,"data":{"id":"21","type":"deployments","attributes":{"status":"finished"}}}',
    '{"data":{"id":"21","type":"deployments","attributes":{"status":"finished"},"relationships":{"site":{"data":{"id":"56","type":"sites"}}}}}']);

it('rejects a different deployment identity while polling', function (): void {
    $history = [];
    $client = forgeDeploymentFixture([forgeDeploymentResponse('queued'), forgeDeploymentResponse('finished', '22')], $history);
    expect(fn () => $client->deploy('7', '55'))->toThrow(RuntimeException::class, 'invalid deployment response');
});

it('rejects a finished response arriving after its deployment deadline', function (): void {
    $history = [];
    $client = forgeDeploymentFixture([forgeDeploymentResponse('queued'), static function (): Response {
        usleep(1_100_000);
        return forgeDeploymentResponse('finished');
    }], $history);
    (new ReflectionProperty($client, 'deploymentTimeout'))->setValue($client, 1);
    expect(fn () => $client->deploy('7', '55'))->toThrow(RuntimeException::class, 'timed out');
    expect($history)->toHaveCount(2);
});

it('validates Forge deployment timeout before remote operations', function (mixed $timeout): void {
    $client = $this->createMock(\ShipperCli\ProviderForge\ForgeClientInterface::class);
    $client->expects($this->never())->method('sites');
    $provider = new \ShipperCli\ProviderForge\ForgeProvider(['deployment_timeout' => $timeout], $client);
    expect($provider->validate(new stdClass, new stdClass))->toContain('Forge deployment timeout must be between 1 and 3600 seconds.');
    expect(fn () => $provider->plan(new stdClass, new stdClass))->toThrow(InvalidArgumentException::class);
    expect($provider->apply(new stdClass, new stdClass))->toBeFalse();
})->with([[0], [-1], [3601], ['30'], [true], [null], [1.5]]);


it('does not poll after the deployment deadline expires during sleep', function (): void {
    $history = [];
    $client = forgeDeploymentFixture([forgeDeploymentResponse('queued')], $history);
    (new ReflectionProperty($client, 'deploymentTimeout'))->setValue($client, 1);
    (new ReflectionProperty($client, 'deploymentPollMilliseconds'))->setValue($client, 1000);
    expect(fn () => $client->deploy('7', '55'))->toThrow(RuntimeException::class, 'timed out');
    expect($history)->toHaveCount(1);
});
