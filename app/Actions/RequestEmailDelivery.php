<?php

namespace App\Actions;

use App\Enums\EmailDeliveryAttemptStatus;
use App\Enums\EmailMessagePurpose;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\InternshipRequestStatus;
use App\Jobs\SendEmailDelivery;
use App\Models\Affiliation;
use App\Models\EmailDeliveryAttempt;
use App\Models\EmailMessage;
use App\Models\GeneratedDocument;
use App\Models\InternshipRequest;
use App\Models\User;
use App\Support\EmailDeliveryContext;
use App\Support\InternshipRequestReceipt;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

class RequestEmailDelivery
{
    /** @param array{name: string, email: string, role: string, affiliation: Affiliation|null} $recipient */
    public function documentSignature(GeneratedDocument $document, array $recipient, string $documentName, string $location, string $personalMessage, string $deliveryKey): EmailDeliveryAttempt
    {
        $this->validateKey($deliveryKey);
        Validator::make($recipient, ['email' => ['required', 'email'], 'name' => ['required', 'string'], 'role' => ['required', 'string']])->validate();
        abort_unless($document->status === GeneratedDocumentStatus::AwaitingSignature, 409);
        $internship = $document->internship;
        $internship->loadMissing('course.campus');
        $studentName = data_get($document->snapshot, 'values.DISCENTE_NOME') ?? data_get($internship->student_snapshot, 'name', 'Discente não informado');
        $subject = 'Documento disponível para assinatura — '.$studentName;
        $documentName = trim($documentName);
        Validator::make(['document_name' => $documentName], ['document_name' => ['required', 'string', 'max:255']])->validate();
        $internshipDetails = array_filter([
            'Discente' => $studentName,
            'Matrícula' => data_get($document->snapshot, 'values.DISCENTE_MATRICULA') ?? data_get($internship->student_snapshot, 'registration_number'),
            'Curso' => data_get($document->snapshot, 'values.CURSO_NOME') ?? $internship->course->name,
            'Campus' => $internship->course->campus->name,
            'Parte concedente' => data_get($document->snapshot, 'values.CONCEDENTE_NOME') ?? data_get($internship->granting_party_snapshot, 'name', 'Concedente não informada'),
            'Tipo de estágio' => data_get($document->snapshot, 'values.ESTAGIO_TIPO') ?? data_get($internship->internship_type_snapshot, 'name'),
            'Início previsto' => data_get($document->snapshot, 'values.ESTAGIO_DATA_INICIO') ?? formatShort($internship->planned_start_date),
            'Término previsto' => data_get($document->snapshot, 'values.ESTAGIO_DATA_TERMINO_PREVISTA') ?? formatShort($internship->projected_end_date),
        ], fn (mixed $value): bool => is_string($value) && trim($value) !== '');
        $protocolNumber = $document->protocol_number;
        $lines = ['Olá, '.$recipient['name'].'.', 'Como '.$recipient['role'].', solicitamos sua assinatura no documento '.$documentName.'.'];
        foreach ($internshipDetails as $label => $value) {
            $lines[] = $label.': '.$value;
        }
        if ($protocolNumber !== null) {
            $lines[] = 'Número de protocolo: '.$protocolNumber;
        }
        $lines[] = 'Local de disponibilização: '.$location;
        if ($personalMessage !== '') {
            $lines[] = 'Orientação do Setor de Estágio: '.$personalMessage;
        }
        $text = implode("\n\n", $lines);
        $data = [...$this->signatureData(), 'recipientName' => $recipient['name'], 'recipientRole' => $recipient['role'],
            'messageSubject' => $subject, 'documentName' => $documentName, 'internshipDetails' => $internshipDetails,
            'protocolNumber' => $protocolNumber, 'location' => $location, 'personalMessage' => $personalMessage,
            'dashboardUrl' => $recipient['affiliation'] === null ? null : route('dashboard')];
        $html = (string) app(Markdown::class)->render('emails.document-signature', $data);

        return DB::transaction(function () use ($recipient, $subject, $text, $html, $deliveryKey, $document): EmailDeliveryAttempt {
            $notification = $recipient['affiliation']?->notifications()->firstOrCreate(['id' => $deliveryKey], [
                'type' => self::class, 'data' => ['title' => $subject, 'message' => $text, 'route' => 'dashboard',
                    'internship_id' => $document->internship_id, 'generated_document_id' => $document->id],
            ]);
            $message = EmailMessage::query()->firstOrCreate(['idempotency_key' => $deliveryKey], [
                'purpose' => EmailMessagePurpose::DocumentSignature, 'notification_id' => $notification?->id,
                'subject' => $subject, 'content_text' => $text, 'content_html' => $html,
                'template_key' => 'document.signature-available', 'template_version' => '3',
            ]);
            if ($message->purpose !== EmailMessagePurpose::DocumentSignature || $message->notification_id !== $notification?->id
                || $message->subject !== $subject || $message->content_text !== $text || $message->content_html !== $html) {
                throw ValidationException::withMessages(['delivery_key' => 'Esta chave já pertence a outra mensagem.']);
            }

            return $this->reserve($deliveryKey, EmailMessagePurpose::DocumentSignature, $recipient['email'], $message,
                scopeContext: app(EmailDeliveryContext::class)->forInternship($document->internship));
        });
    }

    public function internshipRequestSubmitted(InternshipRequest $request, string $deliveryKey, bool $resubmission = false): EmailDeliveryAttempt
    {
        $this->validateKey($deliveryKey);
        $request->load('affiliation');
        $recipientEmail = $request->affiliation->email;
        Validator::make(['email' => $recipientEmail, 'status' => $request->status->value], [
            'email' => ['required', 'email'],
            'status' => ['required', Rule::in([InternshipRequestStatus::Submitted->value])],
        ])->validate();

        $subject = ($resubmission ? 'Reenvio' : 'Envio').' da solicitação de estágio nº '.$request->id;
        $sections = app(InternshipRequestReceipt::class)->sections($request);
        $url = route('internship-requests.show', $request);
        $contentHtml = (string) app(Markdown::class)->render('emails.internship-request-submitted', [
            'messageSubject' => $subject, 'sections' => $sections, 'requestUrl' => $url,
        ]);
        $lines = [$subject, 'Recebemos sua solicitação para análise. Estes são os dados registrados neste envio.'];
        foreach ($sections as $section) {
            $lines[] = $section['title'];
            foreach ($section['fields'] as $label => $value) {
                $lines[] = $label.': '.$value;
            }
        }
        $lines[] = 'Acompanhe a solicitação: '.$url;
        $contentText = implode("\n\n", $lines);

        return DB::transaction(function () use ($deliveryKey, $recipientEmail, $subject, $contentHtml, $contentText, $request): EmailDeliveryAttempt {
            $purpose = EmailMessagePurpose::InternshipRequestSubmitted;
            $message = EmailMessage::query()->firstOrCreate(['idempotency_key' => $deliveryKey], [
                'purpose' => $purpose, 'subject' => $subject, 'content_text' => $contentText, 'content_html' => $contentHtml,
                'template_key' => 'internship-request.submitted', 'template_version' => '1',
            ]);
            if ($message->purpose !== $purpose || $message->subject !== $subject || $message->content_text !== $contentText || $message->content_html !== $contentHtml) {
                throw ValidationException::withMessages(['delivery_key' => 'Esta chave já pertence a outra mensagem.']);
            }

            return $this->reserve($deliveryKey, $purpose, $recipientEmail, $message,
                scopeContext: app(EmailDeliveryContext::class)->forInternshipRequest($request));
        });
    }

    /** @param array{campus_ids: list<int>}|null $scopeContext */
    public function notification(DatabaseNotification $notification, string $subject, string $contentText, ?string $contentHtml, string $deliveryKey, ?array $scopeContext = null): EmailDeliveryAttempt
    {
        $this->validateKey($deliveryKey);
        $recipient = $notification->notifiable;

        if (! $recipient instanceof Affiliation && ! $recipient instanceof User) {
            throw ValidationException::withMessages(['notification' => 'A notificação deve pertencer a uma conta ou vínculo.']);
        }

        return DB::transaction(function () use ($notification, $recipient, $subject, $contentText, $contentHtml, $deliveryKey, $scopeContext): EmailDeliveryAttempt {
            $message = EmailMessage::query()->firstOrCreate(
                ['idempotency_key' => $deliveryKey],
                [
                    'notification_id' => $notification->id,
                    'purpose' => EmailMessagePurpose::Notification,
                    'subject' => $subject,
                    'content_text' => $contentText,
                    'content_html' => $contentHtml,
                ],
            );

            if ($message->notification_id !== $notification->id || $message->subject !== $subject ||
                $message->content_text !== $contentText || $message->content_html !== $contentHtml) {
                throw ValidationException::withMessages(['delivery_key' => 'Esta chave já pertence a outra mensagem.']);
            }

            return $this->reserve($deliveryKey, EmailMessagePurpose::Notification, $recipient->email, $message, scopeContext: $scopeContext);
        });
    }

    public function accountCreated(string $recipientEmail, string $deliveryKey, ?Affiliation $affiliation = null): EmailDeliveryAttempt
    {
        $this->validateKey($deliveryKey);
        $affiliation?->loadMissing(['campus', 'course']);
        $scopeContext = $affiliation === null ? null : app(EmailDeliveryContext::class)->forAffiliation($affiliation);
        $subject = 'Sua conta no Sistema de Gestão de Estágios foi criada';
        $data = [
            ...$this->signatureData(),
            'affiliationName' => $affiliation?->type->label() ?? 'Vínculo administrativo',
            'loginEmail' => $recipientEmail,
            'campusName' => $affiliation === null ? null : ($affiliation->campus_id === null ? 'Escopo global' : $affiliation->campus->name),
            'registrationNumber' => $affiliation?->registration_number,
            'courseName' => $affiliation?->course?->name,
            'affiliationEmail' => $affiliation?->email,
            'requestUrl' => route('password.request', ['email' => $recipientEmail]),
        ];
        $markdown = app(Markdown::class);
        $contentHtml = (string) $markdown->render('emails.invitation', $data);
        $contentText = (string) $markdown->renderText('emails.invitation', $data);

        return DB::transaction(function () use ($deliveryKey, $recipientEmail, $subject, $contentHtml, $contentText, $scopeContext): EmailDeliveryAttempt {
            $message = EmailMessage::query()->firstOrCreate(['idempotency_key' => $deliveryKey], [
                'purpose' => EmailMessagePurpose::AccountCreated,
                'subject' => $subject,
                'content_text' => $contentText,
                'content_html' => $contentHtml,
                'template_key' => 'administrative.account-created',
                'template_version' => '2',
            ]);

            if ($message->purpose !== EmailMessagePurpose::AccountCreated || $message->subject !== $subject ||
                $message->content_text !== $contentText || $message->content_html !== $contentHtml) {
                throw ValidationException::withMessages(['delivery_key' => 'Esta chave já pertence a outra mensagem.']);
            }

            return $this->reserve($deliveryKey, EmailMessagePurpose::AccountCreated, $recipientEmail, $message, scopeContext: $scopeContext);
        });
    }

    public function affiliationCreated(string $recipientEmail, Affiliation $affiliation, string $deliveryKey): EmailDeliveryAttempt
    {
        $this->validateKey($deliveryKey);
        $affiliation->loadMissing('campus');
        $scopeContext = app(EmailDeliveryContext::class)->forAffiliation($affiliation);

        $subject = 'Novo vínculo criado no Sistema de Gestão de Estágios';
        $data = [
            ...$this->signatureData(),
            'affiliationName' => $affiliation->type->label(),
            'campusName' => $affiliation->campus_id === null ? 'Escopo global' : $affiliation->campus->name,
            'registrationNumber' => $affiliation->registration_number,
            'loginUrl' => route('login'),
        ];
        $markdown = app(Markdown::class);
        $contentHtml = (string) $markdown->render('emails.affiliation-created', $data);
        $contentText = (string) $markdown->renderText('emails.affiliation-created', $data);

        return DB::transaction(function () use ($deliveryKey, $recipientEmail, $subject, $contentHtml, $contentText, $scopeContext): EmailDeliveryAttempt {
            $message = EmailMessage::query()->firstOrCreate(
                ['idempotency_key' => $deliveryKey],
                [
                    'purpose' => EmailMessagePurpose::NewAffiliation,
                    'subject' => $subject,
                    'content_text' => $contentText,
                    'content_html' => $contentHtml,
                    'template_key' => 'administrative.affiliation-created',
                    'template_version' => '1',
                ],
            );

            if ($message->purpose !== EmailMessagePurpose::NewAffiliation || $message->subject !== $subject ||
                $message->content_text !== $contentText || $message->content_html !== $contentHtml) {
                throw ValidationException::withMessages(['delivery_key' => 'Esta chave já pertence a outra mensagem.']);
            }

            return $this->reserve($deliveryKey, EmailMessagePurpose::NewAffiliation, $recipientEmail, $message, scopeContext: $scopeContext);
        });
    }

    public function accountEmailChanged(string $recipientEmail, string $previousEmail, string $newEmail, string $deliveryKey, ?User $account = null): EmailDeliveryAttempt
    {
        $this->validateKey($deliveryKey);

        Validator::make(compact('recipientEmail', 'previousEmail', 'newEmail'), [
            'recipientEmail' => ['required', 'email', Rule::in([$previousEmail, $newEmail])],
            'previousEmail' => ['required', 'email'],
            'newEmail' => ['required', 'email', 'different:previousEmail'],
        ])->validate();

        $scopeContext = $account === null ? null : app(EmailDeliveryContext::class)->forUser($account);
        $subject = 'E-mail da conta alterado';
        $requester = app(CauserResolver::class)->resolve();
        $isSelfChange = $account !== null && $requester instanceof Affiliation && $requester->user_id === $account->id;
        $data = [
            ...($isSelfChange ? ['requesterName' => null, 'requesterRole' => null] : $this->signatureData()),
            'isSelfChange' => $isSelfChange,
            'previousEmail' => $previousEmail,
            'newEmail' => $newEmail,
            'loginUrl' => route('login'),
        ];
        $markdown = app(Markdown::class);
        $contentHtml = (string) $markdown->render('emails.account-email-changed', $data);
        $contentText = (string) $markdown->renderText('emails.account-email-changed', $data);

        return DB::transaction(function () use ($deliveryKey, $recipientEmail, $subject, $contentHtml, $contentText, $scopeContext): EmailDeliveryAttempt {
            $message = EmailMessage::query()->firstOrCreate(
                ['idempotency_key' => $deliveryKey],
                [
                    'purpose' => EmailMessagePurpose::AccountEmailChanged,
                    'subject' => $subject,
                    'content_text' => $contentText,
                    'content_html' => $contentHtml,
                ],
            );

            if ($message->purpose !== EmailMessagePurpose::AccountEmailChanged || $message->subject !== $subject ||
                $message->content_text !== $contentText || $message->content_html !== $contentHtml) {
                throw ValidationException::withMessages(['delivery_key' => 'Esta chave já pertence a outra mensagem.']);
            }

            return $this->reserve($deliveryKey, EmailMessagePurpose::AccountEmailChanged, $recipientEmail, $message, scopeContext: $scopeContext);
        });
    }

    public function administrativeChange(string $recipientEmail, string $subject, string $body, string $deliveryKey, User|Affiliation|null $record = null): EmailDeliveryAttempt
    {
        $this->validateKey($deliveryKey);
        $markdown = app(Markdown::class);
        $context = app(EmailDeliveryContext::class);
        $scopeContext = match (true) {
            $record instanceof Affiliation => $context->forAffiliation($record),
            $record instanceof User => $context->forUser($record),
            default => null,
        };
        $data = [...$this->signatureData(), 'messageSubject' => $subject, 'body' => $body, 'dashboardUrl' => route('login')];
        $contentHtml = (string) $markdown->render('emails.notification', $data);
        $contentText = (string) $markdown->renderText('emails.notification', $data);

        return DB::transaction(function () use ($deliveryKey, $recipientEmail, $subject, $contentHtml, $contentText, $scopeContext): EmailDeliveryAttempt {
            $message = EmailMessage::query()->firstOrCreate(['idempotency_key' => $deliveryKey], [
                'purpose' => EmailMessagePurpose::AdministrativeChange,
                'subject' => $subject, 'content_text' => $contentText, 'content_html' => $contentHtml,
            ]);
            if ($message->purpose !== EmailMessagePurpose::AdministrativeChange || $message->subject !== $subject || $message->content_text !== $contentText || $message->content_html !== $contentHtml) {
                throw ValidationException::withMessages(['delivery_key' => 'Esta chave já pertence a outra mensagem.']);
            }

            return $this->reserve($deliveryKey, EmailMessagePurpose::AdministrativeChange, $recipientEmail, $message, scopeContext: $scopeContext);
        });
    }

    public function retry(EmailDeliveryAttempt $attempt): EmailDeliveryAttempt
    {
        return DB::transaction(function () use ($attempt): EmailDeliveryAttempt {
            $first = EmailDeliveryAttempt::query()->where('delivery_key', $attempt->delivery_key)
                ->orderBy('id')->lockForUpdate()->firstOrFail();
            $latest = EmailDeliveryAttempt::query()->where('delivery_key', $first->delivery_key)
                ->orderByDesc('attempt_number')->firstOrFail();

            if ($latest->status !== EmailDeliveryAttemptStatus::Failed) {
                return $latest;
            }

            if ($latest->attempt_number >= 3) {
                throw ValidationException::withMessages(['attempt_number' => 'O limite de tentativas foi atingido.']);
            }

            return $this->reserve($latest->delivery_key, $latest->purpose, $latest->recipient_email, $latest->emailMessage, $latest->attempt_number + 1, $latest->scope_context);
        });
    }

    /** @return array{requesterName: string|null, requesterRole: string|null} */
    private function signatureData(): array
    {
        $requester = app(CauserResolver::class)->resolve();

        if (! $requester instanceof Affiliation) {
            return ['requesterName' => null, 'requesterRole' => null];
        }

        $requester->loadMissing('user');

        return ['requesterName' => $requester->user->name, 'requesterRole' => $requester->type->label()];
    }

    /** @param array<string, mixed>|null $scopeContext */
    private function reserve(string $deliveryKey, EmailMessagePurpose $purpose, string $recipientEmail, ?EmailMessage $message = null, int $number = 1, ?array $scopeContext = null): EmailDeliveryAttempt
    {
        $attempt = EmailDeliveryAttempt::query()->firstOrCreate(
            ['delivery_key' => $deliveryKey, 'attempt_number' => $number],
            [
                'email_message_id' => $message?->id,
                'purpose' => $purpose,
                'recipient_email' => $recipientEmail,
                'status' => EmailDeliveryAttemptStatus::Queued,
                'queued_at' => now(),
                'scope_context' => $scopeContext,
            ],
        );

        if (! $attempt->wasRecentlyCreated && $attempt->scope_context === null
            && in_array($purpose, [EmailMessagePurpose::Notification, EmailMessagePurpose::DocumentSignature, EmailMessagePurpose::InternshipRequestSubmitted], true)) {
            $scopeContext = null;
        }

        if ($attempt->purpose !== $purpose || $attempt->recipient_email !== $recipientEmail ||
            $attempt->email_message_id !== $message?->id || $attempt->scope_context != $scopeContext) {
            throw ValidationException::withMessages(['delivery_key' => 'Esta chave já pertence a outro envio.']);
        }

        if ($attempt->wasRecentlyCreated) {
            SendEmailDelivery::dispatch($attempt->id)->afterCommit();
        }

        return $attempt;
    }

    private function validateKey(string $deliveryKey): void
    {
        Validator::make(['delivery_key' => $deliveryKey], ['delivery_key' => ['required', 'uuid']])->validate();
    }
}
