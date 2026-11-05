<?php

namespace App\Http\Controllers;

use App\Models\AssetPublicToken;
use Illuminate\Http\Response;

/**
 * Public QR page `GET /q/{token}` — the URL printed on asset labels.
 *
 * Serves the SPA shell (the React page fetches the allowlisted data from
 * `GET /api/public/assets/{token}`), but answers with HTTP 404 when the token is
 * malformed, unknown, or belongs to a soft-deleted asset, so the status of the
 * URL itself is honest. Never indexed by search engines.
 */
class PublicAssetPageController extends Controller
{
    public const TOKEN_PATTERN = '/^[0-9a-f]{32}$/';

    public function __invoke(string $token): Response
    {
        $visible = preg_match(self::TOKEN_PATTERN, $token) === 1
            && AssetPublicToken::query()->where('token', $token)->first()?->asset !== null;

        return response()
            ->view('welcome', [], $visible ? 200 : 404)
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
