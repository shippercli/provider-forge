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
        if (\strtoupper($verb) === 'GET' && $this->isInventoryUri($uri)) {
            $shape = \json_decode($body);
            if (! $shape instanceof \stdClass || ! \is_array($shape->data ?? null)
                || ($shape->errors ?? []) !== []
                || ! \in_array($shape->error ?? null, [null, '', false], true)
                || (\property_exists($shape, 'success') && $shape->success !== true)) {
                throw new RuntimeException('Forge returned an invalid resource inventory envelope.');
            }
            foreach ($shape->data as $item) {
                if (! $item instanceof \stdClass || $this->resourceId($item->id ?? null) === null) {
                    throw new RuntimeException('Forge returned an invalid resource inventory envelope.');
                }
                $attributes = $item->attributes ?? null;
                if ($attributes instanceof \stdClass && \property_exists($attributes, 'id')
                    && $this->resourceId($attributes->id) !== $this->resourceId($item->id)) {
                    throw new RuntimeException('Forge returned an invalid resource inventory envelope.');
                }
            }
            if (\property_exists($shape, 'meta')) {
                $meta = $shape->meta;
                if (! $meta instanceof \stdClass || (\property_exists($meta, 'next_cursor')
                    && $meta->next_cursor !== null && (! \is_string($meta->next_cursor)
                        || $meta->next_cursor === '' || \trim($meta->next_cursor) !== $meta->next_cursor))) {
                    throw new RuntimeException('Forge returned an invalid resource inventory envelope.');
                }
            }
        }
        $isCreate = \strtoupper($verb) === 'POST' && ($this->isInventoryUri($uri)
            || \preg_match('#\Aorgs/[^/]+/servers/[0-9]+/sites/[0-9]+/domains/[0-9]+/certificates\z#', $uri) === 1);
        $isCertificate = \strtoupper($verb) === 'GET'
            && \preg_match('#\Aorgs/[^/]+/servers/[0-9]+/sites/[0-9]+/domains/[0-9]+/certificates/active\z#', $uri) === 1;
        $detail = [];
        $isDetail = \strtoupper($verb) === 'GET'
            && \preg_match('#\A(orgs/[^/]+/servers(?:/[0-9]+/(?:sites|database/schemas|background-processes|scheduled-jobs|sites/[0-9]+/domains))?)/([0-9]+)\z#', $uri, $detail) === 1;
        $isServerDetail = $isDetail && preg_match('#\Aorgs/[^/]+/servers\z#', $detail[1]) === 1;
        if ($isCreate || $isCertificate || $isDetail) {
            $shape = \json_decode($body);
            $data = $shape->data ?? null;
            $id = $data instanceof \stdClass ? $this->resourceId($data->id ?? null) : null;
            $attributes = $data->attributes ?? null;
            if (! $shape instanceof \stdClass || $id === null || ($shape->errors ?? []) !== []
                || ! \in_array($shape->error ?? null, [null, '', false], true)
                || (\property_exists($shape, 'success') && $shape->success !== true)
                || ($attributes instanceof \stdClass && \property_exists($attributes, 'id')
                    && $this->resourceId($attributes->id) !== $id)
                || ($isDetail && $id !== $this->resourceId($detail[2]))
                || ($isServerDetail && (! $attributes instanceof \stdClass
                    || ! is_string($attributes->database_type ?? null)
                    || preg_match('/\A(?:mysql|mariadb|postgres)[0-9]+\z/', $attributes->database_type) !== 1))) {
                throw new RuntimeException($isCertificate ? 'Forge returned an invalid active certificate identity.'
                    : 'Forge returned an invalid resource response envelope.');
            }
        }
        if ($body === '') return null;
        $decoded = json_decode($body, true);
        return $decoded === null ? $body : $decoded;
    }

    public function get(string $uri, array $query = []): mixed
    {
        $response = parent::get($uri, $query);
        if ($this->isInventoryUri($uri)
            && (! is_array($response) || ! is_array($response['data'] ?? null) || ! array_is_list($response['data']))) {
            throw new RuntimeException('Forge returned an invalid resource inventory envelope.');
        }

        return $response;
    }
    private function resourceId(mixed $value): ?int
    {
        $id = (\is_int($value) || (\is_string($value) && \preg_match('/^[1-9][0-9]*$/D', $value) === 1))
            ? \filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        return $id === false ? null : $id;
    }

    private function isInventoryUri(string $uri): bool
    {
        return \preg_match('#\Aorgs/[^/]+/servers/[0-9]+/(?:sites|database/schemas|background-processes|scheduled-jobs|sites/[0-9]+/domains)\z#', $uri) === 1;
    }

}
