<?php

namespace App\Http\Resources;

use Spark\Http\Request;

/** Private account fields: use only in the owner's authenticated/session response. */
class AccountResource extends ProfileResource
{
    public function toArray(?Request $request = null): array
    {
        return parent::toArray($request) + [
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'social_identities' => $this->whenLoaded('authIdentities'),
        ];
    }
}
