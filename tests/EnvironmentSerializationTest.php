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

it('replaces complete multiline and exported assignments while preserving other content', function (): void {
    $client = (new ReflectionClass(ForgeApiClient::class))->newInstanceWithoutConstructor();
    $existing = "# retained comment\nexport TOKEN=\"old\nvalue\"\nKEEP='untouched'\n";
    $result = (new ReflectionMethod($client, 'mergeEnvironment'))->invoke($client, $existing, [
        'TOKEN' => 'replacement',
        'PATH_VALUE' => 'C:\\deployment\\app',
        'SECRET' => "literal\$value # with \"quotes\"\nand newline",
    ]);

    expect(\Dotenv\Dotenv::parse($result))->toBe([
        'TOKEN' => 'replacement',
        'KEEP' => 'untouched',
        'PATH_VALUE' => 'C:\\deployment\\app',
        'SECRET' => "literal\$value # with \"quotes\"\nand newline",
    ])->and($result)->toContain('# retained comment');
});

it('refuses malformed existing content before rendering a replacement', function (string $existing): void {
    $client = (new ReflectionClass(ForgeApiClient::class))->newInstanceWithoutConstructor();
    expect(fn () => (new ReflectionMethod($client, 'mergeEnvironment'))->invoke($client, $existing, ['TOKEN' => 'replacement']))
        ->toThrow(RuntimeException::class, 'Cannot parse the existing Forge site environment before updating it.');
})->with(['TOKEN="unfinished', "KEEP=retained\nBROKEN='unfinished", "INVALID NAME=value\n"]);
