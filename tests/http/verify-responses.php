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


foreach (['valid', 'valid-explicit', 'foreign', 'missing-name', 'engine-mismatch', 'engine-unknown'] as $case) {
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
    $profile = new class($case) {
        public function __construct(private string $case) {}
        public function environment(): object { return new class($this->case) {
            public function __construct(private string $case) {}
            public function variables(): array { return $this->case === 'valid-explicit'
                ? ['DB_PASSWORD' => 'literal-${PROJECT_NAME}-${PROFILE}-+%:@'] : []; }
        }; }
    };
    $failure = null;
    try {
        (new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $adapter, $project, $profile);
    } catch (RuntimeException $error) { $failure = $error; }
    $environment = (new ReflectionProperty($provider, 'generatedEnvironment'))->getValue($provider);
    if (in_array($case, ['valid', 'valid-explicit'], true)) {
        $expectedPassword = $case === 'valid-explicit' ? 'literal-${PROJECT_NAME}-${PROFILE}-+%:@' : 'synthetic-database-secret';
        if ($failure !== null || ($environment['DB_DATABASE'] ?? null) !== 'main'
            || ($environment['DB_PASSWORD'] ?? null) !== $expectedPassword) {
            throw new RuntimeException('Valid Forge database creation failed');
        }
    } elseif (in_array($case, ['engine-mismatch', 'engine-unknown'], true)) {
        $expectedError = $case === 'engine-mismatch' ? 'Forge database declaration does not match the server database engine.'
            : 'Forge returned an invalid resource response envelope.';
        if ($failure?->getMessage() !== $expectedError || $environment !== []) throw new RuntimeException('Invalid server engine accepted');
    } elseif ($failure?->getMessage() !== 'Forge did not confirm the requested database creation identity.' || $environment !== []) {
        throw new RuntimeException('Invalid Forge database creation was accepted');
    }
}
echo "Six real HTTP provider database creation/engine cases passed\n";


$partialSdk = new ForgeInventoryClient('synthetic-authorization-secret', new Client([
    'base_uri' => $argv[1].'/', 'headers' => ['X-Fixture-Case' => 'partial-recovery', 'Authorization' => 'Bearer synthetic-authorization-secret'],
]));
$partialAdapter = (new ReflectionClass(\ShipperCli\ProviderForge\ForgeApiClient::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($partialAdapter, 'forge'))->setValue($partialAdapter, $partialSdk);
(new ReflectionProperty($partialAdapter, 'organizationSlug'))->setValue($partialAdapter, 'partial');
$partialProject = new class {
    public function databases(): array { return array_map(static fn ($name) => new class($name) {
        public function __construct(private string $name) {}
        public function name(): string { return $this->name; }
        public function user(): string { return $this->name.'_user'; }
    }, ['main', 'analytics']); }
};
$partialOptions = ['server_id' => 7, 'database_password' => 'literal-${PROJECT_NAME}-${PROFILE}',
    'database_environment_prefixes' => ['main' => 'DB', 'analytics' => 'ANALYTICS_DB']];
$partialFailure = null;
try {
    $provider = new \ShipperCli\ProviderForge\ForgeProvider($partialOptions);
    (new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $partialAdapter, $partialProject, new stdClass, '55');
} catch (Throwable $error) { $partialFailure = $error; }
$partialEnvironment = \Dotenv\Dotenv::parse($partialAdapter->environment('7', '55'));
if ($partialFailure === null || ($partialEnvironment['DB_PASSWORD'] ?? null) !== $partialOptions['database_password']
    || isset($partialEnvironment['ANALYTICS_DB_PASSWORD'])) throw new RuntimeException('Confirmed first connection was lost after later creation failure');
$retryProvider = new \ShipperCli\ProviderForge\ForgeProvider($partialOptions);
(new ReflectionMethod($retryProvider, 'applyDatabases'))->invoke($retryProvider, $partialAdapter, $partialProject, new stdClass, '55');
$finalEnvironment = \Dotenv\Dotenv::parse($partialAdapter->environment('7', '55'));
if (($finalEnvironment['DB_PASSWORD'] ?? null) !== $partialOptions['database_password']
    || ($finalEnvironment['ANALYTICS_DB_PASSWORD'] ?? null) !== $partialOptions['database_password']
    || ($finalEnvironment['APP_ENV'] ?? null) !== 'production') throw new RuntimeException('Partial database retry did not preserve connection values');
echo "Confirmed first connection survives second creation failure and fresh provider retry over real HTTP\n";


foreach (['double', 'single', 'name'] as $suffix) {
    $sdk = new ForgeInventoryClient('synthetic-authorization-secret', new Client(['base_uri' => $argv[1].'/',
        'headers' => ['X-Fixture-Case' => 'malformed-env-'.$suffix, 'Authorization' => 'Bearer synthetic-authorization-secret']]));
    $adapter = (new ReflectionClass(\ShipperCli\ProviderForge\ForgeApiClient::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($adapter, 'forge'))->setValue($adapter, $sdk);
    (new ReflectionProperty($adapter, 'organizationSlug'))->setValue($adapter, 'org');
    $failure = null;
    try { $adapter->updateEnvironment('7', '55', ['TOKEN' => 'replacement']); }
    catch (RuntimeException $error) { $failure = $error; }
    if ($failure?->getMessage() !== 'Cannot parse the existing Forge site environment before updating it.' || $failure->getPrevious() !== null) {
        throw new RuntimeException('Malformed existing environment was accepted or leaked diagnostics');
    }
}
echo "Three malformed existing environments refused before HTTP update\n";


foreach (['valid', 'ignored'] as $mode) {
    $sdk = new ForgeInventoryClient('synthetic-authorization-secret', new Client(['base_uri' => $argv[1].'/',
        'headers' => ['X-Fixture-Case' => 'readback-env-'.$mode, 'Authorization' => 'Bearer synthetic-authorization-secret']]));
    $adapter = (new ReflectionClass(\ShipperCli\ProviderForge\ForgeApiClient::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($adapter, 'forge'))->setValue($adapter, $sdk);
    (new ReflectionProperty($adapter, 'organizationSlug'))->setValue($adapter, 'org');
    $failure = null;
    try { $adapter->updateEnvironment('7', '55', ['TOKEN' => 'literal-${PROJECT_NAME}-secret', 'EMPTY' => '']); }
    catch (RuntimeException $error) { $failure = $error; }
    if (($failure === null) !== ($mode === 'valid')
        || ($failure !== null && $failure->getMessage() !== 'Forge did not confirm the requested environment configuration.')) {
        throw new RuntimeException('Environment readback acceptance incorrect');
    }
}
echo "Real HTTP environment persistence readback controls passed\n";


foreach (['missing', 'attributes', 'null', 'number', 'false', 'errors', 'valid'] as $case) {
    $sdk = new ForgeInventoryClient('synthetic-authorization-secret', new Client(['base_uri' => $argv[1].'/',
        'headers' => ['X-Fixture-Case' => 'raw-env-'.$case, 'Authorization' => 'Bearer synthetic-authorization-secret']]));
    $failure = null;
    $content = null;
    try { $content = $sdk->siteEnvironment('org', 7, 55); }
    catch (RuntimeException $error) { $failure = $error; }
    if ($case === 'valid') {
        if ($failure !== null || $content !== '') throw new RuntimeException('Explicit empty environment rejected');
    } elseif ($failure?->getMessage() !== 'Forge returned an invalid site environment envelope.' || $failure->getPrevious() !== null) {
        throw new RuntimeException('Unreadable environment accepted or leaked diagnostics');
    }
}
echo "Seven raw environment envelope real HTTP controls passed\n";


foreach (['finished', 'failed', 'failed-build', 'cancelled', 'wrong-id', 'progress'] as $result) {
    $sdk = new ForgeInventoryClient('synthetic-authorization-secret', new Client(['base_uri' => $argv[1].'/',
        'headers' => ['X-Fixture-Case' => 'deployment-result-'.$result, 'Authorization' => 'Bearer synthetic-authorization-secret']]));
    $adapter = (new ReflectionClass(\ShipperCli\ProviderForge\ForgeApiClient::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($adapter, 'forge'))->setValue($adapter, $sdk);
    (new ReflectionProperty($adapter, 'organizationSlug'))->setValue($adapter, 'org');
    (new ReflectionProperty($adapter, 'deploymentPollMilliseconds'))->setValue($adapter, 0);
    $failure = null;
    try { $adapter->deploy('7', '55'); }
    catch (RuntimeException $error) { $failure = $error; }
    if (($failure === null) !== in_array($result, ['finished', 'progress'], true)) {
        throw new RuntimeException('Deployment result was reported incorrectly');
    }
}
echo "Six real SDK exact-deployment completion scenarios passed\n";


foreach (['valid', 'null', 'missing', 'false', 'unknown', 'missing-time'] as $case) {
    $sdk = new ForgeInventoryClient('synthetic-authorization-secret', new Client(['base_uri' => $argv[1].'/',
        'headers' => ['X-Fixture-Case' => 'status-summary-'.$case, 'Authorization' => 'Bearer synthetic-authorization-secret']]));
    $adapter = (new ReflectionClass(\ShipperCli\ProviderForge\ForgeApiClient::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($adapter, 'forge'))->setValue($adapter, $sdk);
    (new ReflectionProperty($adapter, 'organizationSlug'))->setValue($adapter, 'org');
    $failure = null;
    $status = null;
    try { $status = $adapter->status('7', '55'); }
    catch (RuntimeException $error) { $failure = $error; }
    if (in_array($case, ['valid', 'null'], true)) {
        if ($failure !== null || str_contains(json_encode($status), 'synthetic-status-secret')
            || array_keys($status) !== ['id', 'type', 'attributes']
            || array_keys($status['attributes']) !== ['status', 'started_at']) {
            throw new RuntimeException('Status exposed raw fields or lost its documented shape');
        }
    } elseif ($failure?->getMessage() !== 'Forge returned an invalid deployment status response.') {
        throw new RuntimeException('Invalid deployment status response accepted');
    }
}
echo "Six deployment status summary real SDK controls passed\n";
