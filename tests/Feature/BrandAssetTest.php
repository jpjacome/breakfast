<?php

use App\Enums\AccessLevel;
use App\Enums\PortalSection;
use App\Enums\UserRole;
use App\Models\BrandAsset;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake('local');

    $this->admin = User::factory()->admin()->create();
    $this->client = Client::factory()->create(['name' => 'Cafetería Norte', 'slug' => 'cafeteria-norte']);
    $this->owner = User::factory()->clientOwner()->create([
        'client_id' => $this->client->id,
        'permissions' => [PortalSection::BrandAssets->value => AccessLevel::Read->value],
    ]);
});

/*
|--------------------------------------------------------------------------
| Uploading
|--------------------------------------------------------------------------
*/

test('an admin uploads a file into the brand folder', function () {
    actingAs($this->admin)
        ->from(route('admin.clients.process.edit', $this->client))
        ->post(route('admin.clients.assets.store', $this->client), [
            'files' => [UploadedFile::fake()->image('logo.png')],
            'title' => 'Logotipo principal',
        ])
        ->assertRedirect(route('admin.clients.process.edit', $this->client));

    $asset = $this->client->brandAssets()->firstOrFail();

    expect($asset->title)->toBe('Logotipo principal')
        ->and($asset->original_name)->toBe('logo.png')
        // Keyed by slug so the folder is legible over FTP, which is how this
        // host is actually administered.
        ->and($asset->path)->toStartWith('marcas/cafeteria-norte/assets/');

    Storage::disk('local')->assertExists($asset->path);
});

test('several files upload at once and each keeps its own name', function () {
    actingAs($this->admin)->post(route('admin.clients.assets.store', $this->client), [
        'files' => [
            UploadedFile::fake()->image('logo-negativo.png'),
            UploadedFile::fake()->create('brandbook.pdf', 100, 'application/pdf'),
        ],
        // A single typed title would label them both the same, so with several
        // files it is ignored.
        'title' => 'No debe aplicarse',
    ]);

    expect($this->client->brandAssets()->pluck('title')->sort()->values()->all())
        ->toBe(['brandbook', 'logo-negativo']);
});

test('two files with the same name do not overwrite each other', function () {
    foreach (range(1, 2) as $_) {
        actingAs($this->admin)->post(route('admin.clients.assets.store', $this->client), [
            'files' => [UploadedFile::fake()->image('logo.png')],
        ]);
    }

    $paths = $this->client->brandAssets()->pluck('path');

    expect($paths)->toHaveCount(2)
        ->and($paths->unique())->toHaveCount(2);
});

test('an upload with no file is refused', function () {
    actingAs($this->admin)
        ->post(route('admin.clients.assets.store', $this->client), [])
        ->assertSessionHasErrors('files');
});

test('a client user cannot upload', function () {
    actingAs($this->owner)
        ->post(route('admin.clients.assets.store', $this->client), [
            'files' => [UploadedFile::fake()->image('mio.png')],
        ])
        ->assertNotFound();

    expect(BrandAsset::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Who may download
|--------------------------------------------------------------------------
*/

test('the brand can download its own file', function () {
    $asset = uploadAsset($this->admin, $this->client);

    actingAs($this->owner)->get($asset->url())->assertOk();
});

test('another brand cannot, even with the exact url', function () {
    $asset = uploadAsset($this->admin, $this->client);

    $stranger = User::factory()->clientOwner()->create([
        'client_id' => Client::factory()->create()->id,
        'permissions' => [PortalSection::BrandAssets->value => AccessLevel::Read->value],
    ]);

    // This is the whole reason files live under storage/ and not public/:
    // a file in public/ is served by Apache before PHP ever runs.
    actingAs($stranger)->get($asset->url())->assertNotFound();
});

test('a member without Brand assets cannot, even in the right brand', function () {
    $asset = uploadAsset($this->admin, $this->client);

    $member = User::factory()->create([
        'role' => UserRole::ClienteMiembro,
        'client_id' => $this->client->id,
        'permissions' => [PortalSection::Estrategia->value => AccessLevel::Read->value],
    ]);

    actingAs($member)->get($asset->url())->assertNotFound();
});

test('a guest gets sent to login, not to the file', function () {
    $asset = uploadAsset($this->admin, $this->client);

    // uploadAsset() acts as the admin, and actingAs outlives the call — so
    // being a guest has to be asked for explicitly.
    auth()->logout();

    $this->get($asset->url())->assertRedirect(route('login'));
});

test('equipo can only reach files of brands they are on', function () {
    $asset = uploadAsset($this->admin, $this->client);
    $staff = User::factory()->equipo()->create();

    actingAs($staff)->get($asset->url())->assertNotFound();

    $this->client->staff()->attach($staff);

    actingAs($staff->fresh())->get($asset->url())->assertOk();
});

test('a row whose file is gone is a 404, not a 500', function () {
    $asset = uploadAsset($this->admin, $this->client);

    Storage::disk('local')->delete($asset->path);

    actingAs($this->owner)->get($asset->url())->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Deleting
|--------------------------------------------------------------------------
*/

test('deleting an asset takes the file with it', function () {
    $asset = uploadAsset($this->admin, $this->client);
    $path = $asset->path;

    actingAs($this->admin)
        ->delete(route('admin.clients.assets.destroy', [$this->client, $asset]))
        ->assertRedirect();

    expect(BrandAsset::count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

test('deleting an asset leaves the entregable link alone', function () {
    $asset = uploadAsset($this->admin, $this->client);
    $url = $asset->url();

    $this->client->deliverables->update(['identificativo_principal' => "El logo: {$url}"]);

    actingAs($this->admin)->delete(route('admin.clients.assets.destroy', [$this->client, $asset]));

    // Deliberate: silently editing 48 text columns to strip a URL would be a
    // worse surprise than a dead link somebody can see and fix.
    expect($this->client->deliverables->fresh()->identificativo_principal)->toContain($url);
});

/*
|--------------------------------------------------------------------------
| The client's screen
|--------------------------------------------------------------------------
*/

test('the files screen lists everything in the folder', function () {
    uploadAsset($this->admin, $this->client, 'logo.png');
    uploadAsset($this->admin, $this->client, 'nota-interna.pdf');

    // Everything, whether or not it belongs to an entregable: it is the
    // brand's folder, not a by-product of the board.
    actingAs($this->owner)
        ->get(route('portal.brand_assets'))
        ->assertOk()
        ->assertSee('logo')
        ->assertSee('nota-interna');
});

test('the files screen needs the section', function () {
    $member = User::factory()->create([
        'role' => UserRole::ClienteMiembro,
        'client_id' => $this->client->id,
        'permissions' => [],
    ]);

    actingAs($member)->get(route('portal.brand_assets'))->assertNotFound();
    actingAs($this->owner)->get(route('portal.brand_assets'))->assertOk();
});

test('an empty folder says so instead of rendering nothing', function () {
    actingAs($this->owner)
        ->get(route('portal.brand_assets'))
        ->assertOk()
        ->assertSee('Todavía no hay archivos');
});

/** Upload one file as $user and return the row. */
function uploadAsset(User $user, Client $client, string $name = 'logo.png'): BrandAsset
{
    actingAs($user)->post(route('admin.clients.assets.store', $client), [
        'files' => [UploadedFile::fake()->create($name, 10)],
    ]);

    return $client->brandAssets()->latest('id')->firstOrFail();
}

/* -------------------------------------------------------------------------
 | What a file looks like
 ------------------------------------------------------------------------- */

test('a file is iconed by its extension, not its mime', function () {
    // Design sources upload as application/octet-stream, so the mime would put
    // the same blank sheet on the three files a brand cares most about.
    $cases = [
        'manual.pdf' => 'file-type-pdf',
        'logo.ai' => 'file-ai',
        'logo.svg' => 'file-type-svg',
        'portada.psd' => 'palette',
        'pack.zip' => 'file-zip',
        'Inter.woff2' => 'typography',
        'jingle.mp3' => 'file-music',
        'reel.mp4' => 'movie',
        'presupuesto.xlsx' => 'file-type-xls',
        'notas.md' => 'file-text',
        'algo.desconocido' => 'file',
    ];

    foreach ($cases as $filename => $icon) {
        $asset = new BrandAsset(['original_name' => $filename, 'mime' => 'application/octet-stream']);

        expect($asset->icon())->toBe($icon, "«{$filename}» debería usar {$icon}");
    }
});

test('the client sees the icon and the extension, not one or the other', function () {
    uploadAsset($this->admin, $this->client, 'manual-de-marca.pdf');

    // The icon says what kind of thing it is; the extension says exactly which.
    // "AI" alone is only obvious to whoever made the file.
    actingAs($this->owner)
        ->get(route('portal.brand_assets'))
        ->assertOk()
        ->assertSee('icon-tabler-file-type-pdf', escape: false)
        ->assertSee('PDF');
});
