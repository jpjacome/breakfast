<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\AssetVisibility;
use App\Enums\PortalSection;
use App\Models\BrandAsset;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

/**
 * Files in a brand's folder that the brand must never see.
 *
 * A brand's folder holds the deliverables AND Breakfast's own working papers:
 * the contract, the pricing sheet, the notes from the call where the team
 * disagreed. Both belong with the brand and only one belongs in front of it.
 *
 * ⚠️ TWO THINGS HAVE TO BE TRUE, and only one of them is obvious. The listing
 * must not show an internal file — but the link is public knowledge the moment
 * it is pasted into an entregable, so the DOWNLOAD is where the real check
 * lives (User::canReachBrandAsset). A test suite that only proved the list was
 * filtered would be proving the decorative half.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->admin = User::factory()->admin()->create();
    $this->client = Client::factory()->create(['name' => 'Cafetería Norte', 'slug' => 'cafeteria-norte']);
    $this->owner = User::factory()->clientOwner($this->client->id, [PortalSection::BrandAssets->value => AccessLevel::Read->value])->create();
});

function anInternalFile(Client $client, string $title = 'Contrato firmado'): BrandAsset
{
    return BrandAsset::factory()->create([
        'client_id' => $client->id,
        'title' => $title,
        'visibility' => AssetVisibility::Interno,
    ]);
}

/* --- uploading ----------------------------------------------------------- */

test('an admin can upload a file only Breakfast will see', function () {
    actingAs($this->admin)
        ->from(route('admin.clients.process.edit', $this->client))
        ->post(route('admin.clients.assets.store', $this->client), [
            'files' => [UploadedFile::fake()->create('contrato.pdf', 12)],
            'visibility' => AssetVisibility::Interno->value,
        ])
        ->assertRedirect();

    expect($this->client->brandAssets()->firstOrFail()->isInternal())->toBeTrue();
});

test('an upload that says nothing about visibility is shared, as it always was', function () {
    // The safe default is the one that matches every file uploaded before this
    // choice existed. The direction that costs something — a contract in front
    // of the client — has to be chosen on purpose.
    actingAs($this->admin)
        ->from(route('admin.clients.process.edit', $this->client))
        ->post(route('admin.clients.assets.store', $this->client), [
            'files' => [UploadedFile::fake()->image('logo.png')],
        ]);

    expect($this->client->brandAssets()->firstOrFail()->visibility)
        ->toBe(AssetVisibility::Compartido);
});

test('a made-up visibility is refused rather than quietly taken', function () {
    actingAs($this->admin)
        ->from(route('admin.clients.process.edit', $this->client))
        ->post(route('admin.clients.assets.store', $this->client), [
            'files' => [UploadedFile::fake()->image('logo.png')],
            'visibility' => 'secreto',
        ])
        ->assertSessionHasErrors('visibility');

    expect($this->client->brandAssets()->count())->toBe(0);
});

/* --- what the brand sees ------------------------------------------------- */

test('the brand is not shown an internal file, or told one exists', function () {
    anInternalFile($this->client);
    BrandAsset::factory()->create([
        'client_id' => $this->client->id,
        'title' => 'Logotipo principal',
    ]);

    actingAs($this->owner)
        ->get(route('portal.brand_assets'))
        ->assertOk()
        ->assertSee('Logotipo principal')
        // Not even as a greyed-out row: naming a file somebody cannot open
        // still tells them the contract exists.
        ->assertDontSee('Contrato firmado');
});

test('the brand cannot download an internal file even holding its link', function () {
    // The case that matters. A link pasted into an entregable, forwarded in a
    // mail, or simply guessed is not a secret — the gate is.
    $internal = anInternalFile($this->client);

    actingAs($this->owner)
        ->get(route('assets.download', $internal))
        // 404, matching every other gate in the app: probing ids learns nothing.
        ->assertNotFound();
});

test('Breakfast can still download it', function () {
    $internal = anInternalFile($this->client);

    Storage::disk('local')->put($internal->path, 'contenido');

    actingAs($this->admin)
        ->get(route('assets.download', $internal))
        ->assertOk();
});

test('an internal file is still refused to a client of another brand', function () {
    // Visibility narrows; it never widens. Making a file internal must not
    // accidentally take it out of the brand check it was already inside.
    $other = User::factory()->clientOwner(Client::factory()->create()->id, [PortalSection::BrandAssets->value => AccessLevel::Read->value])->create();

    actingAs($other)
        ->get(route('assets.download', anInternalFile($this->client)))
        ->assertNotFound();
});

/* --- fixing a mistake ---------------------------------------------------- */

test('an admin can make a shared file internal, and back again', function () {
    // ⚠️ THE POINT IS THE MISTAKE. Picking the wrong option on the upload form
    // puts a contract in front of a client; without this the only fix is
    // deleting the file, which also breaks any entregable linking it.
    $asset = BrandAsset::factory()->create(['client_id' => $this->client->id]);

    actingAs($this->admin)
        ->from(route('admin.files.show', $this->client))
        ->patch(route('admin.clients.assets.visibility', [$this->client, $asset]))
        ->assertRedirect(route('admin.files.show', $this->client));

    expect($asset->fresh()->isInternal())->toBeTrue();

    actingAs($this->admin)
        ->patch(route('admin.clients.assets.visibility', [$this->client, $asset]));

    expect($asset->fresh()->isInternal())->toBeFalse();
});

test('a client cannot flip a file to visible', function () {
    // The route is behind 'breakfast', so the portal side has no path to it —
    // 404 rather than 403, like the rest of /admin.
    $asset = anInternalFile($this->client);

    actingAs($this->owner)
        ->patch(route('admin.clients.assets.visibility', [$this->client, $asset]))
        ->assertNotFound();

    expect($asset->fresh()->isInternal())->toBeTrue();
});

/* --- the admin's own screens --------------------------------------------- */

test('both admin screens list internal files and mark them', function () {
    anInternalFile($this->client);

    foreach ([
        route('admin.files.show', $this->client),
        route('admin.clients.process.edit', $this->client),
    ] as $screen) {
        actingAs($this->admin)
            ->get($screen)
            ->assertOk()
            ->assertSee('Contrato firmado')
            // The badge is what stops somebody copying its link into an
            // entregable and wondering why the client gets a 404.
            ->assertSee(AssetVisibility::Interno->shortLabel());
    }
});
