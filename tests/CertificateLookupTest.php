<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Laravel\Forge\Exceptions\ForbiddenException;
use ShipperCli\ProviderForge\ForgeApiClient;
use ShipperCli\ProviderForge\ForgeInventoryClient;

function certificateLookupClient(int $status): ForgeApiClient
{
    $http = new Client(['http_errors' => false, 'handler' => HandlerStack::create(new MockHandler([new Response($status, [], '{}')]))]);
    $client = (new ReflectionClass(ForgeApiClient::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($client, 'forge'))->setValue($client, new ForgeInventoryClient('token', $http));
    (new ReflectionProperty($client, 'organizationSlug'))->setValue($client, 'org');

    return $client;
}

it('treats only explicit certificate absence as a missing certificate', function (): void {
    expect(certificateLookupClient(404)->activeCertificate('7', '12', '13'))->toBeNull();
});

it('propagates certificate permission failures instead of inviting creation', function (): void {
    certificateLookupClient(403)->activeCertificate('7', '12', '13');
})->throws(ForbiddenException::class);

it('rejects malformed successful certificate responses instead of inviting creation', function (): void {
    certificateLookupClient(200)->activeCertificate('7', '12', '13');
})->throws(RuntimeException::class, 'invalid active certificate identity');
