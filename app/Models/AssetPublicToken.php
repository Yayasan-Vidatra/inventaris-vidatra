<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Public QR token of one asset (`asset_public_tokens`). Rows are written only
 * by AssetPublicTokenService (App\Services\Asset); this model is used to
 * resolve a token on the public `/q/{token}` page and its API.
 *
 * `asset()` uses Asset's default SoftDeletes scope, so a soft-deleted asset
 * resolves to null — the public page treats that exactly like an unknown token.
 */
class AssetPublicToken extends Model
{
    protected $table = 'asset_public_tokens';

    protected $primaryKey = 'asset_id';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'asset_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }
}
