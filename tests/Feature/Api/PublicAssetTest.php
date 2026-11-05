<?php

namespace Tests\Feature\Api;

use App\Exceptions\QrBaseUrlNotConfiguredException;
use App\Models\Asset;
use App\Models\AssetPhoto;
use App\Services\Asset\AssetPublicTokenService;
use App\Services\Label\AssetLabelPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Public QR access — `/q/{token}` and `/api/public/assets/{token}[/photos/{slot}]`.
 * Read-only, no login, one asset per random token, allowlisted fields only.
 * Soft-deleted assets answer 404; written-off assets are shown with their status.
 */
class PublicAssetTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    private function tokenFor(Asset $asset): string
    {
        return app(AssetPublicTokenService::class)->tokenFor($asset);
    }

    private function addPhoto(Asset $asset, int $slot, string $bytes): AssetPhoto
    {
        $path = "asset-photos/{$asset->id}/photo-{$slot}.jpg";
        Storage::disk('local')->put($path, $bytes);

        return AssetPhoto::query()->forceCreate([
            'asset_id' => $asset->id, 'slot' => $slot, 'path' => $path, 'mime' => 'image/jpeg',
            'size_bytes' => strlen($bytes), 'width' => 10, 'height' => 10,
        ]);
    }

    /* ------------------------------------------------------------------ tokens */

    public function test_token_is_random_hex_stable_and_one_per_asset(): void
    {
        $asset = $this->existingAsset('001');

        $first = $this->tokenFor($asset);
        $again = app(AssetPublicTokenService::class)->tokenFor($asset->fresh()); // new service instance

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first);
        $this->assertSame($first, $again);
        $this->assertSame(1, DB::table('asset_public_tokens')->where('asset_id', $asset->id)->count());
    }

    public function test_bulk_ensure_creates_one_distinct_token_per_asset(): void
    {
        $assets = collect(['001', '002', '003'])->map(fn (string $seq) => $this->existingAsset($seq));
        $service = app(AssetPublicTokenService::class);

        $service->ensureFor($assets);
        $service->ensureFor($assets); // idempotent

        $tokens = DB::table('asset_public_tokens')->pluck('token');
        $this->assertCount(3, $tokens);
        $this->assertCount(3, $tokens->unique());
    }

    public function test_label_qr_targets_the_public_token_url_and_reuses_the_token(): void
    {
        $asset = $this->existingAsset('001');
        $service = app(AssetLabelPdfService::class);

        $url = $service->qrTargetUrl($asset);

        $this->assertSame('https://qr.inventaris-vidatra.test/q/'.$this->tokenFor($asset), $url);
        $this->assertStringNotContainsString('/a/', $url);
        $this->assertSame($url, app(AssetLabelPdfService::class)->qrTargetUrl($asset->fresh()));
    }

    public function test_no_token_is_created_when_the_qr_base_url_is_not_configured(): void
    {
        config(['inventory.qr_base_url' => null]);
        $asset = $this->existingAsset('001');

        try {
            app(AssetLabelPdfService::class)->qrTargetUrl($asset);
            $this->fail('expected QrBaseUrlNotConfiguredException');
        } catch (QrBaseUrlNotConfiguredException) {
            // expected
        }

        $this->assertSame(0, DB::table('asset_public_tokens')->count());
    }

    public function test_printing_a_label_creates_the_token(): void
    {
        $asset = $this->existingAsset('001');
        $this->actingAs($this->operator());

        $this->get("/api/assets/{$asset->id}/label")->assertOk();

        $this->assertSame(1, DB::table('asset_public_tokens')->where('asset_id', $asset->id)->count());
    }

    /* ------------------------------------------------------------------ public detail API */

    public function test_valid_token_returns_only_allowlisted_fields_without_login(): void
    {
        $room = $this->room();
        $asset = $this->existingAsset('017', 2021, [
            'room_id' => $room->id, 'condition' => 'baik',
            'serial_no' => 'SERIAL-SECRET-1', 'notes' => 'catatan internal', 'brand_model' => 'Merk Internal',
        ]);
        $token = $this->tokenFor($asset);

        $response = $this->getJson("/api/public/assets/{$token}")
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertJsonPath('data.asset_code', $asset->asset_code)
            ->assertJsonPath('data.name', 'Subkategori ZC-001')
            ->assertJsonPath('data.category', 'Kategori ZC')
            ->assertJsonPath('data.location', 'Lokasi ZL')
            ->assertJsonPath('data.room', $room->name)
            ->assertJsonPath('data.condition_label', 'Baik')
            ->assertJsonPath('data.status', 'active');

        $this->assertSame(
            ['asset_code', 'name', 'category', 'location', 'room', 'condition', 'condition_label', 'status', 'status_label', 'photos'],
            array_keys($response->json('data')),
        );
        $body = $response->getContent();
        foreach (['SERIAL-SECRET-1', 'catatan internal', 'Merk Internal', '"id"', 'sequence_no', 'created_by', 'path'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "public response leaks [{$forbidden}]");
        }
    }

    public function test_unknown_or_malformed_token_returns_404(): void
    {
        $this->existingAsset('001');

        $this->getJson('/api/public/assets/'.str_repeat('a', 32))
            ->assertNotFound()
            ->assertJsonPath('message', 'Aset tidak ditemukan.');
        $this->getJson('/api/public/assets/NOT-A-TOKEN')->assertNotFound();
        $this->getJson('/api/public/assets/1')->assertNotFound();
    }

    public function test_soft_deleted_asset_is_not_shown(): void
    {
        $asset = $this->existingAsset('001');
        $token = $this->tokenFor($asset);
        $asset->delete();

        $this->getJson("/api/public/assets/{$token}")->assertNotFound();
        $this->get("/q/{$token}")->assertNotFound();
    }

    public function test_written_off_asset_is_shown_with_its_status(): void
    {
        $asset = $this->existingAsset('001', 2020, ['is_written_off' => true, 'written_off_on' => '2026-01-02']);

        $this->getJson('/api/public/assets/'.$this->tokenFor($asset))
            ->assertOk()
            ->assertJsonPath('data.status', 'written_off')
            ->assertJsonPath('data.status_label', 'Dihapusbukukan');
    }

    public function test_there_is_no_listing_search_or_mutation_endpoint(): void
    {
        $asset = $this->existingAsset('001');
        $token = $this->tokenFor($asset);

        $this->getJson('/api/public/assets')->assertNotFound();
        $this->getJson('/api/public/assets?search=001')->assertNotFound();
        $this->putJson("/api/public/assets/{$token}", ['notes' => 'x'])->assertStatus(405);
        $this->patchJson("/api/public/assets/{$token}", ['notes' => 'x'])->assertStatus(405);
        $this->deleteJson("/api/public/assets/{$token}")->assertStatus(405);
        $this->postJson("/api/public/assets/{$token}")->assertStatus(405);

        // the internal API stays behind authentication
        $this->getJson('/api/assets')->assertStatus(401);
        $this->getJson("/api/assets/{$asset->id}")->assertStatus(401);
        $this->assertNull($asset->fresh()->notes);
    }

    /* ------------------------------------------------------------------ public photos */

    public function test_photo_is_served_only_through_its_own_assets_token(): void
    {
        Storage::fake('local');
        $a = $this->existingAsset('001');
        $b = $this->existingAsset('002');
        $this->addPhoto($a, 1, 'JPEG-BYTES-OF-A');
        $this->addPhoto($b, 1, 'JPEG-BYTES-OF-B');
        $tokenA = $this->tokenFor($a);

        $response = $this->get("/api/public/assets/{$tokenA}/photos/1")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('JPEG-BYTES-OF-A', $response->getContent());
        $this->assertStringNotContainsString((string) $a->id, (string) $response->headers->get('Content-Disposition'));

        $this->getJson("/api/public/assets/{$tokenA}/photos/2")->assertNotFound();
        $this->getJson("/api/public/assets/{$tokenA}/photos/9")->assertNotFound();

        $this->getJson("/api/public/assets/{$tokenA}")
            ->assertJsonPath('data.photos.0.url', "/api/public/assets/{$tokenA}/photos/1");
    }

    /* ------------------------------------------------------------------ public page + old QR */

    public function test_public_page_answers_200_for_a_valid_token_and_404_otherwise(): void
    {
        $asset = $this->existingAsset('001');
        $token = $this->tokenFor($asset);

        $this->get("/q/{$token}")->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/q/'.str_repeat('0', 32))->assertNotFound();
        $this->get('/q/not-a-token')->assertNotFound();
    }

    public function test_old_id_based_qr_still_redirects_to_the_internal_detail(): void
    {
        $asset = $this->existingAsset('001');

        $this->get("/a/{$asset->id}")->assertRedirect("/inventory/{$asset->id}");
    }
}
