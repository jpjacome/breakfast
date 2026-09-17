<?php

use App\Enums\AssetSource;
use App\Enums\AssetVisibility;
use App\Enums\PortalSection;
use App\Models\BrandAsset;
use App\Models\Client;
use App\Models\User;
use App\Models\UserFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Keeping what is attached to Brandy — brief point 3
|--------------------------------------------------------------------------
| A brandbook uploaded to the assistant used to go to the provider and be
| dropped, so getting it anywhere meant uploading it AGAIN. These pin that it
| is kept, where it lands, and who may see it.
|
| ⚠️ WHERE IT LANDS CHANGED ON 2026-09-17. It used to be a brand_assets row on
| whatever brand the conversation was about, so a pasted screenshot sat in the
| brand's folder beside the logo. It is now a user_files row in the folder of
| whoever pasted it: what a BRAND's assets are is answered by the Egg's
| inventory alone, and a pasted image has never been through that decision.
*/

beforeEach(function () {
    Storage::fake('local');

    $this->admin = User::factory()->admin()->create();
    $this->client = Client::factory()->create(['name' => 'Alea', 'slug' => 'alea']);
});

/** One JSON answer from the provider, so a turn can complete. */
function fakeReading(): void
{
    Http::fake(['*' => Http::response([
        'choices' => [[
            'message' => ['content' => json_encode([
                'reply' => 'Leí el documento.',
                'digest' => 'La marca se llama Alea.',
                'proposals' => [],
                'questions' => [],
            ])],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10],
    ])]);
}

it('keeps a file attached to the brand assistant, without a second upload', function () {
    fakeReading();

    actingAs($this->admin)->post(route('admin.clients.process.assistant', $this->client), [
        'message' => 'Te dejo el toolkit.',
        'files' => [UploadedFile::fake()->create('toolkit.pdf', 120, 'application/pdf')],
    ])->assertOk();

    $file = UserFile::query()->sole();

    expect($file->user_id)->toBe($this->admin->id)
        ->and($file->original_name)->toBe('toolkit.pdf');

    // ⚠️ THE PERSON'S FOLDER, NOT THE BRAND'S, even though this turn is about
    // one brand and nothing else. The conversation's brand was never the
    // file's owner, and filing it there is what made a pasted screenshot look
    // like a brand asset.
    expect($file->path)->toStartWith('usuarios/'.$this->admin->id.'/');
    Storage::disk('local')->assertExists($file->path);

    // And the brand's own folder is untouched.
    expect(BrandAsset::query()->count())->toBe(0);
});

it('files an attachment with no brand chosen in the same place', function () {
    Http::fake(['*' => Http::response([
        'choices' => [['message' => ['content' => 'Lo veo.'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ])]);

    // The dashboard assistant's brand dropdown, left empty.
    actingAs($this->admin)->post(route('admin.assistant'), [
        'question' => '¿Qué ves acá?',
        'files' => [UploadedFile::fake()->image('captura.png')],
    ])->assertOk();

    /*
     * ⚠️ THE QUESTION THIS TEST USED TO ASK NO LONGER EXISTS. With files owned
     * by brands, "no brand chosen" needed an answer — a `_sin-marca` folder
     * beside the real ones, underscored so no slug could collide with it. Now
     * that a pasted file belongs to the person, the dropdown has nothing to do
     * with where it goes, and the empty case is not a case at all.
     */
    expect(UserFile::query()->sole()->path)
        ->toStartWith('usuarios/'.$this->admin->id.'/');
});

it("keeps one person's paste out of another person's folder", function () {
    fakeReading();

    $colleague = User::factory()->admin()->create();

    actingAs($colleague)->post(route('admin.clients.process.assistant', $this->client), [
        'message' => 'Mira esto.',
        'files' => [UploadedFile::fake()->image('captura.png')],
    ])->assertOk();

    $file = UserFile::query()->sole();

    expect($file->user_id)->toBe($colleague->id)
        ->and($file->isReachableBy($colleague))->toBeTrue()
        // Breakfast sees what was pasted at them — that is the point of keeping
        // it — and this colleague IS Breakfast.
        ->and($file->isReachableBy($this->admin))->toBeTrue();

    // A client of the brand does not, even the owner.
    $owner = User::factory()->clientOwner($this->client->id, [])->create();
    expect($file->isReachableBy($owner))->toBeFalse();

    actingAs($owner)->get(route('user-files.download', $file))->assertNotFound();
});

it('never shows an unfiled file to a client', function () {
    $asset = BrandAsset::factory()->create([
        'client_id' => null,
        'source' => AssetSource::Referencia,
        'visibility' => AssetVisibility::Interno,
    ]);

    $owner = User::factory()->clientOwner($this->client->id, [PortalSection::BrandAssets->value => 'read'])->create();

    // No brand means no client it could belong to. Breakfast's alone.
    expect($owner->canReachBrandAsset($asset))->toBeFalse()
        ->and($this->admin->canReachBrandAsset($asset))->toBeTrue();

    actingAs($owner)->get(route('assets.download', $asset))->assertNotFound();
});

it('keeps unfiled files out of every brand portal listing', function () {
    BrandAsset::factory()->create([
        'client_id' => null,
        'visibility' => AssetVisibility::Compartido,
    ]);

    // Even marked compartido — there is no brand for it to be shared WITH, and
    // scopeSharedWithClient() is what the portal list is built from.
    expect(BrandAsset::query()->sharedWithClient()->count())->toBe(0);
});

it('keeps the file even when the provider fails', function () {
    // The bytes are already on this server and the turn was already paid for.
    // Losing the file to a 502 would mean uploading it again, which is the
    // whole thing this removes.
    Http::fake(['*' => Http::response('nope', 500)]);

    actingAs($this->admin)->post(route('admin.clients.process.assistant', $this->client), [
        'message' => 'Te dejo el toolkit.',
        'files' => [UploadedFile::fake()->create('toolkit.pdf', 120, 'application/pdf')],
    ])->assertStatus(502);

    // In the PERSON'S folder since 2026-09-17, not the brand's: what
    // somebody pastes is theirs, and what a brand's assets are is answered
    // by the Egg's inventory alone.
    expect(UserFile::query()->count())->toBe(1);
});

/* -------------------------------------------------------------------------
 | The folder screen
 ------------------------------------------------------------------------- */

it('sorts a folder by name, size and date', function () {
    // ⚠️ DISTINCT created_at, AND the newest row is the SMALL one. Both matter.
    //
    // Client::brandAssets() is declared ->latest(), so a sort that is merely
    // appended sits behind created_at and never decides anything. Rows made in
    // the same second tie on it and fall through to the real sort, which is how
    // a broken sort passed this test once. Making the date disagree with every
    // other ordering is what gives the assertions below teeth.
    $big = BrandAsset::factory()->create([
        'client_id' => $this->client->id, 'title' => 'Alfa', 'size_bytes' => 900,
        'created_at' => now()->subDays(3),
    ]);
    $small = BrandAsset::factory()->create([
        'client_id' => $this->client->id, 'title' => 'Zeta', 'size_bytes' => 100,
        'created_at' => now(),
    ]);

    $byName = actingAs($this->admin)
        ->get(route('admin.files.show', $this->client).'?orden=nombre&dir=asc');

    $byName->assertOk();
    expect($byName->viewData('assets')->first()->is($big))->toBeTrue();

    $bySize = actingAs($this->admin)
        ->get(route('admin.files.show', $this->client).'?orden=peso&dir=desc');

    expect($bySize->viewData('assets')->first()->is($big))->toBeTrue();

    $bySizeAsc = actingAs($this->admin)
        ->get(route('admin.files.show', $this->client).'?orden=peso&dir=asc');

    expect($bySizeAsc->viewData('assets')->first()->is($small))->toBeTrue();

    // And the date still sorts by date: newest first by default, oldest first
    // when asked. If the relation's own ->latest() were still in charge, this
    // pair would agree with each other and prove nothing.
    $newest = actingAs($this->admin)->get(route('admin.files.show', $this->client));
    expect($newest->viewData('assets')->first()->is($small))->toBeTrue();

    $oldest = actingAs($this->admin)
        ->get(route('admin.files.show', $this->client).'?orden=fecha&dir=asc');
    expect($oldest->viewData('assets')->first()->is($big))->toBeTrue();
});

it('ignores a sort field it does not know', function () {
    BrandAsset::factory()->create(['client_id' => $this->client->id]);

    // The column comes from a fixed map, never from the query string.
    actingAs($this->admin)
        ->get(route('admin.files.show', $this->client).'?orden=size_bytes;drop&dir=whatever')
        ->assertOk();
});

it('shows the unfiled folder only when it has something in it', function () {
    actingAs($this->admin)->get(route('admin.files.index'))
        ->assertOk()
        ->assertDontSee('Sin marca');

    BrandAsset::factory()->create(['client_id' => null]);

    actingAs($this->admin)->get(route('admin.files.index'))
        ->assertOk()
        ->assertSee('Sin marca');
});

it('hides the unfiled folder from staff who do not cover every brand', function () {
    BrandAsset::factory()->create(['client_id' => null]);

    // An unattributed file could be about any brand, so showing it to somebody
    // who covers three of them could show them a fourth brand's material.
    $equipo = User::factory()->equipo()->create();
    $equipo->assignedClients()->attach($this->client);

    actingAs($equipo)->get(route('admin.files.index'))
        ->assertOk()
        ->assertDontSee('Sin marca');
});

it('opens the unfiled folder without a brand in the URL', function () {
    BrandAsset::factory()->create(['client_id' => null, 'title' => 'Captura suelta']);

    actingAs($this->admin)->get(route('admin.files.unfiled'))
        ->assertOk()
        ->assertSee('Captura suelta');
});
