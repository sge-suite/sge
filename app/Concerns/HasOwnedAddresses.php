<?php

namespace App\Concerns;

use App\Models\Address;
use App\Models\Campus;
use App\Models\GrantingParty;
use App\Models\Internship;
use App\Support\AddressActivityContext;
use Spatie\Activitylog\Models\Activity;

trait HasOwnedAddresses
{
    /** @return list<string> */
    public function ownedAddressFields(): array
    {
        return $this instanceof Internship ? ['student_address_id', 'workplace_address_id'] : ['address_id'];
    }

    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        if ($this instanceof Campus || $this instanceof GrantingParty) {
            $activity->properties = ($activity->properties ?? collect())->put('address_owner', [
                'type' => $this->getMorphClass(), 'id' => $this->id, 'name' => $this->name,
                'campus_id' => $this instanceof Campus ? $this->id : $this->campus_id,
            ]);
        }
    }

    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(function (): ?bool {
            $ids = array_filter($this->only($this->ownedAddressFields()));
            $addresses = Address::query()->whereKey(array_values($ids))->lockForUpdate()->get();
            foreach ($addresses as $address) {
                $address->auditOwners = app(AddressActivityContext::class)->owners($address);
            }

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
