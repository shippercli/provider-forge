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

foreach (['cursor-self', 'cursor-cycle', 'cursor-valid'] as $case) {
    foreach (['sites', 'databases', 'domains', 'backgroundProcesses', 'scheduledJobs'] as $method) {
        $sdk = new ForgeInventoryClient('synthetic-authorization-secret', new Client(['base_uri' => $argv[1].'/',
            'headers' => ['Authorization' => 'Bearer synthetic-authorization-secret', 'X-Fixture-Case' => $case]]));
        $adapter = (new ReflectionClass(\ShipperCli\ProviderForge\ForgeApiClient::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($adapter, 'forge'))->setValue($adapter, $sdk);
        (new ReflectionProperty($adapter, 'organizationSlug'))->setValue($adapter, 'org');
        $failure = null;
        try { $items = $adapter->{$method}(...($method === 'domains' ? ['7', '12'] : ['7'])); }
        catch (Throwable $exception) { $failure = $exception; }
        if ($case === 'cursor-valid') {
            if ($failure !== null || array_column($items, 'id') !== [1, 2, 3]) throw new RuntimeException('Valid cursor chain changed');
        } elseif (!$failure instanceof RuntimeException || $failure->getMessage() !== 'Forge returned a repeated pagination cursor.') {
            throw new RuntimeException('Forge cursor cycle accepted: '.$case.'/'.$method);
        }
    }
}
echo "Ten real HTTP cursor cycle rejections and five valid chain controls passed\n";

foreach (['sites', 'databases', 'domains', 'backgroundProcesses', 'scheduledJobs', 'duplicate-site'] as $scenario) {
    $method = $scenario === 'duplicate-site' ? 'sites' : $scenario;
    $sdk = new ForgeInventoryClient('synthetic-authorization-secret', new Client(['base_uri' => $argv[1].'/',
        'headers' => ['Authorization' => 'Bearer synthetic-authorization-secret',
            'X-Fixture-Case' => $scenario === 'duplicate-site' ? 'duplicate-site' : 'duplicate-id']]));
    $adapter = (new ReflectionClass(\ShipperCli\ProviderForge\ForgeApiClient::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($adapter, 'forge'))->setValue($adapter, $sdk);
    (new ReflectionProperty($adapter, 'organizationSlug'))->setValue($adapter, 'org');
    $failure = null;
    try { $adapter->{$method}(...($method === 'domains' ? ['7', '12'] : ['7'])); }
    catch (RuntimeException $exception) { $failure = $exception; }
    $expected = $method === 'sites' ? 'Forge returned a duplicate site identity.' : 'Forge returned a duplicate resource identity.';
    if ($failure === null || $failure->getMessage() !== $expected) throw new RuntimeException('Duplicate Forge identity accepted: '.$scenario);
}
echo "Six real HTTP duplicate identity rejections passed\n";

foreach (['fraction', 'boolean', 'junk', 'exponent', 'padded', 'leading-zero', 'null', 'shadow', 'shadow-fraction', 'valid-int', 'valid-string', 'valid-same'] as $case) {
    $methods = in_array($case, ['shadow', 'shadow-fraction', 'valid-int', 'valid-string', 'valid-same'], true)
        ? ['sites'] : ['sites', 'databases', 'domains', 'backgroundProcesses', 'scheduledJobs'];
    foreach ($methods as $method) {
        $sdk = new ForgeInventoryClient('synthetic-authorization-secret', new Client(['base_uri' => $argv[1].'/',
            'headers' => ['Authorization' => 'Bearer synthetic-authorization-secret', 'X-Fixture-Case' => 'raw-id-'.$case]]));
        $adapter = (new ReflectionClass(\ShipperCli\ProviderForge\ForgeApiClient::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($adapter, 'forge'))->setValue($adapter, $sdk);
        (new ReflectionProperty($adapter, 'organizationSlug'))->setValue($adapter, 'org');
        $failure = null;
        try { $items = $adapter->{$method}(...($method === 'domains' ? ['7', '12'] : ['7'])); }
        catch (Throwable $exception) { $failure = $exception; }
        if (str_starts_with($case, 'valid-')) {
            if ($failure !== null || array_column($items, 'id') !== [21]) throw new RuntimeException('Valid raw identity changed');
        } elseif (!$failure instanceof RuntimeException || $failure->getMessage() !== 'Forge returned an invalid resource inventory envelope.') {
            throw new RuntimeException('Malformed raw Forge identity accepted: '.$case.'/'.$method);
        }
    }
}
echo "37 real HTTP raw identity rejections and three valid controls passed\n";

foreach (['fraction', 'shadow', 'errors', 'missing', 'list', 'null', 'valid'] as $case) {
    foreach (['createSite', 'createDatabase', 'createDomain', 'createBackgroundProcess', 'createScheduledJob', 'createCertificate', 'activeCertificate'] as $method) {
        $sdk = new ForgeInventoryClient('synthetic-authorization-secret', new Client(['base_uri' => $argv[1].'/',
            'headers' => ['Authorization' => 'Bearer synthetic-authorization-secret', 'X-Fixture-Case' => 'resource-'.$case]]));
        $adapter = (new ReflectionClass(\ShipperCli\ProviderForge\ForgeApiClient::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($adapter, 'forge'))->setValue($adapter, $sdk);
        (new ReflectionProperty($adapter, 'organizationSlug'))->setValue($adapter, 'org');
        $failure = null;
        try {
            $result = match ($method) {
                'createSite' => $adapter->createSite('7', ['domain' => 'fixture.test']),
                'createDatabase' => $adapter->createDatabase('7', ['name' => 'fixture']),
                'createDomain' => $adapter->createDomain('7', '12', ['name' => 'fixture.test']),
                'createBackgroundProcess' => $adapter->createBackgroundProcess('7', ['command' => 'fixture']),
                'createScheduledJob' => $adapter->createScheduledJob('7', ['command' => 'fixture']),
                'createCertificate' => $adapter->createCertificate('7', '12', '13', []),
                'activeCertificate' => $adapter->activeCertificate('7', '12', '13'),
            };
        } catch (Throwable $exception) { $failure = $exception; }
        if ($case === 'valid') {
            if ($failure !== null || ($method !== 'createCertificate' && ($result['id'] ?? null) !== 21)) throw new RuntimeException('Valid resource response changed');
        } else {
            $expected = $method === 'activeCertificate' ? 'Forge returned an invalid active certificate identity.' : 'Forge returned an invalid resource response envelope.';
            if (!$failure instanceof RuntimeException || $failure->getMessage() !== $expected) throw new RuntimeException('Invalid resource response accepted: '.$case.'/'.$method);
        }
    }
}
foreach (['sites/21', 'database/schemas/21', 'background-processes/21', 'scheduled-jobs/21', 'sites/12/domains/21'] as $path) {
    $sdk = new ForgeInventoryClient('synthetic-authorization-secret', new Client(['base_uri' => $argv[1].'/',
        'headers' => ['Authorization' => 'Bearer synthetic-authorization-secret', 'X-Fixture-Case' => 'resource-foreign']]));
    $failure = null;
    try { $sdk->get('orgs/org/servers/7/'.$path); } catch (RuntimeException $exception) { $failure = $exception; }
    if ($failure === null || $failure->getMessage() !== 'Forge returned an invalid resource response envelope.') throw new RuntimeException('Foreign detail identity accepted');
}
echo "Resource creation, certificate and matching-detail HTTP checks passed\n";

foreach (['wrong-domain', 'domain-case'] as $case) {
    $sdk = new ForgeInventoryClient('synthetic-authorization-secret', new Client(['base_uri' => $argv[1].'/',
        'headers' => ['Authorization' => 'Bearer synthetic-authorization-secret', 'X-Fixture-Case' => 'resource-'.$case]]));
    $adapter = (new ReflectionClass(\ShipperCli\ProviderForge\ForgeApiClient::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($adapter, 'forge'))->setValue($adapter, $sdk);
    (new ReflectionProperty($adapter, 'organizationSlug'))->setValue($adapter, 'org');
    $failure = null;
    try { $site = $adapter->createSite('7', ['domain' => 'fixture.test']); }
    catch (RuntimeException $exception) { $failure = $exception; }
    if ($case === 'domain-case') {
        if ($failure !== null || ($site['id'] ?? null) !== 21) throw new RuntimeException('Case-equivalent created domain rejected');
    } elseif ($failure === null || $failure->getMessage() !== 'Forge created site did not confirm the requested domain.') {
        throw new RuntimeException('Foreign created site domain accepted');
    }
}
echo "Created site domain mismatch rejection and case-equivalent control passed\n";


foreach (['valid', 'foreign', 'missing-name'] as $case) {
    $sdk = new ForgeInventoryClient('synthetic-authorization-secret', new Client([
        'base_uri' => $argv[1].'/', 'headers' => ['X-Fixture-Case' => 'database-create-'.$case, 'Authorization' => 'Bearer synthetic-authorization-secret'],
    ]));
    $adapter = (new ReflectionClass(\ShipperCli\ProviderForge\ForgeApiClient::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($adapter, 'forge'))->setValue($adapter, $sdk);
    (new ReflectionProperty($adapter, 'organizationSlug'))->setValue($adapter, $case);
    $project = new class {
        public function databases(): array { return [new class {
            public function name(): string { return 'main'; }
            public function user(): string { return 'application'; }
        }]; }
    };
    $provider = new \ShipperCli\ProviderForge\ForgeProvider(['server_id' => 7, 'database_password' => 'synthetic-database-secret']);
    $failure = null;
    try {
        (new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $adapter, $project, new stdClass);
    } catch (RuntimeException $error) { $failure = $error; }
    $environment = (new ReflectionProperty($provider, 'generatedEnvironment'))->getValue($provider);
    if ($case === 'valid') {
        if ($failure !== null || ($environment['DB_DATABASE'] ?? null) !== 'main'
            || ($environment['DB_PASSWORD'] ?? null) !== 'synthetic-database-secret') {
            throw new RuntimeException('Valid Forge database creation failed');
        }
    } elseif ($failure?->getMessage() !== 'Forge did not confirm the requested database creation identity.' || $environment !== []) {
        throw new RuntimeException('Invalid Forge database creation was accepted');
    }
}
echo "Three real HTTP provider database creation cases passed\n";
