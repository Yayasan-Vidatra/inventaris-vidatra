<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ChangeOwnPasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Self-service password change for every signed-in user, whatever the role.
 *
 * After a successful change:
 *  - the new password is stored through User's `hashed` cast (never plaintext);
 *  - the current session is regenerated (old session id destroyed);
 *  - every OTHER session of this user is deleted from the `sessions` table, so
 *    other browsers/devices are signed out on their next request. This only
 *    works with the `database` session driver (this app's configuration); with
 *    another driver that step is skipped — the response says how many were ended;
 *  - the remember token is rotated and any Sanctum personal access tokens are
 *    revoked (the SPA issues none today; this only closes that door).
 */
class AccountPasswordController extends Controller
{
    public function update(ChangeOwnPasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Kata sandi saat ini tidak sesuai.'],
            ]);
        }

        $user->forceFill([
            'password' => $validated['password'],
            'remember_token' => Str::random(60),
        ])->save();
        $user->tokens()->delete();

        $endedSessions = $this->endOtherSessions($request, $user);

        return response()->json([
            'message' => 'Kata sandi berhasil diubah.',
            'other_sessions_ended' => $endedSessions,
        ]);
    }

    /**
     * Regenerate this session, then delete the user's other session rows.
     * Returns how many other sessions were ended (0 when not applicable).
     */
    private function endOtherSessions(Request $request, User $user): int
    {
        if (! $request->hasSession()) {
            return 0;
        }

        $request->session()->regenerate(true);

        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getAuthIdentifier())
            ->where('id', '!=', $request->session()->getId())
            ->delete();
    }
}
