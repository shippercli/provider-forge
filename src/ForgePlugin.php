<?php

declare(strict_types=1);

namespace ShipperCli\ProviderForge;

use ShipperCli\Contracts\ShipperPluginInterface;

final class ForgePlugin implements ShipperPluginInterface
{
    /**
     * @return array<class-string, class-string>
     */
    public function providers(): array
    {
        return [];
    }
}