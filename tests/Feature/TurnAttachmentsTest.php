<?php

use App\Models\Client;
use App\Models\User;
use App\Models\UserFile;
use App\Services\TurnAttachments;

/*
|--------------------------------------------------------------------------
| Item 4 — showing what a turn carried
|--------------------------------------------------------------------------
| A person attached a screenshot, Brandy answered about it, and the picture
| vanished: the transcript rendered the body and nothing else. These pin what
| each attachment becomes, and — the part that matters — what it does NOT
| become when the file is gone or not theirs to see.
|
| ⚠️ THE FILES ARE `user_files` SINCE 2026-09-17, not brand_assets. What
| somebody pastes at an assistant is theirs; what a BRAND's assets are is
| answered by the Egg's inventory alone. So the access question changed with
| the table: it used to be the brand's interno/compartido rules, and it is now
| ownership plus Breakfast.
*/

beforeEach(function () {
    $this->client = Client::factory()->create();
    $this->admin = User::factory()->admin()->create();
});

/** A file in somebody's own folder, the way a pasted one lands. */
function pastedBy(User $owner, string $name, ?string $mime = null): UserFile
{
    return UserFile::create([
        'user_id' => $owner->getKey(),
        'title' => pathinfo($name, PATHINFO_FILENAME),
        'disk' => 'local',
        'path' => 'usuarios/'.$owner->getKey().'/'.$name,
        'original_name' => $name,
        'mime' => $mime,
        'size_bytes' => 1024,
    ]);
}

it('shows an image as an image and a video as a video', function () {
    $image = pastedBy($this->admin, 'captura.png', 'image/png');
    $video = pastedBy($this->admin, 'spot.mp4', 'video/mp4');

    $items = app(TurnAttachments::class)->for(
        ['captura.png', 'spot.mp4'],
        [$image->id, $video->id],
        $this->admin,
    );

    expect($items->pluck('kind')->all())->toBe(['image', 'video']);
});

it('calls a video a plain file when no browser can play it', function () {
    // ⚠️ .mov uploads with a video mime and plays in nothing. A preview box
    // showing a black rectangle is worse than the filename it replaced.
    $mov = pastedBy($this->admin, 'camara.mov', 'video/quicktime');

    $items = app(TurnAttachments::class)->for(['camara.mov'], [$mov->id], $this->admin);

    expect($items->first()['kind'])->toBe('file');
});

it('falls back to the name for a turn from before the files were kept', function () {
    // Every turn before brief point 3 has names and no files: the bytes went to
    // the provider and were dropped. That is a state, not an error, and must
    // not render as a broken image.
    $items = app(TurnAttachments::class)->for(['vieja.png'], null, $this->admin);

    expect($items)->toHaveCount(1)
        ->and($items->first()['kind'])->toBe('name')
        ->and($items->first()['asset'])->toBeNull()
        ->and($items->first()['name'])->toBe('vieja.png');
});

it("never shows a file from somebody else's folder", function () {
    /*
     * Fails closed, and it matters: an attachment row is a listing, so printing
     * one the viewer cannot download would tell them the file exists.
     *
     * ⚠️ A CLIENT NEVER SEES ANOTHER PERSON'S FOLDER, not even a brand owner
     * looking at their own team. Being able to invite somebody is not being
     * able to read their working material.
     */
    $mine = pastedBy($this->admin, 'contrato.png', 'image/png');

    $somebodyElse = User::factory()->create(['role' => 'cliente_miembro']);

    $items = app(TurnAttachments::class)->for(['contrato.png'], [$mine->id], $somebodyElse);

    expect($items->first()['kind'])->toBe('name')
        ->and($items->first()['asset'])->toBeNull();

    // Its owner sees it, and so does Breakfast.
    expect(app(TurnAttachments::class)->for(['contrato.png'], [$mine->id], $this->admin)
        ->first()['kind'])->toBe('image');
});

it('lets Breakfast see what a client pasted at them', function () {
    // The reason the files are kept at all: a client shows you something and
    // you still have it tomorrow.
    $client = User::factory()->create(['role' => 'cliente_owner']);
    $theirs = pastedBy($client, 'pantalla-rota.png', 'image/png');

    expect(app(TurnAttachments::class)->for(['pantalla-rota.png'], [$theirs->id], $this->admin)
        ->first()['kind'])->toBe('image');
});

it('keeps names lined up when a file has been deleted', function () {
    $second = pastedBy($this->admin, 'dos.png', 'image/png');

    // The first asset is gone from the folder. Dropping it would shift 'dos.png'
    // onto the first name and label the wrong picture.
    $items = app(TurnAttachments::class)->for(
        ['uno.png', 'dos.png'],
        [999999, $second->id],
        $this->admin,
    );

    expect($items->pluck('name')->all())->toBe(['uno.png', 'dos.png'])
        ->and($items->pluck('kind')->all())->toBe(['name', 'image']);
});

it('shows nothing at all when the turn carried nothing', function () {
    expect(app(TurnAttachments::class)->for(null, null, $this->admin))->toBeEmpty()
        ->and(app(TurnAttachments::class)->for([], [], $this->admin))->toBeEmpty();
});

it('renders the attachment in the process board transcript', function () {
    $asset = pastedBy($this->admin, 'brandbook-p4.png', 'image/png');

    $this->client->onboardingMessages()->create([
        'role' => 'user',
        'body' => '¿Qué ves acá?',
        'attachments' => ['brandbook-p4.png'],
        'attachment_ids' => [$asset->id],
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.clients.process.edit', $this->client))
        ->assertOk()
        // The <img> points at the gated route, never at a storage path.
        ->assertSee(route('user-files.download', $asset), escape: false)
        ->assertSee('data-lightbox', escape: false);
});

/* -------------------------------------------------------------------------
 | Who said this — the shared thread
 |
 | The process board's conversation belongs to the BRAND, not to a person:
 | everyone at Breakfast working it writes into the same one. So unlike the
 | other two assistants, "was this me or Andrea" is a real question.
 ------------------------------------------------------------------------- */

it('names the author of each turn on the shared thread', function () {
    $andrea = User::factory()->admin()->create(['name' => 'Andrea Rivas']);
    $bruno = User::factory()->admin()->create(['name' => 'Bruno Salas']);

    foreach ([[$andrea, '¿Qué dice el brandbook?'], [$bruno, '¿Y los colores?']] as [$who, $said]) {
        $this->client->onboardingMessages()->create([
            'role' => 'user',
            'user_id' => $who->id,
            'body' => $said,
        ]);
    }

    $this->client->onboardingMessages()->create([
        'role' => 'assistant',
        'body' => 'Dice esto y lo otro.',
    ]);

    $page = $this->actingAs($andrea)->get(route('admin.clients.process.edit', $this->client));

    $page->assertOk()
        ->assertSee('Andrea Rivas')
        ->assertSee('Bruno Salas')
        // Initials, the same mark the roster and the account screen use.
        ->assertSee('AR')
        ->assertSee('BS')
        // Brandy signs her own side, so the thread does not look one-sided.
        ->assertSee('Brandy');
});

it('keeps a turn whose author is gone', function () {
    // A deleted account, or a row from before the author was recorded. The turn
    // is still part of how this brand got written, so it keeps its place.
    $this->client->onboardingMessages()->create([
        'role' => 'user',
        'user_id' => null,
        'body' => 'Una pregunta vieja.',
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.clients.process.edit', $this->client))
        ->assertOk()
        ->assertSee('Una pregunta vieja.')
        ->assertSee('Alguien del equipo');
});
