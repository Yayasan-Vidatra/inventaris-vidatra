<?php

namespace App\Services\Asset;

use App\Models\Asset;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Public QR tokens: one random, never-changing token per asset, created the
 * first time it is needed (when a label is printed).
 *
 * Concurrency: rows are inserted with INSERT IGNORE and then read back, so two
 * requests printing the same asset at once both end up with the single token
 * that won the `asset_id` primary key. A token collision on the UNIQUE `token`
 * index (128 random bits — practically impossible) is ignored the same way and
 * simply retried with a fresh token.
 */
class AssetPublicTokenService
{
    private const MAX_INSERT_ATTEMPTS = 3;

    /** @var array<int, string> asset id => token, cached for this instance */
    private array $known = [];

    public function tokenFor(Asset $asset): string
    {
        $this->ensureFor([$asset]);

        return $this->known[(int) $asset->id];
    }

    /**
     * Make sure every given asset has a token, with one read (plus one insert
     * for the missing ones) instead of a query per asset.
     *
     * @param  iterable<Asset>  $assets
     */
    public function ensureFor(iterable $assets): void
    {
        $pending = collect($assets)
            ->map(fn (Asset $asset): int => (int) $asset->id)
            ->unique()
            ->reject(fn (int $id): bool => isset($this->known[$id]))
            ->values();

        for ($attempt = 1; $pending->isNotEmpty(); $attempt++) {
            DB::table('asset_public_tokens')
                ->whereIn('asset_id', $pending->all())
                ->pluck('token', 'asset_id')
                ->each(function (string $token, int|string $assetId): void {
                    $this->known[(int) $assetId] = $token;
                });

            $pending = $pending->reject(fn (int $id): bool => isset($this->known[$id]))->values();
            if ($pending->isEmpty()) {
                return;
            }

            if ($attempt > self::MAX_INSERT_ATTEMPTS) {
                throw new RuntimeException('Could not allocate a public QR token for asset(s) '.$pending->implode(', ').'.');
            }

            $now = now();
            DB::table('asset_public_tokens')->insertOrIgnore(
                $pending->map(fn (int $id): array => [
                    'asset_id' => $id,
                    'token' => self::newToken(),
                    'created_at' => $now,
                ])->all()
            );
        }
    }

    /** 32 lowercase hex characters = 128 bits from the CSPRNG. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
