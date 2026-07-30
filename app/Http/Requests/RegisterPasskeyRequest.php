<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;

/**
 * A passkey registration, with the label treated as a nicety rather than a requirement.
 *
 * Everything that matters is inherited: the credential is deserialised by the parent, and the
 * creation options come from the session rather than from the request — so the challenge being
 * answered is the one this platform issued, not one the caller chose.
 *
 * The only change is the label. The package requires a name; here the form has a single optional
 * field, and refusing a registration because somebody left it blank would be turning a cosmetic
 * detail into a failed security enrolment.
 */
final class RegisterPasskeyRequest extends PasskeyRegistrationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),

            // Optional, and bounded well below the column width because this is only ever read by a
            // person choosing which passkey to remove.
            'name' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * What to call this passkey in the interface.
     */
    public function label(): string
    {
        $label = trim((string) $this->input('name', ''));

        return $label !== '' ? mb_substr($label, 0, 60) : 'Passkey';
    }
}
