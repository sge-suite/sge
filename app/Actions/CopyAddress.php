<?php

namespace App\Actions;

use App\Models\Address;
use InvalidArgumentException;

class CopyAddress
{
    public function handle(Address $address): Address
    {
        if (! $address->exists) {
            throw new InvalidArgumentException('A cópia histórica exige um endereço persistido.');
        }

        return $address->getConnection()->transaction(function () use ($address): Address {
            $original = $address->newQuery()->whereKey($address->getKey())->sharedLock()->firstOrFail();
            $copy = $original->replicate()->unsetRelations();
            $copy->setConnection($original->getConnectionName());
            $copy->saveOrFail();

            return $copy;
        });
    }
}
