<?php

namespace App\Support;

use App\Models\Affiliation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;

final class BackupManager
{
    public const string RUNNING_CACHE_KEY = 'sge:backup:running';

    public const string LOG_NAME = 'backups';

    /**
     * @return non-empty-list<string>
     */
    public function disks(): array
    {
        $configured = config('backup.backup.destination.disks', ['backups']);
        if (! is_array($configured)) {
            return ['backups'];
        }

        $disks = array_values(array_filter(array_map(
            static fn (mixed $disk): string => is_string($disk) ? trim($disk) : '',
            $configured,
        ), static fn (string $disk): bool => $disk !== ''));

        return $disks === [] ? ['backups'] : $disks;
    }

    public function primaryDisk(): string
    {
        return $this->disks()[0];
    }

    public function backupName(): string
    {
        $name = config('backup.backup.name', 'sge');

        return is_string($name) && $name !== '' ? $name : 'sge';
    }

    public function isEncrypted(): bool
    {
        $password = config('backup.backup.password');
        $encryption = config('backup.backup.encryption', 'default');

        return is_string($password) && $password !== '' && $encryption !== 'none';
    }

    public function isRunning(): bool
    {
        return Cache::has(self::RUNNING_CACHE_KEY);
    }

    public function markRunning(string $mode): void
    {
        Cache::put(self::RUNNING_CACHE_KEY, [
            'mode' => $mode,
            'started_at' => now()->toIso8601String(),
        ], now()->addMinutes(20));
    }

    public function clearRunning(): void
    {
        Cache::forget(self::RUNNING_CACHE_KEY);
    }

    /**
     * @return array{
     *     disk: string,
     *     disks: non-empty-list<string>,
     *     reachable: bool,
     *     encrypted: bool,
     *     totalSizeBytes: int,
     *     backups: list<array{
     *         filename: string,
     *         path: string,
     *         date: CarbonInterface,
     *         sizeBytes: int,
     *     }>,
     * }
     */
    public function list(): array
    {
        $disk = $this->primaryDisk();
        $destination = BackupDestination::create($disk, $this->backupName());
        $reachable = $destination->isReachable();

        $items = [];
        $totalSizeBytes = 0;

        if ($reachable) {
            foreach ($destination->backups() as $backup) {
                if (! $backup->exists()) {
                    continue;
                }

                $sizeBytes = (int) $backup->sizeInBytes();
                $totalSizeBytes += $sizeBytes;

                $items[] = [
                    'filename' => basename($backup->path()),
                    'path' => $backup->path(),
                    'date' => $backup->date(),
                    'sizeBytes' => $sizeBytes,
                ];
            }
        }

        return [
            'disk' => $disk,
            'disks' => $this->disks(),
            'reachable' => $reachable,
            'encrypted' => $this->isEncrypted(),
            'totalSizeBytes' => $totalSizeBytes,
            'backups' => $items,
        ];
    }

    public function find(string $filename): ?Backup
    {
        if (basename($filename) !== $filename || preg_match('/^[A-Za-z0-9._-]+\.zip$/', $filename) !== 1) {
            return null;
        }

        $destination = BackupDestination::create($this->primaryDisk(), $this->backupName());
        if (! $destination->isReachable()) {
            return null;
        }

        foreach ($destination->backups() as $backup) {
            if ($backup->exists() && basename($backup->path()) === $filename) {
                return $backup;
            }
        }

        return null;
    }

    public function delete(string $filename, Affiliation $actor): bool
    {
        $list = $this->list();
        if (count($list['backups']) <= 1) {
            return false;
        }

        $backup = $this->find($filename);
        if ($backup === null) {
            return false;
        }

        $sizeBytes = (int) $backup->sizeInBytes();
        $backup->delete();

        $this->recordAudit($actor, 'backup_deleted', 'Backup removido manualmente pelo administrador.', [
            'filename' => $filename,
            'disk' => $this->primaryDisk(),
            'size_bytes' => $sizeBytes,
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function recordAudit(?Affiliation $actor, string $event, string $description, array $properties = []): void
    {
        $logger = activity(self::LOG_NAME)
            ->event($event)
            ->withProperties($properties);

        if ($actor !== null) {
            $logger->causedBy($actor);
        }

        $logger->log($description);
    }
}
