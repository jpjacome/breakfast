<?php

declare(strict_types=1);

use App\Actions\DescribeBrandAsset;
use App\Enums\AssetSource;
use App\Enums\AssetVisibility;
use App\Enums\BrandEggLayer;
use App\Enums\DeliverableItem;
use App\Models\BrandAsset;
use App\Models\Client;
use App\Services\BrandEgg\EggComposer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * An image becomes text once, and the Brand Egg reads the text.
 *
 * ⚠️ THE WHOLE POINT IS THAT THE EGG NEVER SEES A PICTURE. It is composed from
 * fields of the brand and nothing else (docs/brand-egg.md §1), so a logo can
 * only reach layer 4 after DescribeBrandAsset has turned it into words on the
 * asset's own row. These tests pin both halves of that: the reading gets
 * written, and the composer sends the words rather than the file.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->client = Client::factory()->create(['name' => 'Cafetería Norte']);

    config()->set('ai.providers.deepseek.api_key', 'sk-test');
});

/** The provider answering with a description. */
function fakeAssetReading(string $text = 'Emblema de trazo grueso sobre fondo crema.'): void
{
    Http::fake(['api.deepseek.com/*' => Http::response([
        'model' => 'deepseek-v4-pro',
        'choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 400, 'completion_tokens' => 40],
    ])]);
}

/**
 * A real image on the fake disk, with a row pointing at it.
 *
 * ⚠️ THE READING IS forceFill'd, NOT created. `visual_reading` and `read_at`
 * are deliberately absent from BrandAsset::$fillable — they are written by
 * DescribeBrandAsset and by nothing else, so a request can never mass-assign a
 * description of a picture nobody looked at. create() would drop them silently,
 * which is exactly how this helper was wrong the first time.
 */
function anImageAsset(Client $client, array $attributes = []): BrandAsset
{
    $file = UploadedFile::fake()->image('emblema.png', 64, 64);
    $path = $file->store('marcas/test/assets', 'local');

    $reading = array_intersect_key($attributes, array_flip(['visual_reading', 'read_at']));
    $columns = array_diff_key($attributes, $reading);

    $asset = $client->brandAssets()->create([
        'title' => 'Emblema principal',
        'disk' => 'local',
        'path' => $path,
        'original_name' => 'emblema.png',
        'mime' => 'image/png',
        'size_bytes' => 1024,
        'visibility' => AssetVisibility::Compartido,
        'source' => AssetSource::Subida,
        ...$columns,
    ]);

    if ($reading !== []) {
        $asset->forceFill($reading)->save();
    }

    return $asset;
}

/* --- what gets read, and what does not ----------------------------------- */

it('reads an uploaded image and stores the description', function () {
    $asset = anImageAsset($this->client);
    fakeAssetReading();

    expect(app(DescribeBrandAsset::class)->handle($asset))->toBeTrue();

    $asset->refresh();

    expect($asset->visual_reading)->toBe('Emblema de trazo grueso sobre fondo crema.')
        ->and($asset->read_at)->not->toBeNull();
});

it('never reads a file that fell out of a chat', function () {
    // ⚠️ A `referencia` is a screenshot somebody pasted to ask about it. It is
    // in the folder so the conversation can still show the picture, not because
    // anyone decided it describes the brand. Describing those is the cost with
    // none of the value — and Http::preventStrayRequests() would catch a call.
    $asset = anImageAsset($this->client, [
        'source' => AssetSource::Referencia,
        'visibility' => AssetVisibility::Interno,
    ]);

    expect(app(DescribeBrandAsset::class)->handle($asset))->toBeFalse()
        ->and($asset->fresh()->visual_reading)->toBeNull();
});

it('never reads a PDF, because the onboarding assistant already does', function () {
    // Two readings of one brandbook can disagree, and the digest already has
    // one. Paying twice to create a contradiction is the worst of both.
    $asset = anImageAsset($this->client, [
        'mime' => 'application/pdf',
        'original_name' => 'brandbook.pdf',
    ]);

    expect(app(DescribeBrandAsset::class)->handle($asset))->toBeFalse();
});

it('does not read the same image twice', function () {
    $asset = anImageAsset($this->client);
    fakeAssetReading();

    app(DescribeBrandAsset::class)->handle($asset);

    // The second call must not reach the provider at all.
    expect(app(DescribeBrandAsset::class)->handle($asset->fresh()))->toBeFalse();

    Http::assertSentCount(1);
});

it('reads again when the row moved after the last reading', function () {
    // ⚠️ THE STALENESS CHECK. A file replaced under the same row leaves a
    // description of the old picture, and comparing read_at with updated_at is
    // the only signal that the bytes behind a path changed.
    $asset = anImageAsset($this->client);
    fakeAssetReading();
    app(DescribeBrandAsset::class)->handle($asset);

    $this->travel(5)->minutes();
    $asset->fresh()->touch();

    expect(app(DescribeBrandAsset::class)->shouldRead($asset->fresh()))->toBeTrue();
});

it('leaves the upload alone when the provider is down', function () {
    // ⚠️ NEVER THROWS. This runs inside an upload: a provider outage must leave
    // the file uploaded and the row intact. An empty reading is a column
    // somebody can fill later; an exception loses what was actually asked for.
    $asset = anImageAsset($this->client);

    Http::fake(['api.deepseek.com/*' => Http::response('', 503)]);

    expect(app(DescribeBrandAsset::class)->handle($asset))->toBeFalse()
        ->and($asset->fresh()->exists)->toBeTrue()
        ->and($asset->fresh()->visual_reading)->toBeNull();
});

/* --- what the composer does with it -------------------------------------- */

it('sends layer 4 the words, never the picture', function () {
    $this->client->deliverables->update([
        DeliverableItem::Emblemas->value => 'Un emblema circular.',
    ]);

    anImageAsset($this->client, [
        'visual_reading' => 'FONDO CREMA CON UN TRAZO GRUESO',
        'read_at' => now(),
    ]);

    fakeAssetReading('La marca se apoya en un emblema de trazo grueso.');

    app(EggComposer::class)->compose($this->client->fresh(), BrandEggLayer::Assets);

    Http::assertSent(function ($request) {
        $sent = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

        return str_contains($sent, 'FONDO CREMA CON UN TRAZO GRUESO')
            // No attachment part: the composer is text in, text out.
            && ! str_contains($sent, 'image_url')
            && ! str_contains($sent, 'base64');
    });
});

it('gives the asset readings to layer 4 and to no other layer', function () {
    $this->client->deliverables->update([
        DeliverableItem::Relato->value => 'Nació en una cocina.',
        DeliverableItem::Valores->value => 'Cercanía.',
    ]);

    anImageAsset($this->client, [
        'visual_reading' => 'SOLO-PARA-LA-CAPA-CUATRO',
        'read_at' => now(),
    ]);

    fakeAssetReading('Un párrafo.');
    app(EggComposer::class)->compose($this->client->fresh(), BrandEggLayer::Esencia);

    Http::assertSent(fn ($request) => ! str_contains(
        json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'SOLO-PARA-LA-CAPA-CUATRO',
    ));

    expect(BrandEggLayer::Assets->readsAssetReadings())->toBeTrue()
        ->and(BrandEggLayer::Esencia->readsAssetReadings())->toBeFalse()
        ->and(BrandEggLayer::Universo->readsAssetReadings())->toBeFalse();
});

it('gives layer 4 the visual entregables it was missing', function () {
    // Until 2026-09-16 the layer called "Brand Assets / Icons" read only
    // look_and_feel and relato, while nine visual entregables fed nothing at
    // all. That absence was the whole reason the fourth ring stayed hollow.
    $sources = collect(BrandEggLayer::Assets->sources())->map(fn ($i) => $i->value);

    expect($sources)->toContain('emblemas')
        ->toContain('colores')
        ->toContain('identificativo_principal')
        ->toContain('brand_universe')
        ->toContain('tipografia');
});
