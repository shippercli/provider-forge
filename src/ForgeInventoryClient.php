<?php

declare(strict_types=1);

namespace ShipperCli\ProviderForge;

use Laravel\Forge\Forge;
use RuntimeException;

/** Preserve the difference between empty inventory and an unreadable response. */
final class ForgeInventoryClient extends Forge
{
    protected function request(string $verb, string $uri, array $payload = [], array $query = []): mixed
    {
        $options = ['allow_redirects' => false, 'http_errors' => false, 'timeout' => $this->timeout];
        if ($payload !== []) $options['json'] = $payload;
        if ($query !== []) $options['query'] = $query;
        try {
            $response = $this->guzzle->request($verb, $uri, $options);
        } catch (\GuzzleHttp\Exception\GuzzleException) {
            throw new RuntimeException('Forge API transport failed; check connectivity, TLS, and timeout settings.');
        }
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            // Retain SDK exception classes without exposing echoed request data.
            $headers = [];
            $reset = $response->getHeaderLine('x-ratelimit-reset');
            if (preg_match('/\A[0-9]{1,12}\z/', $reset) === 1) {
                $headers['x-ratelimit-reset'] = $reset;
            }
            $safe = new \GuzzleHttp\Psr7\Response($status, $headers, json_encode([
                'message' => 'Forge API request failed (HTTP '.$status.').',
            ], JSON_THROW_ON_ERROR));
            return $this->handleRequestError($safe);
        }
        $body = (string) $response->getBody();
        if ($body === '') return null;
        $decoded = json_decode($body, true);
        return $decoded === null ? $body : $decoded;
    }

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
