<?php

namespace App\Concerns;

use Closure;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** @phpstan-require-extends Component */
trait InteractsWithCsvImport
{
    use WithFileUploads;
    use WithPagination;

    public ?TemporaryUploadedFile $csv = null;

    public string $separator = ';';

    public string $filter = 'all';

    /** @var array<string, mixed> */
    #[Locked]
    public array $import = [];

    /** @param Closure(string, string): array<string, mixed> $prepare */
    protected function analyzeCsv(Closure $prepare): void
    {
        abort_if(($this->import['status'] ?? null) === 'running', 409);
        $file = $this->csv;
        try {
            $this->validate([
                'csv' => ['required', 'file', 'extensions:csv', 'max:8192', 'mimetypes:text/plain,text/csv,text/x-csv,application/csv,application/vnd.ms-excel,application/octet-stream'],
                'separator' => ['required', Rule::in([';', ','])],
            ], attributes: ['csv' => 'arquivo CSV', 'separator' => 'separador']);
            $contents = $file?->get();
            abort_unless(is_string($contents), 422);
            $this->import = $prepare($contents, $this->separator);
            $this->filter = 'all';
            $this->resetPage();
        } finally {
            $file?->delete();
            $this->csv = null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    protected function paginateImportRows(array $rows, string $path): LengthAwarePaginator
    {
        $page = max(1, (int) $this->getPage());

        return new LengthAwarePaginator(array_slice($rows, ($page - 1) * 100, 100), count($rows), 100, $page, ['path' => $path]);
    }

    protected function downloadCsv(string $contents, string $filename): StreamedResponse
    {
        return response()->streamDownload(fn () => print ($contents), $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
