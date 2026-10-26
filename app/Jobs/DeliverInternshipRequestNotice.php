<?php

namespace App\Jobs;

use App\Actions\RequestEmailDelivery;
use App\Models\Affiliation;
use App\Models\EmailMessage;
use App\Models\InternshipRequest;
use App\Models\InternshipRequestCorrection;
use App\Support\EmailDeliveryContext;
use App\Support\EmailRecipientIdentity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeliverInternshipRequestNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    /** @param list<string> $affectedSections
     * @param  array<string, string>  $sectionMessages
     */
    public function __construct(public int $requestId, public string $noticeId, public string $statusLabel,
        public array $affectedSections = [], public array $sectionMessages = [], public ?string $legacyMessage = null,
        public ?string $generalMessage = null) {}

    public function handle(RequestEmailDelivery $delivery, EmailRecipientIdentity $identities): void
    {
        DB::transaction(function () use ($delivery, $identities): void {
            $request = InternshipRequest::query()->findOrFail($this->requestId);
            $owner = Affiliation::query()->lockForUpdate()->findOrFail($request->affiliation_id);
            if (EmailMessage::query()->where('idempotency_key', $this->noticeId)->exists()) {
                return;
            }
            $isPendingCorrection = $this->affectedSections !== [];
            $title = $isPendingCorrection ? 'Sua solicitação de estágio precisa de correções' : 'Sua solicitação de estágio foi atualizada';
            $text = $isPendingCorrection
                ? $this->correctionEmailText($request->id)
                : 'A solicitação nº '.$request->id.' está com a situação: '.$this->statusLabel.'. Acesse o SGE para consultar os próximos passos.';
            $notification = $owner->notifications()->firstOrCreate(['id' => $this->noticeId], [
                'type' => self::class,
                'data' => ['title' => $title, 'message' => $text, 'route' => 'internship-requests.show', 'internship_request_id' => $request->id],
            ]);
            $data = [
                'messageSubject' => $title,
                'body' => $isPendingCorrection ? $this->correctionEmailMarkdown($request->id) : $text,
                'dashboardUrl' => route('internship-requests.show', $request),
                'internshipDetails' => ['Solicitação' => 'nº '.$request->id,
                    'Tipo de estágio' => $request->internship_type_snapshot['name'] ?? 'Não informado', 'Situação' => $this->statusLabel],
                'recipientIdentity' => $identities->forAffiliation($owner), 'actionLabel' => 'Acompanhar solicitação',
            ];
            $contentHtml = (string) app(Markdown::class)->render('emails.notification', $data);
            $emailText = (string) app(Markdown::class)->renderText('emails.notification', $data);
            $delivery->notification($notification, $title, $emailText, $contentHtml, $this->noticeId, app(EmailDeliveryContext::class)->forInternshipRequest($request));
        });
    }

    private function correctionEmailText(int $requestId): string
    {
        $lines = ['A solicitação nº '.$requestId.' está com pendências e precisa de ajustes para voltar à análise.'];
        foreach ($this->correctionItems() as [$label, $message]) {
            $lines[] = $label.': '.$message;
        }
        if (filled($this->generalMessage)) {
            $lines[] = 'Orientação geral do Setor: '.$this->generalMessage;
        }
        $lines[] = 'Acesse a solicitação no SGE, revise cada etapa indicada e reenvie para análise.';

        return implode("\n\n", $lines);
    }

    private function correctionEmailMarkdown(int $requestId): string
    {
        $body = ['A solicitação nº '.$requestId.' está com pendências e precisa de ajustes para voltar à análise.'];
        foreach ($this->correctionItems() as [$label, $message]) {
            $body[] = '### '.$label."\n\n".$message;
        }
        if (filled($this->generalMessage)) {
            $body[] = "### Orientação geral do Setor\n\n".$this->generalMessage;
        }
        $body[] = 'Acesse a solicitação, revise cada etapa indicada e reenvie para análise.';

        return implode("\n\n", $body);
    }

    /** @return list<array{string, string}> */
    private function correctionItems(): array
    {
        $items = [];
        foreach ($this->affectedSections as $section) {
            $message = trim($this->sectionMessages[$section] ?? '');
            if ($message !== '') {
                $items[] = [InternshipRequestCorrection::sectionLabel($section), $message];
            }
        }

        if ($items === [] && filled($this->legacyMessage)) {
            $items[] = ['Orientação do Setor', trim($this->legacyMessage)];
        }

        return $items;
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Não foi possível preparar o aviso de decisão da solicitação.', [
            'internship_request_id' => $this->requestId, 'notice_id' => $this->noticeId,
        ]);
    }
}
