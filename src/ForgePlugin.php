<?php

declare(strict_types=1);

namespace ShipperCli\ProviderForge;

use ShipperCli\Contracts\ShipperPluginInterface;

final class ForgePlugin implements ShipperPluginInterface
{
    public function providers(): array
    {
        return ['forge' => ForgeProvider::class];
    }
}
