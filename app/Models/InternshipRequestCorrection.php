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

#[Fillable(['internship_request_id', 'message', 'affected_sections', 'status', 'responded_at', 'resolved_at'])]
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
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    protected static function booted(): void
    {
        static::saving(function (self $correction): void {
            if (is_string($correction->message)) {
                $correction->message = trim($correction->message);
            }

            $status = $correction->status;
            $validator = Validator::make([...$correction->getAttributes(), 'affected_sections' => $correction->affected_sections], [
                'internship_request_id' => ['required', 'integer', Rule::exists(InternshipRequest::class, 'id')],
                'message' => ['required', 'string'],
                'affected_sections' => ['required', 'array', 'min:1'],
                'affected_sections.*' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_]*\z/'],
                'status' => ['required', Rule::enum(InternshipRequestCorrectionStatus::class)],
                'responded_at' => [in_array($status, [InternshipRequestCorrectionStatus::Responded, InternshipRequestCorrectionStatus::Resolved], true) ? 'required' : 'nullable', 'date'],
                'resolved_at' => [in_array($status, [InternshipRequestCorrectionStatus::Resolved, InternshipRequestCorrectionStatus::Cancelled], true) ? 'required' : 'nullable', 'date'],
            ]);

            $validator->after(function (LaravelValidator $validator) use ($correction): void {
                $sections = $correction->affected_sections;
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
