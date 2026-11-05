<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicAssetResource;
use App\Models\Asset;
use App\Models\AssetPublicToken;
use App\Services\Asset\AssetPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public QR access — read-only, no authentication (`/api/public/...`).
 *
 * Exactly two GET endpoints, both addressed ONLY by an asset's random public
 * token: the asset's allowlisted detail and one of its photos by slot. There is
 * no listing, search or mutation here, and nothing accepts an asset id.
 *
 * Visibility: an unknown token and a soft-deleted asset both answer the same
 * plain 404 (no hint which one it was). A written-off asset is still shown —
 * it is a physical item that may still carry its label — with its status.
 */
class PublicAssetController extends Controller
{
    private const NOT_FOUND = 'Aset tidak ditemukan.';

    public function show(string $token): JsonResponse
    {
        $asset = $this->resolve($token);
        $asset->load(['location', 'category', 'room', 'photos']);

        return (new PublicAssetResource($asset, $token))
            ->response()
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function photo(Request $request, string $token, int $slot, AssetPhotoService $service): Response
    {
        $asset = $this->resolve($token);
        $photo = $asset->photos()->where('slot', $slot)->first();
        if ($photo === null) {
            abort(404, 'Foto tidak ditemukan.');
        }

        $response = new Response('', Response::HTTP_OK, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="foto-aset-'.$slot.'.jpg"',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Cache-Control' => 'private, no-cache',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
        $response->setEtag($photo->etag());

        if ($response->isNotModified($request)) {
            return $response;
        }

        $disk = $service->disk();
        $contents = $disk->exists($photo->path) ? $disk->get($photo->path) : null;
        if (! is_string($contents) || $contents === '') {
            abort(404, 'Foto tidak ditemukan.');
        }

        return $response->setContent($contents)->header('Content-Length', (string) strlen($contents));
    }

    /** The non-trashed asset behind a token, or a plain 404. */
    private function resolve(string $token): Asset
    {
        $asset = AssetPublicToken::query()->where('token', $token)->first()?->asset;
        if ($asset === null) {
            abort(404, self::NOT_FOUND);
        }

        return $asset;
    }
}
