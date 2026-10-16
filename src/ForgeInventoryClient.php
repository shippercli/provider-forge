<?php

declare(strict_types=1);

namespace ShipperCli\ProviderForge;

use Laravel\Forge\Forge;
use RuntimeException;

/** Preserve the difference between empty inventory and an unreadable response. */
final class ForgeInventoryClient extends Forge
{
    public function get(string $uri, array $query = []): mixed
    {
        $response = parent::get($uri, $query);
        if (preg_match('#\Aorgs/[^/]+/servers/[0-9]+/(?:sites|database/schemas|background-processes|scheduled-jobs|sites/[0-9]+/domains)\z#', $uri) === 1
            && (! is_array($response) || ! is_array($response['data'] ?? null) || ! array_is_list($response['data']))) {
            throw new RuntimeException('Forge returned an invalid resource inventory envelope.');
        }

        return $response;
    }
}
