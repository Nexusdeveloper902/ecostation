<?php

namespace App\Http\Requests;

use App\Services\Hce\HceCredentialAuth;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CaptureAssociateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // reader identity is enforced by the reader.auth middleware
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'credential_uid' => ['required', 'string', 'max:255'],
            // Same relayed HCE proof as the tap endpoint.
            'hce_nonce' => ['nullable', 'string', HceCredentialAuth::NONCE_RULE, 'required_with:hce_mac'],
            'hce_mac' => ['nullable', 'string', HceCredentialAuth::MAC_RULE, 'required_with:hce_nonce'],
        ];
    }
}
