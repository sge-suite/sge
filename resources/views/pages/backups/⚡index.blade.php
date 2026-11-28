<?php

use App\Enums\AffiliationType;
use App\Jobs\RunSystemBackup;
use App\Support\ActiveAffiliationContext;
use App\Support\BackupManager;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Backups')] class extends Component
{
    public bool $showDeleteModal = false;

    public ?string $deletingFilename = null;

    public function boot(): void
    {
        Gate::authorize('manage-backups');
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function summary(): array
    {
        return app(BackupManager::class)->list();
    }

    #[Computed]
    public function isRunning(): bool
    {
        return app(BackupManager::class)->isRunning();
    }

    public function triggerBackup(BackupManager $manager, ActiveAffiliationContext $context): void
    {
        Gate::authorize('manage-backups');

        if ($manager->isRunning()) {
            Flux::toast('Já existe um backup em andamento. Aguarde a conclusão.', variant: 'warning');

            return;
        }

        $actor = $context->currentFor(auth()->user(), app('session.store'));
        abort_unless($actor?->type === AffiliationType::SystemAdministrator, 403);

        $manager->markRunning('full');
        RunSystemBackup::dispatch($actor->id);

        unset($this->isRunning, $this->summary);

        Flux::toast('Backup enfileirado. A lista será atualizada automaticamente ao concluir.', variant: 'success');
    }

    public function confirmDelete(string $filename): void
    {
        Gate::authorize('manage-backups');

        $this->deletingFilename = $filename;
        $this->showDeleteModal = true;
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingFilename = null;
    }

    public function deleteBackup(BackupManager $manager, ActiveAffiliationContext $context): void
    {
        Gate::authorize('manage-backups');

        $actor = $context->currentFor(auth()->user(), app('session.store'));
        abort_unless($actor?->type === AffiliationType::SystemAdministrator, 403);

        if ($this->deletingFilename === null || ! $manager->delete($this->deletingFilename, $actor)) {
            $this->closeDeleteModal();
            Flux::toast('Não foi possível excluir este backup. O último backup disponível é protegido.', variant: 'warning');

            return;
        }

        $this->closeDeleteModal();
        unset($this->summary);

        Flux::toast('Backup excluído com sucesso.', variant: 'success');
    }
}; ?>

@php
    $summary = $this->summary;
    $backups = $summary['backups'];
    $latest = $backups[0] ?? null;
    $backupCount = count($backups);
@endphp

<div class="space-y-6" @if ($this->isRunning) wire:poll.5s @endif>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Backups do sistema</flux:heading>
            <flux:text class="mt-1">
                Cópias de segurança completas (banco de dados PostgreSQL e arquivos privados) armazenadas no disco dedicado <code class="font-mono text-xs">{{ $summary['disk'] }}</code>.
            </flux:text>
        </div>

        <flux:button
            type="button"
            variant="primary"
            icon="archive-box-arrow-down"
            wire:click="triggerBackup"
            wire:loading.attr="disabled"
            :disabled="$this->isRunning"
        >
            Gerar backup
        </flux:button>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <flux:card class="p-4">
            <flux:text size="sm">Último backup</flux:text>
            <flux:heading size="lg" class="mt-1">
                {{ $latest !== null ? formatDateTime($latest['date']) : 'Nenhum' }}
            </flux:heading>
            @if ($latest !== null)
                <flux:text size="xs" class="mt-0.5 text-zinc-500">{{ formatRelative($latest['date']) }}</flux:text>
            @endif
        </flux:card>

        <flux:card class="p-4">
            <flux:text size="sm">Espaço utilizado</flux:text>
            <flux:heading size="lg" class="mt-1 tabular-nums">
                {{ formatFileSize($summary['totalSizeBytes']) }}
            </flux:heading>
            <flux:text size="xs" class="mt-0.5 text-zinc-500">
                {{ $backupCount }} {{ $backupCount === 1 ? 'arquivo armazenado' : 'arquivos armazenados' }}
            </flux:text>
        </flux:card>

        <flux:card class="p-4">
            <flux:text size="sm">Criptografia AES-256</flux:text>
            <div class="mt-2">
                <flux:badge size="sm" :color="$summary['encrypted'] ? 'green' : 'amber'">
                    {{ $summary['encrypted'] ? 'Ativa' : 'Não configurada' }}
                </flux:badge>
            </div>
            <flux:text size="xs" class="mt-1 text-zinc-500">
                {{ $summary['encrypted'] ? 'Arquivos ZIP protegidos por senha.' : 'Defina BACKUP_ARCHIVE_PASSWORD no .env.' }}
            </flux:text>
        </flux:card>

        <flux:card class="p-4">
            <flux:text size="sm">Rotina automática</flux:text>
            <flux:heading size="lg" class="mt-1 tabular-nums">01:30</flux:heading>
            <flux:text size="xs" class="mt-0.5 text-zinc-500">
                Limpeza 01:00 · Monitoramento 08:10
            </flux:text>
        </flux:card>
    </div>

    @if (! $summary['encrypted'])
        <flux:callout variant="warning" icon="shield-exclamation" heading="Criptografia de backup não configurada">
            Os backups contêm dados pessoais e documentos privados (LGPD). Configure <code class="font-mono text-xs">BACKUP_ARCHIVE_PASSWORD</code> no <code class="font-mono text-xs">.env</code> para proteger os arquivos <code class="font-mono text-xs">.zip</code> com AES-256.
        </flux:callout>
    @endif

    @if ($this->isRunning)
        <flux:callout variant="secondary" icon="arrow-path" heading="Backup em andamento">
            A geração do backup está sendo processada em segundo plano. Esta página será atualizada automaticamente ao concluir.
        </flux:callout>
    @endif

    @if (! $summary['reachable'])
        <flux:callout variant="danger" icon="exclamation-triangle" heading="Disco de backup indisponível">
            Não foi possível acessar o disco configurado (<code class="font-mono text-xs">{{ $summary['disk'] }}</code>). Verifique as permissões e a configuração de armazenamento.
        </flux:callout>
    @elseif ($backupCount === 0)
        <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700">
            <flux:icon.archive-box class="mx-auto mb-4 size-8 text-zinc-400" />
            <flux:heading size="lg">Nenhum backup encontrado</flux:heading>
            <flux:text class="mt-2">Gere um backup manual acima ou aguarde a execução automática agendada para as 01:30.</flux:text>
        </div>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Arquivo</flux:table.column>
                <flux:table.column>Criado em</flux:table.column>
                <flux:table.column>Tamanho</flux:table.column>
                <flux:table.column class="text-end">Ações</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($backups as $item)
                    <flux:table.row :key="$item['filename']">
                        <flux:table.cell class="font-mono text-xs text-zinc-900 dark:text-zinc-100">
                            {{ $item['filename'] }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <div>{{ formatDateTime($item['date']) }}</div>
                            <flux:text size="xs" class="text-zinc-500">{{ formatRelative($item['date']) }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell class="tabular-nums">
                            {{ formatFileSize($item['sizeBytes']) }}
                        </flux:table.cell>
                        <flux:table.cell class="text-end">
                            <div class="inline-flex items-center justify-end gap-2">
                                <flux:button
                                    size="sm"
                                    variant="outline"
                                    icon="arrow-down-tray"
                                    :href="route('backups.download', ['filename' => $item['filename']])"
                                >
                                    Baixar
                                </flux:button>
                                <flux:button
                                    type="button"
                                    size="sm"
                                    variant="danger"
                                    icon="trash"
                                    :disabled="$backupCount <= 1"
                                    wire:click="confirmDelete(@js($item['filename']))"
                                >
                                    Excluir
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <x-accordion title="Como restaurar um backup">
        <div class="space-y-3 text-sm text-zinc-600 dark:text-zinc-300">
            <p>
                Por segurança, a restauração substitui o banco de dados e os arquivos privados (<code class="font-mono text-xs">storage/app/private</code>) e deve ser executada pelo terminal do servidor (no ambiente atual ou em uma nova instalação).
            </p>
            <ol class="list-decimal space-y-2 ps-5">
                <li>
                    Guarde uma cópia segura do arquivo <code class="font-mono text-xs">.env</code> fora do servidor — em especial <code class="font-mono text-xs">APP_KEY</code> e <code class="font-mono text-xs">BACKUP_ARCHIVE_PASSWORD</code>.
                </li>
                <li>
                    Em uma nova instalação, configure o <code class="font-mono text-xs">.env</code>, suba o banco PostgreSQL e o Meilisearch, e copie o arquivo <code class="font-mono text-xs">.zip</code> baixado para o servidor.
                </li>
                <li>
                    Execute o comando de restauração (ou com <code class="font-mono text-xs">./vendor/bin/sail artisan ...</code> em Docker):
                    <pre class="mt-1.5 overflow-x-auto rounded-lg bg-zinc-900 px-3 py-2 font-mono text-xs text-zinc-100">php artisan sge:restore-backup /caminho/para/backup.zip</pre>
                    <span class="mt-1 block text-xs text-zinc-500 dark:text-zinc-400">
                        Se omitir o caminho do arquivo, o comando lista os backups disponíveis no disco <code class="font-mono text-xs">{{ $summary['disk'] }}</code> para seleção interativa.
                    </span>
                </li>
                <li>
                    Após restaurar em uma instalação nova do Meilisearch, reindexe a busca:
                    <pre class="mt-1.5 overflow-x-auto rounded-lg bg-zinc-900 px-3 py-2 font-mono text-xs text-zinc-100">php artisan scout:sync-index-settings</pre>
                </li>
            </ol>
        </div>
    </x-accordion>

    <flux:modal name="delete-backup" wire:model.self="showDeleteModal" class="md:w-md">
        <form wire:submit="deleteBackup" class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg" level="2">Excluir backup</flux:heading>
                <flux:text class="mt-2">
                    Tem certeza que deseja excluir permanentemente o arquivo <strong class="font-mono text-xs">{{ $deletingFilename }}</strong>?
                </flux:text>
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button type="button" variant="outline" wire:click="closeDeleteModal">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="danger">Excluir backup</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
