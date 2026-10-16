<?php

declare(strict_types=1);

namespace ShipperCli\ProviderForge;

use Laravel\Forge\Forge;
use RuntimeException;

/** Preserve the difference between an empty site list and an unreadable one. */
final class ForgeInventoryClient extends Forge
{
    public function get(string $uri, array $query = []): mixed
    {
        $response = parent::get($uri, $query);
        if (preg_match('#\Aorgs/[^/]+/servers/[0-9]+/sites\z#', $uri) === 1
            && (! is_array($response) || ! is_array($response['data'] ?? null) || ! array_is_list($response['data']))) {
            throw new RuntimeException('Forge returned an invalid site inventory envelope.');
        }

        return $response;
    }
}
