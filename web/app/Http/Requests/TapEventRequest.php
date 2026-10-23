<?php

namespace App\Http\Requests;

use App\Services\Hce\HceCredentialAuth;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TapEventRequest extends FormRequest
{
    /** Reader identity is enforced by the reader.auth middleware. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'credential_uid' => ['required', 'string', 'max:255'],
            // Optional device clock (ISO 8601). Absent => server time.
            'client_timestamp' => ['nullable', 'date'],
            // The relayed HCE CHALLENGE transcript (hex), required in
            // practice for phone credentials; ignored for physical cards so
            // old readers keep their exact contract.
            'hce_nonce' => ['nullable', 'string', HceCredentialAuth::NONCE_RULE, 'required_with:hce_mac'],
            'hce_mac' => ['nullable', 'string', HceCredentialAuth::MAC_RULE, 'required_with:hce_nonce'],
        ];
    }
}
