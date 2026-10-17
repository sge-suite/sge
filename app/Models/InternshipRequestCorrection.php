<?php

namespace App\Models;

use App\Enums\InternshipRequestCorrectionStatus;
use Database\Factories\InternshipRequestCorrectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $internship_request_id
 * @property string $message
 * @property string|null $general_message
 * @property list<string> $affected_sections
 * @property array<string, string>|null $section_messages
 * @property InternshipRequestCorrectionStatus $status
 */
#[Fillable(['internship_request_id', 'message', 'general_message', 'affected_sections', 'section_messages', 'status', 'responded_at', 'resolved_at'])]
class InternshipRequestCorrection extends Model
{
    /** @use HasFactory<InternshipRequestCorrectionFactory> */
    use HasFactory;

    use LogsActivity;

    protected $attributes = ['status' => 'open'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'internship_request_id' => 'integer',
            'affected_sections' => 'array',
            'section_messages' => 'array',
            'status' => InternshipRequestCorrectionStatus::class,
            'responded_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<InternshipRequest, $this> */
    public function internshipRequest(): BelongsTo
    {
        return $this->belongsTo(InternshipRequest::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    public static function sectionLabel(string $section): string
    {
        return match ($section) {
            'personal_data' => 'Dados pessoais',
            'internship' => 'Tipo, ano/semestre e orientador',
            'granting_party' => 'Concedente',
            'supervisor' => 'Supervisor',
            'activities' => 'Atividades e setor',
            'schedule' => 'Jornada e pausas previstas',
            'planned_start_date' => 'Data de início',
            'legal_capacity' => 'Capacidade civil e comprovante',
            'remuneration' => 'Remuneração',
            default => $section,
        };
    }

    protected static function booted(): void
    {
        static::saving(function (self $correction): void {
            $message = $correction->getAttribute('message');
            if (is_string($message)) {
                $correction->message = trim($message);
            }
            $generalMessage = $correction->getAttribute('general_message');
            if (is_string($generalMessage)) {
                $correction->general_message = trim($generalMessage) ?: null;
            }

            $status = $correction->status;
            $validator = Validator::make([...$correction->getAttributes(), 'affected_sections' => $correction->affected_sections, 'section_messages' => $correction->section_messages], [
                'internship_request_id' => ['required', 'integer', Rule::exists(InternshipRequest::class, 'id')],
                'message' => ['required', 'string'],
                'general_message' => ['nullable', 'string', 'max:5000'],
                'affected_sections' => ['required', 'array', 'min:1'],
                'affected_sections.*' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_]*\z/'],
                'section_messages' => ['nullable', 'array'],
                'section_messages.*' => ['required', 'string', 'max:5000'],
                'status' => ['required', Rule::enum(InternshipRequestCorrectionStatus::class)],
                'responded_at' => [in_array($status, [InternshipRequestCorrectionStatus::Responded, InternshipRequestCorrectionStatus::Resolved], true) ? 'required' : 'nullable', 'date'],
                'resolved_at' => [in_array($status, [InternshipRequestCorrectionStatus::Resolved, InternshipRequestCorrectionStatus::Cancelled], true) ? 'required' : 'nullable', 'date'],
            ]);

            $validator->after(function (LaravelValidator $validator) use ($correction): void {
                $sections = $correction->getAttribute('affected_sections');
                if (is_array($sections) && (array_is_list($sections) === false || count($sections) !== count(array_unique($sections)))) {
                    $validator->errors()->add('affected_sections', 'As seções devem ser uma lista sem repetições.');
                }

                if ($correction->status === InternshipRequestCorrectionStatus::Open) {
                    $anotherOpen = self::query()->where('internship_request_id', $correction->internship_request_id)
                        ->where('status', InternshipRequestCorrectionStatus::Open->value)
                        ->when($correction->exists, fn ($query) => $query->where('id', '!=', $correction->id))
                        ->exists();
                    if ($anotherOpen) {
                        $validator->errors()->add('status', 'Já existe uma correção aberta para esta solicitação.');
                    }
                }
            });

            $validator->validate();
        });

        static::deleting(function (): void {
            throw ValidationException::withMessages(['correction' => 'Correções não podem ser excluídas fisicamente.']);
        });
    }
}
