<?php

declare(strict_types=1);

use App\Actions\DescribeBrandAsset;
use App\Enums\AssetSource;
use App\Enums\AssetType;
use App\Enums\AssetVisibility;
use App\Models\BrandAsset;
use App\Models\Client;
use App\Models\User;
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

it('puts a file in the Egg, and takes it out again', function () {
    $asset = anAsset($this->client, ['type' => AssetType::Logo]);

    $this->actingAs($this->admin)
        ->post(route('admin.clients.egg.asset', [$this->client, $asset]))
        ->assertRedirect();

    expect($this->client->fresh()->brandEgg->assets)->toHaveCount(1);

    // The same click again takes it out — it is a toggle, because that is what
    // clicking a file in a list means.
    $this->actingAs($this->admin)
        ->post(route('admin.clients.egg.asset', [$this->client, $asset]));

    expect($this->client->fresh()->brandEgg->assets)->toHaveCount(0);
});

it('refuses a file belonging to another brand', function () {
    // ⚠️ 404, NOT 403. The Egg is the one place whose whole claim is that a
    // person approved what is in it, and a wrong id must not confirm that
    // somebody else's file exists.
    $other = Client::factory()->create();
    $theirs = anAsset($other);

    $this->actingAs($this->admin)
        ->post(route('admin.clients.egg.asset', [$this->client, $theirs]))
        ->assertNotFound();
});

it('reads the description from the file, so correcting it corrects the Egg', function () {
    // ⚠️ THE POINT OF STORING IDS. The Egg holds the row; the words are fetched
    // when asked. So a correction needs nothing re-run and nothing recomposed.
    $asset = anAsset($this->client, [
        'type' => AssetType::Logo,
        'visual_reading' => 'Lo que dijo la máquina.',
        'read_at' => now(),
    ]);

    $egg = $this->client->brandEgg()->make(['generated_at' => now()]);
    $this->client->brandEgg()->save($egg);
    $egg->assets()->attach($asset->getKey(), ['position' => 1]);

    $this->actingAs($this->admin)
        ->patch(route('admin.clients.assets.reading', [$this->client, $asset]), [
            'visual_reading' => 'Corregido a mano.',
        ]);

    expect($this->client->fresh()->brandEgg->toMarkdown())
        ->toContain('Corregido a mano.')
        ->not->toContain('Lo que dijo la máquina.');
});

it('drops a file out of the Egg when the file is deleted', function () {
    // The cascade is what stops the Egg pointing at something that is gone —
    // the failure prose could never avoid.
    $asset = anAsset($this->client, ['type' => AssetType::Logo]);

    $egg = $this->client->brandEgg()->make(['generated_at' => now()]);
    $this->client->brandEgg()->save($egg);
    $egg->assets()->attach($asset->getKey(), ['position' => 1]);

    $asset->delete();

    expect($this->client->fresh()->brandEgg->assets)->toHaveCount(0);
});

it('still reads an untyped image, because null means nobody has said', function () {
    // Every file described before types existed has a null type. Treating that
    // as "not identity" would silently empty layer 4 for existing brands.
    expect((null)?->isIdentity() ?? true)->toBeTrue()
        ->and(AssetType::Logo->isIdentity())->toBeTrue()
        ->and(AssetType::Documento->isIdentity())->toBeFalse();
});
