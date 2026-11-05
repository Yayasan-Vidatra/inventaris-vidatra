<?php

use App\Http\Controllers\AssetQrRedirectController;
use App\Http\Controllers\PublicAssetPageController;
use Illuminate\Support\Facades\Route;

/*
| Public QR-code redirect layer (Tahap 6.0). Registered BEFORE the SPA catch-all so
| it never falls through to the HTML shell. See AssetQrRedirectController for why
| this exists, why it uses asset.id, and why it deliberately does not require auth.
| `throttle:qr-redirect` (Tahap 6.9 R8.2, P3-2) — IP-based limiter defined in
| AppServiceProvider::configureQrRedirectRateLimiter(); stays public, no auth bypass.
*/
Route::get('/a/{asset}', AssetQrRedirectController::class)
    ->whereNumber('asset')
    ->withTrashed()
    ->middleware('throttle:qr-redirect')
    ->name('asset.qr-redirect');

/*
| Public, read-only asset page reached from the QR on labels printed from now on
| (`{QR_BASE_URL}/q/{token}`). Serves the SPA shell with HTTP 200, or 404 when the
| token is unknown / the asset is soft-deleted. No authentication; the data comes
| from the allowlisted `GET /api/public/assets/{token}`. See PublicAssetPageController.
*/
Route::get('/q/{token}', PublicAssetPageController::class)
    ->middleware('throttle:public-asset')
    ->name('asset.public');

/*
| The React SPA is served for every non-API path. `/api/*` is deliberately
| EXCLUDED so an unknown or wrong-method API request produces a JSON 404 / 405
| instead of falling through to the SPA HTML shell (see docs/api_convention.md §7).
*/
Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '^(?!api($|/)).*$');
