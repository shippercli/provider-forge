<?php

use GuzzleHttp\Psr7\Response;

it('rejects repeated Forge cursors instead of continuing an inventory loop', function (string $method, array $cursors): void {
    $responses = [];
    foreach ($cursors as $index => $cursor) {
        $responses[] = new Response(200, [], json_encode(['data' => [['id' => (string) ($index + 1),
            'type' => 'resource', 'attributes' => ['name' => 'fixture-'.$index, 'command' => 'fixture-'.$index]]],
            'meta' => ['next_cursor' => $cursor]]));
    }
    $client = forgeSuccessClient($responses);
    $args = $method === 'domains' ? ['7', '12'] : ['7'];
    expect(fn () => $client->{$method}(...$args))->toThrow(RuntimeException::class, 'repeated pagination cursor');
})->with(['sites', 'databases', 'domains', 'backgroundProcesses', 'scheduledJobs'])
    ->with(['self loop' => [['synthetic-cursor', 'synthetic-cursor']],
        'cycle' => [['synthetic-first', 'synthetic-second', 'synthetic-first']]]);

it('preserves unique cursor chains including empty intermediate pages', function (string $method): void {
    $responses = [new Response(200, [], '{"data":[],"meta":{"next_cursor":"first"}}')];
    foreach ([['second', '1'], [null, '2']] as [$cursor, $id]) {
        $responses[] = new Response(200, [], json_encode(['data' => [['id' => $id, 'type' => 'resource',
            'attributes' => ['name' => 'fixture-'.$id, 'command' => 'fixture-'.$id]]], 'meta' => ['next_cursor' => $cursor]]));
    }
    $client = forgeSuccessClient($responses);
    $args = $method === 'domains' ? ['7', '12'] : ['7'];
    expect(array_column($client->{$method}(...$args), 'id'))->toBe([1, 2]);
})->with(['sites', 'databases', 'domains', 'backgroundProcesses', 'scheduledJobs']);
