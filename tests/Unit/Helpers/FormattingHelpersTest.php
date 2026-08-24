<?php

use App\Helpers\DateHelper;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

test('date helpers support immutable dates and the configured timezone', function () {
    config()->set('app.timezone', 'America/Sao_Paulo');

    $date = CarbonImmutable::parse('2024-01-10 03:00:00', 'UTC');

    expect(formatDateTime($date))->toBe('10/01/2024 às 00:00')
        ->and(DateHelper::formatShort($date))->toBe('10/01/2024');
});

test('document helpers show a placeholder for blank values', function () {
    expect(formatCpf('   '))->toBe('-')
        ->and(formatCnpj('   '))->toBe('-')
        ->and(formatPhone('   '))->toBe('-')
        ->and(formatCep('   '))->toBe('-');
});

test('formats Brazilian phone numbers with country code', function () {
    expect(formatPhone('5511998765432'))->toBe('+55 (11) 99876-5432');
});

test('preserves international phone numbers without the Brazilian country code', function () {
    expect(formatPhone('991234567890'))->toBe('991234567890');
});
