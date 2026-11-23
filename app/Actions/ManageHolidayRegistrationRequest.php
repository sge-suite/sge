<?php

namespace App\Actions;

use App\Enums\AffiliationType;
use App\Enums\HolidayScope;
use App\Enums\RegistrationRequestStatus;
use App\Models\Affiliation;
use App\Models\City;
use App\Models\Holiday;
use App\Models\HolidayRecurrence;
use App\Models\HolidayRegistrationRequest;
use App\Models\User;
use App\Support\ActiveAffiliationContext;
use App\Support\HolidayAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

class ManageHolidayRegistrationRequest
{
    public function __construct(
        private ActiveAffiliationContext $context,
        private HolidayAccess $access,
        private ManageHoliday $manageHoliday,
        private ManageHolidayRecurrence $manageRecurrence,
        private RequestEmailDelivery $emails,
        private CauserResolver $causers,
    ) {}

    /** @param array<string, mixed> $data */
    public function submit(User $actor, int $expectedAffiliationId, array $data): HolidayRegistrationRequest
    {
        Gate::forUser($actor)->authorize('requestRegistration', Holiday::class);

        $kind = (string) ($data['kind'] ?? 'holiday');
        $validated = Validator::make($data, [
            'kind' => ['required', Rule::in(['holiday', 'recurrence'])],
            'name' => ['required', 'string', 'max:255'],
            'city_id' => ['required', 'integer', Rule::exists(City::class, 'id')],
            'date' => [Rule::requiredIf($kind === 'holiday'), 'nullable', 'date_format:Y-m-d', 'date', 'after_or_equal:1900-01-01', 'before_or_equal:2199-12-31'],
            'month' => [Rule::requiredIf($kind === 'recurrence'), 'nullable', 'integer', 'between:1,12'],
            'day' => [Rule::requiredIf($kind === 'recurrence'), 'nullable', 'integer', 'between:1,31'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'name.required' => 'Informe o nome do feriado municipal.',
            'city_id.required' => 'Selecione a cidade do feriado municipal.',
            'city_id.exists' => 'Selecione uma cidade válida.',
            'date.required' => 'Informe a data do feriado.',
            'date.date_format' => 'Informe uma data válida.',
            'date.date' => 'Informe uma data válida.',
            'month.required' => 'Informe o mês da recorrência.',
            'day.required' => 'Informe o dia da recorrência.',
        ])->after(function ($validator) use ($kind, $data): void {
            if ($kind === 'recurrence' && ! $validator->errors()->hasAny(['month', 'day'])) {
                if (! checkdate((int) $data['month'], (int) $data['day'], 2024)) {
                    $validator->errors()->add('day', 'A combinação de dia e mês informada não existe no calendário.');
                }
            }
        })->validate();

        return DB::transaction(function () use ($actor, $expectedAffiliationId, $validated): HolidayRegistrationRequest {
            $selected = $this->context->currentFor($actor, app('session.store'));
            abort_unless($selected !== null && $selected->id === $expectedAffiliationId, 403);
            $office = Affiliation::query()->active()->with('campus')->lockForUpdate()->find($selected->id);
            abort_unless(
                $office?->type === AffiliationType::InternshipOffice
                && $office->campus !== null
                && $office->campus->deactivated_at === null,
                403,
            );

            $name = trim((string) $validated['name']);
            $cityId = (int) $validated['city_id'];
            $kind = (string) $validated['kind'];

            if ($kind === 'holiday') {
                $alreadyExists = Holiday::query()
                    ->where('scope', HolidayScope::Municipal)
                    ->where('city_id', $cityId)
                    ->whereDate('date', (string) $validated['date'])
                    ->exists();
                if ($alreadyExists) {
                    throw ValidationException::withMessages(['date' => 'Já existe um feriado municipal cadastrado para esta cidade nesta data.']);
                }

                $pendingExists = HolidayRegistrationRequest::query()
                    ->where('status', RegistrationRequestStatus::Submitted)
                    ->where('kind', 'holiday')
                    ->where('city_id', $cityId)
                    ->whereDate('date', (string) $validated['date'])
                    ->exists();
                if ($pendingExists) {
                    throw ValidationException::withMessages(['date' => 'Já existe uma solicitação pendente para esta cidade nesta data.']);
                }
            } else {
                $alreadyExists = HolidayRecurrence::query()
                    ->where('city_id', $cityId)
                    ->where('month', (int) $validated['month'])
                    ->where('day', (int) $validated['day'])
                    ->exists();
                if ($alreadyExists) {
                    throw ValidationException::withMessages(['day' => 'Já existe uma recorrência municipal cadastrada para esta cidade neste dia e mês.']);
                }

                $pendingExists = HolidayRegistrationRequest::query()
                    ->where('status', RegistrationRequestStatus::Submitted)
                    ->where('kind', 'recurrence')
                    ->where('city_id', $cityId)
                    ->where('month', (int) $validated['month'])
                    ->where('day', (int) $validated['day'])
                    ->exists();
                if ($pendingExists) {
                    throw ValidationException::withMessages(['day' => 'Já existe uma solicitação de recorrência pendente para esta cidade neste dia e mês.']);
                }
            }

            $created = $this->causers->withCauser($office, function () use ($office, $validated, $name, $cityId, $kind): HolidayRegistrationRequest {
                return HolidayRegistrationRequest::create([
                    'campus_id' => $office->campus_id,
                    'requested_by_affiliation_id' => $office->id,
                    'kind' => $kind,
                    'name' => $name,
                    'city_id' => $cityId,
                    'date' => $kind === 'holiday' ? $validated['date'] : null,
                    'month' => $kind === 'recurrence' ? (int) $validated['month'] : null,
                    'day' => $kind === 'recurrence' ? (int) $validated['day'] : null,
                    'notes' => isset($validated['notes']) && trim((string) $validated['notes']) !== '' ? trim((string) $validated['notes']) : null,
                    'status' => RegistrationRequestStatus::Submitted,
                ]);
            });

            $created->loadMissing(['city', 'campus', 'requestedByAffiliation.user']);
            $this->emailSystemAdministrators($created);

            return $created;
        });
    }

    public function approve(User $actor, HolidayRegistrationRequest $target): HolidayRegistrationRequest
    {
        Gate::forUser($actor)->authorize('create', Holiday::class);

        $request = HolidayRegistrationRequest::query()->with(['city', 'requestedByAffiliation'])->findOrFail($target->id);
        if ($request->status !== RegistrationRequestStatus::Submitted) {
            throw ValidationException::withMessages(['holiday' => 'Esta solicitação já foi analisada.']);
        }

        if ($request->kind === 'holiday') {
            $holiday = $this->manageHoliday->handle($actor, null, 'create', [
                'name' => $request->name,
                'date' => $request->date?->toDateString(),
                'scope' => HolidayScope::Municipal->value,
                'state_code' => $request->city->state->value,
                'city_id' => $request->city_id,
            ]);
            $recurrence = null;
        } else {
            $recurrence = $this->manageRecurrence->handle($actor, null, [
                'name' => $request->name,
                'city_id' => $request->city_id,
                'month' => $request->month,
                'day' => $request->day,
                'starts_year' => null,
                'ends_year' => null,
                'notes' => $request->notes,
            ], 'save');
            $holiday = null;
        }

        return $this->access->asAdministrator($actor, function () use ($actor, $request, $holiday, $recurrence): HolidayRegistrationRequest {
            $admin = $this->context->currentFor($actor, app('session.store'));
            $locked = HolidayRegistrationRequest::query()->with(['city', 'requestedByAffiliation'])->lockForUpdate()->findOrFail($request->id);
            $locked->update([
                'status' => RegistrationRequestStatus::Approved,
                'reviewed_by_affiliation_id' => $admin?->id,
                'reviewed_at' => now(),
                'holiday_id' => $holiday?->id,
                'holiday_recurrence_id' => $recurrence?->id,
            ]);

            $this->notifyRequester(
                $locked,
                'Solicitação de feriado aprovada',
                sprintf(
                    'O feriado municipal "%s" (%s · %s/%s) solicitado pelo campus foi aprovado e cadastrado no calendário.',
                    $locked->name,
                    $locked->periodSummary(),
                    $locked->city->name,
                    $locked->city->state->value,
                ),
            );

            return $locked;
        });
    }

    public function reject(User $actor, HolidayRegistrationRequest $target, string $decisionNotes): HolidayRegistrationRequest
    {
        Gate::forUser($actor)->authorize('create', Holiday::class);
        $validated = Validator::make(['decision_notes' => trim($decisionNotes)], [
            'decision_notes' => ['required', 'string', 'max:2000'],
        ], [
            'decision_notes.required' => 'Informe o motivo da recusa.',
        ])->validate();

        return $this->access->asAdministrator($actor, function () use ($actor, $target, $validated): HolidayRegistrationRequest {
            $admin = $this->context->currentFor($actor, app('session.store'));
            $locked = HolidayRegistrationRequest::query()->with(['city', 'requestedByAffiliation'])->lockForUpdate()->findOrFail($target->id);
            if ($locked->status !== RegistrationRequestStatus::Submitted) {
                throw ValidationException::withMessages(['holiday' => 'Esta solicitação já foi analisada.']);
            }

            $locked->update([
                'status' => RegistrationRequestStatus::Rejected,
                'reviewed_by_affiliation_id' => $admin?->id,
                'reviewed_at' => now(),
                'decision_notes' => $validated['decision_notes'],
            ]);

            $this->notifyRequester(
                $locked,
                'Solicitação de feriado recusada',
                sprintf(
                    "A solicitação do feriado municipal \"%s\" (%s · %s/%s) foi recusada.\nMotivo: %s",
                    $locked->name,
                    $locked->periodSummary(),
                    $locked->city->name,
                    $locked->city->state->value,
                    $locked->decision_notes,
                ),
            );

            return $locked;
        });
    }

    private function emailSystemAdministrators(HolidayRegistrationRequest $request): void
    {
        $admins = Affiliation::query()
            ->active()
            ->where('type', AffiliationType::SystemAdministrator)
            ->with('user')
            ->get();

        $subject = sprintf('Solicitação de feriado municipal: %s (%s/%s)', $request->name, $request->city->name, $request->city->state->value);
        $body = sprintf(
            "O Setor de Estágio do campus %s (%s) enviou uma solicitação de cadastro de feriado municipal:\n\n- Nome: %s\n- Tipo: %s\n- Data/período: %s\n- Cidade/UF: %s/%s%s\n\nAcesse Solicitações de feriado no menu Configurações do SGE para aprovar ou recusar a solicitação.",
            $request->campus->name,
            $request->requestedByAffiliation?->user->name ?? 'Setor de Estágio',
            $request->name,
            $request->kindLabel(),
            $request->periodSummary(),
            $request->city->name,
            $request->city->state->value,
            $request->notes !== null ? "\n- Observações: ".$request->notes : '',
        );

        $sentEmails = [];
        foreach ($admins as $admin) {
            $email = trim((string) ($admin->email ?: $admin->user->email));
            if ($email === '' || isset($sentEmails[mb_strtolower($email)])) {
                continue;
            }
            $sentEmails[mb_strtolower($email)] = true;
            $this->emails->administrativeChange(
                $email,
                $subject,
                $body,
                (string) Str::uuid(),
                $admin,
            );
        }
    }

    private function notifyRequester(HolidayRegistrationRequest $request, string $title, string $message): void
    {
        $recipient = $request->requestedByAffiliation;
        if ($recipient === null || $recipient->deactivated_at !== null) {
            return;
        }

        $recipient->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => self::class,
            'data' => [
                'title' => $title,
                'message' => $message,
                'route' => 'holiday-requests.index',
            ],
        ]);

        $email = trim((string) ($recipient->email ?: $recipient->user->email));
        if ($email !== '') {
            $this->emails->administrativeChange(
                $email,
                $title.': '.$request->name,
                $message,
                (string) Str::uuid(),
                $recipient,
            );
        }
    }
}
