<?php

use App\Enums\HolidayScope;
use Tests\TestCase;

uses(TestCase::class);

test('defines holiday scope cases, values, labels and options', function () {
    expect(HolidayScope::cases())->toBe([
        HolidayScope::National,
        HolidayScope::State,
        HolidayScope::Municipal,
    ])
        ->and(HolidayScope::values())->toBe(['national', 'state', 'municipal'])
        ->and(HolidayScope::options())->toBe([
            'national' => 'Nacional',
            'state' => 'Estadual',
            'municipal' => 'Municipal',
        ])
        ->and(HolidayScope::National->label())->toBe('Nacional')
        ->and(HolidayScope::State->label())->toBe('Estadual')
        ->and(HolidayScope::Municipal->label())->toBe('Municipal');
});
