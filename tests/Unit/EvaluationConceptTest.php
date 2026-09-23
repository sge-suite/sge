<?php

use App\Enums\EvaluationConcept;
use Tests\TestCase;

uses(TestCase::class);

test('defines localized evaluation concept labels and stored values', function () {
    expect(EvaluationConcept::values())->toBe([
        'excellent', 'very_good', 'good', 'satisfactory', 'unsatisfactory',
    ])
        ->and(EvaluationConcept::options())->toBe([
            'excellent' => 'Ótimo',
            'very_good' => 'Muito bom',
            'good' => 'Bom',
            'satisfactory' => 'Satisfatório',
            'unsatisfactory' => 'Insatisfatório',
        ])
        ->and(EvaluationConcept::Excellent->internshipTypeValueAttribute())->toBeNull()
        ->and(EvaluationConcept::Unsatisfactory->internshipTypeValueAttribute())->toBe('unsatisfactory_value');
});
