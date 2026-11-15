<?php

use ShipperCli\ProviderForge\ForgePlugin;
use ShipperCli\ProviderForge\ForgeProvider;

it('registers the forge provider through the plugin manifest', function () {
    expect((new ForgePlugin())->providers())->toBe([
        'forge' => ForgeProvider::class,
    ]);
});
