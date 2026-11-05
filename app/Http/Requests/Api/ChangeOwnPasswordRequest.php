<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * `PUT /api/me/password` — any signed-in, active user changes THEIR OWN
 * password. There is no user parameter at all: the target is always
 * `$request->user()`, so no request field can point at another account.
 *
 * Shape only here. Whether `current_password` is actually correct is checked
 * in AccountPasswordController (Api) against the
 * stored hash, after this passes. New-password policy is the same as every
 * other password set in this app: min(12), see {@see StoreUserRequest}.
 */
class ChangeOwnPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(12), 'different:current_password'],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Kata sandi saat ini wajib diisi.',
            'password.required' => 'Kata sandi baru wajib diisi.',
            'password.min' => 'Kata sandi baru minimal 12 karakter.',
            'password.confirmed' => 'Konfirmasi kata sandi baru tidak cocok.',
            'password.different' => 'Kata sandi baru harus berbeda dari kata sandi saat ini.',
        ];
    }
}
