<?php

namespace App\Models;

use App\Enums\InternshipCancellationRequestStatus;
use App\Enums\InternshipStatus;
use Database\Factories\InternshipCancellationRequestFactory;
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

#[Fillable(['internship_id', 'reason', 'status', 'reviewed_at', 'decision_reason', 'effective_date'])]
class InternshipCancellationRequest extends Model
{
    /** @use HasFactory<InternshipCancellationRequestFactory> */
    use HasFactory;

    use LogsActivity;

    protected $attributes = ['status' => 'submitted'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'internship_id' => 'integer',
            'status' => InternshipCancellationRequestStatus::class,
            'reviewed_at' => 'datetime',
            'effective_date' => 'date',
        ];
    }

    /** @return BelongsTo<Internship, $this> */
    public function internship(): BelongsTo
    {
        return $this->belongsTo(Internship::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    protected static function booted(): void
    {
        static::saving(function (self $request): void {
            foreach (['reason', 'decision_reason'] as $field) {
                if (is_string($request->{$field})) {
                    $request->{$field} = trim($request->{$field}) ?: null;
                }
            }

            $approved = $request->status === InternshipCancellationRequestStatus::Approved;
            $rejected = $request->status === InternshipCancellationRequestStatus::Rejected;
            $validator = Validator::make($request->getAttributes(), [
                'internship_id' => ['required', 'integer', Rule::exists(Internship::class, 'id')],
                'reason' => ['required', 'string'],
                'status' => ['required', Rule::enum(InternshipCancellationRequestStatus::class)],
                'reviewed_at' => [$approved || $rejected ? 'required' : 'nullable', 'date'],
                'decision_reason' => [$rejected ? 'required' : 'nullable', 'string'],
                'effective_date' => [$approved ? 'required' : 'prohibited', 'nullable', 'date'],
            ]);

            $validator->after(function (LaravelValidator $validator) use ($request): void {
                $internship = Internship::find($request->internship_id);
                if ($internship && ! $request->exists && in_array($internship->status, [InternshipStatus::Completed, InternshipStatus::Cancelled], true)) {
                    $validator->errors()->add('internship_id', 'Este estágio não aceita pedido de cancelamento.');
                }

                if (in_array($request->status, [InternshipCancellationRequestStatus::Submitted, InternshipCancellationRequestStatus::UnderReview], true)) {
                    $anotherPending = self::query()->where('internship_id', $request->internship_id)
                        ->whereIn('status', [InternshipCancellationRequestStatus::Submitted->value, InternshipCancellationRequestStatus::UnderReview->value])
                        ->when($request->exists, fn ($query) => $query->where('id', '!=', $request->id))
                        ->exists();
                    if ($anotherPending) {
                        $validator->errors()->add('status', 'Já existe um pedido de cancelamento em análise para este estágio.');
                    }
                }
            });

            $validator->validate();
        });

        static::deleting(function (): void {
            throw ValidationException::withMessages(['cancellation_request' => 'Pedidos de cancelamento não podem ser excluídos fisicamente.']);
        });
    }
}
