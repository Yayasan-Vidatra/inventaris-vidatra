<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Public QR access — one random public token per asset, used by the public,
 * read-only asset page `/q/{token}` (no login). Kept in its own table so the
 * public identifier stays separate from the internal asset record:
 *
 *  - `asset_id` is the primary key (one token per asset) with FK RESTRICT, like
 *    `asset_photos`: assets are only ever soft-deleted.
 *  - `token` is 32 lowercase hex characters (128 random bits from
 *    random_bytes()), UNIQUE. Hex keeps the value safe under the
 *    case-insensitive table collation and short enough to keep the label QR compact.
 *  - Tokens are created lazily, the first time a label is printed for an asset
 *    (App\Services\Asset\AssetPublicTokenService), and never change
 *    afterwards. No existing row is touched by this migration — no backfill.
 *  - Explicit `utf8mb4_unicode_ci` so the table is identical on MySQL 8.4 and
 *    MariaDB 10.11.
 *
 * down() refuses to drop the table while it holds tokens: every token may
 * already be printed on a physical label, and dropping them would make those
 * QR codes stop resolving. Delete the rows deliberately before rolling back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_public_tokens', function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');

            $table->unsignedBigInteger('asset_id')->primary();
            $table->char('token', 32);
            $table->timestamp('created_at')->useCurrent();

            $table->unique('token', 'uq_asset_public_tokens_token');

            $table->foreign('asset_id', 'fk_asset_public_tokens_asset')
                ->references('id')->on('assets')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('asset_public_tokens') && DB::table('asset_public_tokens')->exists()) {
            throw new RuntimeException(
                'Refusing to drop `asset_public_tokens`: it still holds public QR tokens that may already be '.
                'printed on asset labels. Remove the rows deliberately before rolling back.'
            );
        }

        Schema::dropIfExists('asset_public_tokens');
    }
};
