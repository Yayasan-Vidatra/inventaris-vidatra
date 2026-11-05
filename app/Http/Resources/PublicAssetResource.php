<?php

namespace App\Http\Resources;

use App\Models\Asset;
use App\Models\AssetPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public, read-only view of ONE asset (`GET /api/public/assets/{token}`).
 *
 * An explicit allowlist — never the internal {@see AssetResource}: no database
 * id, no sequence/year components, no serial/brand/notes/funding, no users, no
 * audit or mutation data, no storage paths. Photo URLs go through the token,
 * never through the asset id. In this application an asset has no separate
 * "name" field; its name is the subcategory name (e.g. "Meja Guru").
 *
 * @mixin Asset
 */
class PublicAssetResource extends JsonResource
{
    public function __construct(Asset $asset, private readonly string $token)
    {
        parent::__construct($asset);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Asset $asset */
        $asset = $this->resource;

        return [
            'asset_code' => $asset->asset_code,
            'name' => $asset->subcategory?->name,
            'category' => $asset->category?->name,
            'location' => $asset->location?->name,
            'room' => $asset->room?->name,
            'condition' => $asset->condition?->value,
            'condition_label' => $asset->condition?->label(),
            'status' => $asset->is_written_off ? 'written_off' : 'active',
            'status_label' => $asset->is_written_off ? 'Dihapusbukukan' : 'Aktif',
            'photos' => $asset->photos
                ->map(fn (AssetPhoto $photo): array => [
                    'slot' => $photo->slot,
                    'url' => '/api/public/assets/'.$this->token.'/photos/'.$photo->slot,
                ])
                ->values()
                ->all(),
        ];
    }
}
