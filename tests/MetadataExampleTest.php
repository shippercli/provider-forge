<?php

use ShipperCli\ProviderForge\ForgeProvider;

it('website metadata example validates after replacing environment placeholders', function (): void {
    $metadata = json_decode(file_get_contents(__DIR__.'/../meta.json'), true, 512, JSON_THROW_ON_ERROR);
    $config = array_map(static fn ($value) => is_string($value) ? strtr($value, [
        '${FORGE_API_TOKEN}' => 'synthetic-token', '${FORGE_ORGANIZATION_SLUG}' => 'example-organization',
    ]) : $value, $metadata['config']);
    $project = new class { public function name(): string { return 'fixture'; } };
    $profile = new class { public function get(string $key): mixed { return $key === 'domain' ? 'fixture.test' : null; } };
    $client = $this->createMock(\ShipperCli\ProviderForge\ForgeClientInterface::class);
    foreach (['sites', 'createSite', 'deploy', 'deleteSite'] as $method) $client->expects($this->never())->method($method);
    expect((new ForgeProvider($config, $client))->validate($project, $profile))->toBe([]);
    expect($config['deployment_timeout'] ?? null)->toBe(300);
});
