<?php

use App\Enums\AssetVisibility;
use App\Enums\PortalSection;
use App\Models\BrandAsset;
use App\Models\Client;
use App\Models\User;
use App\Services\TurnAttachments;

/*
|--------------------------------------------------------------------------
| Item 4 — showing what a turn carried
|--------------------------------------------------------------------------
| A person attached a screenshot, Brandy answered about it, and the picture
| vanished: the transcript rendered the body and nothing else. These pin what
| each attachment becomes, and — the part that matters — what it does NOT
| become when the file is gone or not theirs to see.
*/

beforeEach(function () {
    $this->client = Client::factory()->create();
    $this->admin = User::factory()->admin()->create();
});

it('shows an image as an image and a video as a video', function () {
    $image = BrandAsset::factory()->create([
        'client_id' => $this->client->id,
        'original_name' => 'captura.png',
        'mime' => 'image/png',
    ]);

    $video = BrandAsset::factory()->create([
        'client_id' => $this->client->id,
        'original_name' => 'spot.mp4',
        'mime' => 'video/mp4',
    ]);

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
    $mov = BrandAsset::factory()->create([
        'client_id' => $this->client->id,
        'original_name' => 'camara.mov',
        'mime' => 'video/quicktime',
    ]);

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

it('never shows a file the viewer could not open', function () {
    // Fails closed, and it matters: an attachment row is a listing, so printing
    // one the viewer cannot download would tell them the file exists. Same
    // split as scopeSharedWithClient() versus the gate.
    $internal = BrandAsset::factory()->create([
        'client_id' => $this->client->id,
        'visibility' => AssetVisibility::Interno,
        'mime' => 'image/png',
        'original_name' => 'contrato.png',
    ]);

    $member = User::factory()
        ->clientMember($this->client, [PortalSection::BrandAssets->value => 'read'])
        ->create();

    $items = app(TurnAttachments::class)->for(['contrato.png'], [$internal->id], $member);

    expect($items->first()['kind'])->toBe('name')
        ->and($items->first()['asset'])->toBeNull();

    // The same call for somebody who may open it does show it.
    expect(app(TurnAttachments::class)->for(['contrato.png'], [$internal->id], $this->admin)
        ->first()['kind'])->toBe('image');
});

it('keeps names lined up when a file has been deleted', function () {
    $second = BrandAsset::factory()->create([
        'client_id' => $this->client->id,
        'mime' => 'image/png',
        'original_name' => 'dos.png',
    ]);

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
    $asset = BrandAsset::factory()->create([
        'client_id' => $this->client->id,
        'mime' => 'image/png',
        'original_name' => 'brandbook-p4.png',
    ]);

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
        ->assertSee(route('assets.download', $asset), escape: false)
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
