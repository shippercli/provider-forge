<?php

use ShipperCli\ProviderForge\ForgePlugin;
use ShipperCli\ProviderForge\ForgeProvider;

it('registers the forge provider through the plugin manifest', function () {
    expect((new ForgePlugin())->providers())->toBe([
        'forge' => ForgeProvider::class,
    ]);
});

it('publishes the current forge capability states', function () {
    $capabilities = (new ForgeProvider())->capabilities();

    expect($capabilities['app_deploy']['state'])->toBe('partial')
        ->and($capabilities['server_lifecycle']['state'])->toBe('unsupported')
        ->and($capabilities['profiles']['state'])->toBe('supported');
});

it('fails closed when apply is not implemented', function () {
    $provider = new ForgeProvider();

    expect($provider->apply(new stdClass(), new stdClass()))->toBeFalse()
        ->and($provider->getLastError())->toBe('Forge apply is not implemented');
});

it('fails closed when destroy is not implemented', function () {
    $provider = new ForgeProvider();

    expect($provider->destroy(new stdClass(), new stdClass()))->toBeFalse()
        ->and($provider->getLastError())->toBe('Forge destroy is not implemented');
});
