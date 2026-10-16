<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use GuzzleHttp\Client;
use Laravel\Forge\Exceptions\ValidationException;
use ShipperCli\ProviderForge\ForgeInventoryClient;

$http = new Client(['base_uri' => $argv[1].'/', 'http_errors' => false,
    'headers' => ['Authorization' => 'Bearer synthetic-authorization-secret']]);
$client = new ForgeInventoryClient('synthetic-authorization-secret', $http);
foreach ([301, 302, 303, 307, 308, 400, 401, 403, 404, 422, 429, 500, 502] as $status) {
    foreach (['json', 'text'] as $format) {
        foreach (['get', 'post'] as $method) {
            $failure = null;
            try {
                $client->{$method}("status-{$status}-{$format}", $method === 'post' ? ['password' => 'synthetic-body-secret'] : []);
            } catch (Throwable $exception) { $failure = $exception; }
            if ($failure === null) throw new RuntimeException("Non-success accepted: {$status}/{$format}/{$method}");
            $diagnostic = $failure->getMessage();
            if ($failure instanceof ValidationException) $diagnostic .= json_encode($failure->errors());
            foreach (['synthetic-authorization-secret', 'synthetic-body-secret', 'synthetic-error-secret'] as $secret) {
                if (str_contains($diagnostic, $secret)) throw new RuntimeException('Error response leaked synthetic credentials');
            }
        }
    }
}
foreach (['direct', 'nested'] as $format) {
    $data = $client->get('success-'.$format);
    $expected = $format === 'direct' ? ['json' => ['accepted' => true]] : ['result' => ['data' => ['json' => ['accepted' => true]]]];
    if ($data !== $expected) throw new RuntimeException('Successful response decoding changed');
}
echo "52 HTTP rejection checks and two success controls passed\n";

foreach (['object', 'numeric-object', 'array-record', 'errors', 'error', 'false', 'meta-array', 'cursor-number', 'cursor-empty', 'valid', 'valid-no-meta'] as $case) {
    $sdk = new ForgeInventoryClient('synthetic-authorization-secret', new Client(['base_uri' => $argv[1].'/',
        'headers' => ['Authorization' => 'Bearer synthetic-authorization-secret', 'X-Fixture-Case' => $case]]));
    $failure = null;
    try { $items = iterator_to_array($sdk->serverSites('org', 7)->lazy()); }
    catch (Throwable $exception) { $failure = $exception; }
    if (str_starts_with($case, 'valid')) {
        if ($failure !== null || $items !== []) throw new RuntimeException('Valid empty Forge inventory changed');
    } elseif (!$failure instanceof RuntimeException || $failure->getMessage() !== 'Forge returned an invalid resource inventory envelope.') {
        throw new RuntimeException('Malformed Forge inventory accepted or exposed: '.$case);
    }
}
echo "Nine real HTTP inventory shape rejections and two valid controls passed\n";
