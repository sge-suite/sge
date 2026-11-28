<?php

namespace App\Console\Commands;

use App\Support\BackupManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class RestoreBackup extends Command
{
    protected $signature = 'sge:restore-backup
        {file? : Caminho do arquivo .zip de backup}
        {--password= : Senha do arquivo .zip criptografado}
        {--force : Executa a restauração sem pedir confirmação}';

    protected $description = 'Restaura um backup (.zip) do banco de dados PostgreSQL e dos arquivos privados';

    public function handle(BackupManager $manager): int
    {
        $connection = (string) config('database.default', 'pgsql');
        if (config("database.connections.{$connection}.driver") !== 'pgsql') {
            $this->error('A restauração automática suporta apenas conexões PostgreSQL (pgsql).');

            return self::FAILURE;
        }

        $filePath = $this->resolveBackupFile($manager);
        if ($filePath === null) {
            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            'Isso substituirá o banco de dados atual e os arquivos em storage/app/private. Deseja continuar?',
            false,
        )) {
            $this->warn('Restauração cancelada.');

            return self::FAILURE;
        }

        $tempDir = storage_path('app/backup-temp/restore-'.uniqid('', true));
        File::ensureDirectoryExists($tempDir);

        $maintenanceEnabled = false;

        try {
            if (! $this->extractArchive($filePath, $tempDir)) {
                return self::FAILURE;
            }

            $dumpPath = $this->findDatabaseDump($tempDir);
            if ($dumpPath === null) {
                $this->error('Nenhum dump de banco de dados (.sql ou .sql.gz) foi encontrado em db-dumps/ dentro do backup.');

                return self::FAILURE;
            }

            $sourcePrivate = $this->findPrivateStorageDirectory($tempDir);
            if ($sourcePrivate === null) {
                $this->error('O diretório storage/app/private não foi encontrado dentro do backup.');

                return self::FAILURE;
            }

            $this->info('Ativando modo de manutenção...');
            Artisan::call('down');
            $maintenanceEnabled = true;

            $this->info('Restaurando banco de dados PostgreSQL...');
            if (! $this->restorePostgres($dumpPath, $connection, $tempDir)) {
                return self::FAILURE;
            }

            $this->info('Restaurando arquivos privados...');
            $this->restorePrivateFiles($sourcePrivate, $manager->backupName());

            $this->info('Limpando caches da aplicação...');
            Artisan::call('optimize:clear');

            $this->newLine();
            $this->info('Backup restaurado com sucesso.');
            $this->line('Se estiver em um ambiente novo do Meilisearch, execute em seguida:');
            $this->line('  php artisan scout:sync-index-settings');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Falha durante a restauração: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($maintenanceEnabled) {
                Artisan::call('up');
                $this->info('Modo de manutenção desativado.');
            }

            File::deleteDirectory($tempDir);
        }
    }

    private function resolveBackupFile(BackupManager $manager): ?string
    {
        $argument = $this->argument('file');
        if (is_string($argument) && $argument !== '') {
            if (! is_file($argument) || strtolower(pathinfo($argument, PATHINFO_EXTENSION)) !== 'zip') {
                $this->error("Arquivo inválido ou não encontrado: {$argument}");

                return null;
            }

            return $argument;
        }

        $listing = $manager->list();
        if (! $listing['reachable'] || $listing['backups'] === []) {
            $this->error('Nenhum backup disponível encontrado no disco configurado.');

            return null;
        }

        $choices = [];
        foreach ($listing['backups'] as $backup) {
            $label = sprintf(
                '%s (%s · %s)',
                $backup['filename'],
                formatDateTime($backup['date']),
                formatFileSize($backup['sizeBytes']),
            );
            $choices[$label] = $backup['path'];
        }

        $selectedLabel = $this->choice('Selecione o backup que deseja restaurar', array_keys($choices), 0);
        if (! is_string($selectedLabel) || ! isset($choices[$selectedLabel])) {
            return null;
        }

        $resolved = Storage::disk($listing['disk'])->path($choices[$selectedLabel]);
        if (! is_file($resolved)) {
            $this->error("Não foi possível localizar o arquivo no disco local: {$resolved}");

            return null;
        }

        return $resolved;
    }

    private function extractArchive(string $filePath, string $destination): bool
    {
        $zip = new ZipArchive;
        $opened = $zip->open($filePath);
        if ($opened !== true) {
            $this->error('Não foi possível abrir o arquivo .zip do backup.');

            return false;
        }

        try {
            $password = $this->option('password');
            if (! is_string($password) || $password === '') {
                $configured = config('backup.backup.password');
                $password = is_string($configured) && $configured !== '' ? $configured : null;
            }

            if ($password !== null) {
                $zip->setPassword($password);
            }

            if ($zip->extractTo($destination)) {
                return true;
            }

            if ($password !== null && $zip->status !== ZipArchive::ER_NOPASSWD) {
                $this->error('Senha incorreta ou arquivo .zip corrompido.');

                return false;
            }

            $prompted = $this->secret('Este backup está criptografado. Informe a senha (.zip)');
            if (! is_string($prompted) || $prompted === '') {
                $this->error('Senha não informada.');

                return false;
            }

            return $this->extractWithPromptedPassword($zip, $destination, $prompted);
        } finally {
            $zip->close();
        }
    }

    private function extractWithPromptedPassword(ZipArchive $zip, string $destination, string $password): bool
    {
        $zip->setPassword($password);
        if (! $zip->extractTo($destination)) {
            $this->error('Senha incorreta ou arquivo .zip corrompido.');

            return false;
        }

        return true;
    }

    private function findDatabaseDump(string $extractedRoot): ?string
    {
        $dumpsDir = $extractedRoot.'/db-dumps';
        if (! is_dir($dumpsDir)) {
            return null;
        }

        foreach (File::files($dumpsDir) as $file) {
            $name = $file->getFilename();
            if (str_ends_with($name, '.sql') || str_ends_with($name, '.sql.gz')) {
                return $file->getPathname();
            }
        }

        return null;
    }

    private function findPrivateStorageDirectory(string $extractedRoot): ?string
    {
        $relative = $extractedRoot.'/storage/app/private';
        if (is_dir($relative)) {
            return $relative;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extractedRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo || ! $item->isDir()) {
                continue;
            }

            $normalized = str_replace('\\', '/', $item->getPathname());
            if (str_ends_with($normalized, '/storage/app/private')) {
                return $item->getPathname();
            }
        }

        return null;
    }

    private function restorePostgres(string $dumpPath, string $connection, string $tempDir): bool
    {
        $sqlPath = $dumpPath;
        if (str_ends_with($dumpPath, '.gz')) {
            $sqlPath = $tempDir.'/restored-dump.sql';
            if (! $this->decompressGzip($dumpPath, $sqlPath)) {
                $this->error('Falha ao descompactar o dump .sql.gz.');

                return false;
            }
        }

        $host = (string) config("database.connections.{$connection}.host", '127.0.0.1');
        $port = (string) config("database.connections.{$connection}.port", '5432');
        $database = (string) config("database.connections.{$connection}.database");
        $username = (string) config("database.connections.{$connection}.username");
        $password = (string) config("database.connections.{$connection}.password", '');

        $env = ['PGPASSWORD' => $password];
        $baseArgs = ['psql', '-h', $host, '-p', $port, '-U', $username, '-d', $database];

        $reset = new Process([...$baseArgs, '-v', 'ON_ERROR_STOP=1', '-c', 'DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;'], null, $env, null, 300);
        $reset->run();
        if (! $reset->isSuccessful()) {
            $this->error('Erro ao limpar o schema public do PostgreSQL: '.trim($reset->getErrorOutput()));

            return false;
        }

        $restore = new Process([...$baseArgs, '-v', 'ON_ERROR_STOP=1', '-f', $sqlPath], null, $env, null, 900);
        $restore->run();
        if (! $restore->isSuccessful()) {
            $this->error('Erro ao restaurar o dump SQL no PostgreSQL: '.trim($restore->getErrorOutput()));

            return false;
        }

        return true;
    }

    private function decompressGzip(string $sourcePath, string $targetPath): bool
    {
        $in = gzopen($sourcePath, 'rb');
        $out = fopen($targetPath, 'wb');
        if ($in === false || $out === false) {
            if (is_resource($in)) {
                gzclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }

            return false;
        }

        try {
            while (! gzeof($in)) {
                $chunk = gzread($in, 65536);
                if ($chunk === false) {
                    return false;
                }
                if ($chunk !== '' && fwrite($out, $chunk) === false) {
                    return false;
                }
            }

            return true;
        } finally {
            gzclose($in);
            fclose($out);
        }
    }

    private function restorePrivateFiles(string $sourcePrivate, string $backupName): void
    {
        $targetPrivate = storage_path('app/private');
        File::ensureDirectoryExists($targetPrivate);

        $excluded = ['.gitignore', 'generated-documents-tmp', 'livewire-tmp', $backupName];

        foreach (File::directories($targetPrivate) as $dir) {
            if (! in_array(basename($dir), $excluded, true)) {
                File::deleteDirectory($dir);
            }
        }

        foreach (File::files($targetPrivate) as $file) {
            if (! in_array($file->getFilename(), $excluded, true)) {
                File::delete($file->getPathname());
            }
        }

        foreach (File::directories($sourcePrivate) as $dir) {
            $name = basename($dir);
            if (! in_array($name, $excluded, true)) {
                File::copyDirectory($dir, $targetPrivate.'/'.$name);
            }
        }

        foreach (File::files($sourcePrivate) as $file) {
            $name = $file->getFilename();
            if (! in_array($name, $excluded, true)) {
                File::copy($file->getPathname(), $targetPrivate.'/'.$name);
            }
        }
    }
}
