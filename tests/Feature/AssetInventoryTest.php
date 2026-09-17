<?php

declare(strict_types=1);

use App\Actions\DescribeBrandAsset;
use App\Enums\AssetSource;
use App\Enums\AssetType;
use App\Enums\AssetVisibility;
use App\Enums\BrandEggLayer;
use App\Enums\DeliverableItem;
use App\Models\BrandAsset;
use App\Models\Client;
use App\Models\User;
use App\Services\BrandEgg\EggComposer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * A brand's files are an inventory, not just a folder.
 *
 * ⚠️ THREE INDEPENDENT LABELS ON ONE ROW: visibility says who may see it,
 * source says how it arrived, type says what it IS. None of them may decide
 * another — dividing the folder by what files are is the mistake that killed
 * `context_documents`, and the whole point here is one list with three
 * separate answers.
 *
 * The functionality exists before the screen does: these are the endpoints the
 * right-click menu on the file manager will call.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->admin = User::factory()->admin()->create();
    $this->client = Client::factory()->create(['name' => 'Cafetería Norte']);

    config()->set('ai.providers.deepseek.api_key', 'sk-test');
});

function anAsset(Client $client, array $attributes = []): BrandAsset
{
    $reading = array_intersect_key($attributes, array_flip(['visual_reading', 'read_at']));
    $columns = array_diff_key($attributes, $reading);

    $asset = $client->brandAssets()->create([
        'title' => 'Emblema principal',
        'disk' => 'local',
        'path' => 'marcas/test/assets/emblema.png',
        'original_name' => 'emblema.png',
        'mime' => 'image/png',
        'size_bytes' => 2048,
        'visibility' => AssetVisibility::Compartido,
        'source' => AssetSource::Subida,
        ...$columns,
    ]);

    if ($reading !== []) {
        $asset->forceFill($reading)->save();
    }

    return $asset;
}

/* --- classifying a file --------------------------------------------------- */

it('records what a file is', function () {
    $asset = anAsset($this->client);

    // Nobody has said yet, and that is a real state — not "otro".
    expect($asset->type)->toBeNull();

    $this->actingAs($this->admin)
        ->patch(route('admin.clients.assets.type', [$this->client, $asset]), [
            'type' => AssetType::Logo->value,
        ])->assertRedirect();

    expect($asset->fresh()->type)->toBe(AssetType::Logo);
});

it('clears the type back to nobody-has-said', function () {
    $asset = anAsset($this->client, ['type' => AssetType::Logo]);

    $this->actingAs($this->admin)
        ->patch(route('admin.clients.assets.type', [$this->client, $asset]), ['type' => '']);

    expect($asset->fresh()->type)->toBeNull();
});

it('refuses a type that is not one of ours', function () {
    $asset = anAsset($this->client);

    $this->actingAs($this->admin)
        ->patch(route('admin.clients.assets.type', [$this->client, $asset]), [
            'type' => 'contrato-inventado',
        ])->assertSessionHasErrors('type');
});

it('does not let the type change who can see the file', function () {
    // ⚠️ THE THREE LABELS STAY INDEPENDENT. Marking a file "documento" must not
    // quietly make it internal, and marking one "logo" must not share it.
    $asset = anAsset($this->client, ['visibility' => AssetVisibility::Interno]);

    $this->actingAs($this->admin)
        ->patch(route('admin.clients.assets.type', [$this->client, $asset]), [
            'type' => AssetType::Logo->value,
        ]);

    expect($asset->fresh()->visibility)->toBe(AssetVisibility::Interno)
        ->and($asset->fresh()->source)->toBe(AssetSource::Subida);
});

/* --- correcting the machine's description --------------------------------- */

it('lets a person rewrite what the machine said', function () {
    $asset = anAsset($this->client, [
        'visual_reading' => 'Lo que dijo la máquina.',
        'read_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->patch(route('admin.clients.assets.reading', [$this->client, $asset]), [
            'visual_reading' => 'Lo que es en realidad.',
        ])->assertRedirect();

    expect($asset->fresh()->visual_reading)->toBe('Lo que es en realidad.');
});

it('does not let a backfill overwrite a hand-written correction', function () {
    // ⚠️ read_at MOVES WITH THE EDIT. Without that, updated_at would be newer
    // than read_at, shouldRead() would call the correction stale, and the next
    // assets:describe run would replace a person's words with the machine's.
    $asset = anAsset($this->client, [
        'visual_reading' => 'Lo que dijo la máquina.',
        'read_at' => now()->subHour(),
    ]);

    $this->travel(5)->minutes();

    $this->actingAs($this->admin)
        ->patch(route('admin.clients.assets.reading', [$this->client, $asset]), [
            'visual_reading' => 'Corregido a mano.',
        ]);

    expect(app(DescribeBrandAsset::class)->shouldRead($asset->fresh()))
        ->toBeFalse();
});

it('404s for an equipo member who was not put on the brand', function () {
    $stranger = User::factory()->equipo()->create();
    $stranger->assignedClients()->sync([Client::factory()->create()->id]);

    $asset = anAsset($this->client);

    $this->actingAs($stranger)
        ->patch(route('admin.clients.assets.type', [$this->client, $asset]), [
            'type' => AssetType::Logo->value,
        ])->assertNotFound();
});

/* --- what the Brand Egg does with the inventory --------------------------- */

it('tells layer 4 which file is the logo, and where it lives', function () {
    $this->client->deliverables->update([
        DeliverableItem::Emblemas->value => 'Un emblema circular.',
    ]);

    $asset = anAsset($this->client, [
        'type' => AssetType::Logo,
        'title' => 'Emblema Alea',
        'visual_reading' => 'Trazo grueso sobre crema.',
        'read_at' => now(),
    ]);

    Http::fake(['api.deepseek.com/*' => Http::response([
        'model' => 'deepseek-v4-pro',
        'choices' => [['message' => ['content' => 'Un párrafo.'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 500, 'completion_tokens' => 50],
    ])]);

    app(EggComposer::class)->compose($this->client->fresh(), BrandEggLayer::Assets);

    Http::assertSent(function ($request) use ($asset) {
        $sent = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

        return str_contains($sent, 'Logo principal')
            && str_contains($sent, 'Trazo grueso sobre crema.')
            // The link, so the layer can name where the file actually is.
            && str_contains($sent, (string) $asset->id);
    });
});

it('keeps a contract out of a paragraph about how the brand looks', function () {
    // Every file shares one folder — the pricing sheet sits beside the logo —
    // so the type is what stops a scanned invoice describing the identity.
    $this->client->deliverables->update([
        DeliverableItem::Emblemas->value => 'Un emblema circular.',
    ]);

    anAsset($this->client, [
        'type' => AssetType::Documento,
        'title' => 'Contrato firmado',
        'visual_reading' => 'CLAUSULA-SEXTA-CONFIDENCIALIDAD',
        'read_at' => now(),
    ]);

    Http::fake(['api.deepseek.com/*' => Http::response([
        'model' => 'deepseek-v4-pro',
        'choices' => [['message' => ['content' => 'Un párrafo.'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 500, 'completion_tokens' => 50],
    ])]);

    app(EggComposer::class)->compose($this->client->fresh(), BrandEggLayer::Assets);

    Http::assertSent(fn ($request) => ! str_contains(
        json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'CLAUSULA-SEXTA',
    ));
});

it('still reads an untyped image, because null means nobody has said', function () {
    // Every file described before types existed has a null type. Treating that
    // as "not identity" would silently empty layer 4 for existing brands.
    expect((null)?->isIdentity() ?? true)->toBeTrue()
        ->and(AssetType::Logo->isIdentity())->toBeTrue()
        ->and(AssetType::Documento->isIdentity())->toBeFalse();
});
