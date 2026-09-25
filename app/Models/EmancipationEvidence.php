<?php

namespace App\Models;

use App\Enums\EmancipationEvidenceStatus;
use Database\Factories\EmancipationEvidenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable(['internship_request_id', 'status', 'reviewed_at', 'return_reason'])]
class EmancipationEvidence extends Model implements HasMedia
{
    /** @use HasFactory<EmancipationEvidenceFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use LogsActivity;

    protected $table = 'emancipation_evidences';

    protected $attributes = ['status' => 'submitted'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'internship_request_id' => 'integer',
            'status' => EmancipationEvidenceStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<InternshipRequest, $this> */
    public function internshipRequest(): BelongsTo
    {
        return $this->belongsTo(InternshipRequest::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('emancipation_evidence')
            ->useDisk('local')
            ->singleFile()
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png'])
            ->acceptsFile(fn (): bool => ! $this->media()->where('collection_name', 'emancipation_evidence')->exists());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['internship_request_id', 'status', 'reviewed_at', 'return_reason'])
            ->logOnlyDirty();
    }

    protected static function booted(): void
    {
        static::saving(function (self $evidence): void {
            if (is_string($evidence->return_reason)) {
                $evidence->return_reason = trim($evidence->return_reason) ?: null;
            }

            $reviewed = in_array($evidence->status, [EmancipationEvidenceStatus::Approved, EmancipationEvidenceStatus::Returned], true);
            Validator::make($evidence->getAttributes(), [
                'internship_request_id' => ['required', 'integer', Rule::exists(InternshipRequest::class, 'id')],
                'status' => ['required', Rule::enum(EmancipationEvidenceStatus::class)],
                'reviewed_at' => [$reviewed ? 'required' : 'nullable', 'date'],
                'return_reason' => [$evidence->status === EmancipationEvidenceStatus::Returned ? 'required' : 'nullable', 'string'],
            ])->validate();

            if ($reviewed && ! $evidence->media()->where('collection_name', 'emancipation_evidence')->exists()) {
                throw ValidationException::withMessages(['evidence' => 'A análise exige o comprovante privado anexado.']);
            }
        });

        static::deleting(function (): void {
            throw ValidationException::withMessages(['evidence' => 'Comprovantes enviados não podem ser excluídos fisicamente.']);
        });
    }
}
