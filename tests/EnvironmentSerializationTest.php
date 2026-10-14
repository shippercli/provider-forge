<?php

use ShipperCli\ProviderForge\ForgeApiClient;

it('preserves existing secrets and serializes literal environment values', function (): void {
    $client = (new ReflectionClass(ForgeApiClient::class))->newInstanceWithoutConstructor();
    $merge = new ReflectionMethod($client, 'mergeEnvironment');
    $result = $merge->invoke($client, "DB_PASSWORD=retained\nAPP_ENV=local\n", [
        'APP_ENV' => 'production',
        'TOKEN' => '$literal',
        'QUOTE' => "'literal'",
        'MULTILINE' => "first\nsecond",
    ]);

    expect($result)->toContain('DB_PASSWORD=retained')
        ->toContain('APP_ENV=production')
        ->not->toContain('APP_ENV=local')
        ->toContain('TOKEN="\$literal"')
        ->toContain('QUOTE="\'literal\'"')
        ->toContain('MULTILINE="first\nsecond"');
});

it('rejects environment names that could inject additional assignments', function (): void {
    $client = (new ReflectionClass(ForgeApiClient::class))->newInstanceWithoutConstructor();
    (new ReflectionMethod($client, 'mergeEnvironment'))->invoke($client, '', ["BAD\nNAME" => 'value']);
})->throws(InvalidArgumentException::class);
