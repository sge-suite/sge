<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;

class AuditInfrastructureModel
{
    public function created(Model $model): void
    {
        $this->record($model, 'created', $this->values($model), []);
    }

    public function updated(Model $model): void
    {
        $changed = array_keys($model->getChanges());
        $this->record($model, 'updated', $this->values($model, $changed), $this->oldValues($model, $changed));
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted', [], $this->values($model));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $old
     */
    private function record(Model $model, string $event, array $attributes, array $old): void
    {
        $logger = activity()
            ->event($event)
            ->withChanges(['attributes' => $attributes, 'old' => $old]);

        if ($model instanceof DatabaseNotification) {
            $logger->withProperty('table', 'notifications')
                ->withProperty('record_id', $model->getKey());
        } else {
            $logger->performedOn($model);
        }

        $logger->log($event);
    }

    /**
     * @param  array<int, string>|null  $changed
     * @return array<string, mixed>
     */
    private function values(Model $model, ?array $changed = null): array
    {
        $allowed = $model instanceof DatabaseNotification
            ? ['id', 'type', 'notifiable_type', 'notifiable_id', 'read_at']
            : ['id', 'uuid', 'model_type', 'model_id', 'collection_name', 'name', 'file_name', 'mime_type', 'disk', 'conversions_disk', 'size', 'order_column'];
        $attributes = [];

        foreach ($allowed as $attribute) {
            if (($changed === null || in_array($attribute, $changed, true)) && array_key_exists($attribute, $model->getAttributes())) {
                $attributes[$attribute] = $model->getAttributes()[$attribute];
            }
        }

        return $attributes;
    }

    /**
     * @param  array<int, string>  $changed
     * @return array<string, mixed>
     */
    private function oldValues(Model $model, array $changed): array
    {
        $allowed = $model instanceof DatabaseNotification
            ? ['id', 'type', 'notifiable_type', 'notifiable_id', 'read_at']
            : ['id', 'uuid', 'model_type', 'model_id', 'collection_name', 'name', 'file_name', 'mime_type', 'disk', 'conversions_disk', 'size', 'order_column'];
        $old = [];

        foreach ($allowed as $attribute) {
            if (in_array($attribute, $changed, true)) {
                $old[$attribute] = $model->getRawOriginal($attribute);
            }
        }

        return $old;
    }
}
