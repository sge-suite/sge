<?php

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

uses(TestCase::class);

test('blocks migration commands when the database driver is not PostgreSQL', function () {
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', ':memory:');
    DB::purge('sqlite');

    expect(fn () => event(new CommandStarting(
        'migrate',
        new ArrayInput([]),
        new NullOutput,
    )))->toThrow(RuntimeException::class, 'As migrations requerem PostgreSQL.');
});
