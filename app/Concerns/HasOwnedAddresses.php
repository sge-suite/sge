<?php

namespace App\Concerns;

use App\Models\Address;
use App\Models\Internship;

trait HasOwnedAddresses
{
    /** @return list<string> */
    public function ownedAddressFields(): array
    {
        return $this instanceof Internship ? ['student_address_id', 'workplace_address_id'] : ['address_id'];
    }

    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(function (): ?bool {
            $ids = array_filter($this->only($this->ownedAddressFields()));
            $addresses = Address::query()->whereKey(array_values($ids))->lockForUpdate()->get();

            $deleted = parent::delete();
            if ($deleted) {
                foreach ($addresses as $address) {
                    if (! $address->hasReferences()) {
                        $address->delete();
                    }
                }
            }

            return $deleted;
        });
    }
}
