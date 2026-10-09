<?php

namespace App\Http\Requests;

use App\Models\Course;
use App\Support\ActiveAffiliationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

abstract class CourseFormRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    abstract public function rules(): array;

    /** @return array<string, array<int, mixed>> */
    protected function courseRules(): array
    {
        $course = $this->route('course');
        $course = $course instanceof Course ? clone $course : new Course;
        $affiliation = app(ActiveAffiliationContext::class)->currentFor($this->user(), app('session.store'));
        $course->fill($this->only(['name', 'primary_coordinator_affiliation_id', 'secondary_coordinator_affiliation_id']));
        $course->campus_id = $affiliation?->campus_id;

        return Arr::only($course->validationRules(), ['name', 'primary_coordinator_affiliation_id', 'secondary_coordinator_affiliation_id']);
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token', '_method']) as $key) {
                $validator->errors()->add((string) $key, 'O formulário contém um campo não permitido.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome do curso.',
            'name.max' => 'O nome do curso não pode ultrapassar :max caracteres.',
            'primary_coordinator_affiliation_id.exists' => 'Selecione um vínculo ativo de Coordenador deste campus.',
            'secondary_coordinator_affiliation_id.exists' => 'Selecione um vínculo ativo de Coordenador deste campus.',
            'secondary_coordinator_affiliation_id.different' => 'Os coordenadores principal e secundário devem ser distintos.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nome do curso',
            'primary_coordinator_affiliation_id' => 'coordenador principal',
            'secondary_coordinator_affiliation_id' => 'coordenador secundário',
        ];
    }
}
