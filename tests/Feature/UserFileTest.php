<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\User;
use App\Models\UserFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

/**
 * A person's own folder — queued item A.
 *
 * Every user has one, and it holds what they pasted at an assistant. It exists
 * because `brand_assets` was two unrelated things in one table: the brand's
 * files, which Breakfast files, and whatever anybody dropped into a chat. What
 * a brand's assets ARE is now answered in one place — the Egg's inventory —
 * and a pasted screenshot has never been through that decision.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->owner = User::factory()->create(['role' => 'cliente_owner']);
    $this->staff = User::factory()->admin()->create();
});

function fileFor(User $user, string $name = 'captura.png'): UserFile
{
    $path = UserFile::folderFor($user).'/'.UserFile::storedName($name);

    Storage::disk('local')->put($path, 'bytes');

    return UserFile::create([
        'user_id' => $user->getKey(),
        'title' => pathinfo($name, PATHINFO_FILENAME),
        'disk' => 'local',
        'path' => $path,
        'original_name' => $name,
        'mime' => 'image/png',
        'size_bytes' => 5,
    ]);
}

it('lets the owner open their own file', function () {
    actingAs($this->owner)
        ->get(route('user-files.download', fileFor($this->owner)))
        ->assertOk()
        ->assertHeader('content-disposition', 'inline; filename="captura.png"');
});

it('lets Breakfast open what a client pasted at them', function () {
    // The whole reason the files are kept: a client shows you something and you
    // still have it tomorrow.
    actingAs($this->staff)
        ->get(route('user-files.download', fileFor($this->owner)))
        ->assertOk();
});

it("404s on somebody else's file", function () {
    /*
     * ⚠️ NOT EVEN A BRAND OWNER LOOKING AT THEIR OWN TEAM. Being able to invite
     * somebody is not being able to read their working material — and an owner
     * who could would make pasting anything at Brandy a thing people think
     * twice about.
     *
     * 404 rather than 403, like every other gate here: they do not learn it
     * exists (CLAUDE.md §6).
     */
    $colleague = User::factory()->create(['role' => 'cliente_miembro']);

    actingAs($this->owner)
        ->get(route('user-files.download', fileFor($colleague)))
        ->assertNotFound();
});

it('404s for a guest', function () {
    $file = fileFor($this->owner);

    expect($file->isReachableBy(null))->toBeFalse();

    $this->get(route('user-files.download', $file))->assertRedirect(route('login'));
});

it('404s when the row outlived the file', function () {
    // Happens when a deploy overwrites storage/. An error page helps nobody.
    $file = fileFor($this->owner);
    Storage::disk('local')->delete($file->path);

    actingAs($this->owner)
        ->get(route('user-files.download', $file))
        ->assertNotFound();
});

it('keys the folder on the id, never on the name', function () {
    // ⚠️ A slug would move when somebody is renamed and an address is not a
    // safe path component; either would orphan every file already written.
    expect(UserFile::folderFor($this->owner))->toBe('usuarios/'.$this->owner->id)
        ->and(UserFile::folderFor($this->owner))->not->toContain($this->owner->email);
});

it('stores under a name that cannot collide or carry a path', function () {
    $stored = UserFile::storedName('../../etc/passwd.png');

    expect($stored)->toEndWith('.png')
        ->and($stored)->not->toContain('/')
        ->and($stored)->not->toContain('..')
        ->and($stored)->not->toBe(UserFile::storedName('../../etc/passwd.png'));
});

it('reads its own kind, the same way a brand asset does', function () {
    // Shared through DescribesAFile, so a screenshot cannot be an image in one
    // transcript and a paperclip in another.
    $file = fileFor($this->owner);

    expect($file->isImage())->toBeTrue()
        ->and($file->icon())->toBe('photo')
        ->and($file->extension())->toBe('PNG')
        ->and($file->humanSize())->toBe('5 B');
});

it('goes with the person, since the files were only ever theirs', function () {
    $file = fileFor($this->owner);

    $this->owner->delete();

    expect(UserFile::find($file->id))->toBeNull();
});

it('has nothing to do with a brand', function () {
    // ⚠️ No client_id, deliberately. A column for "the brand the conversation
    // was about" is exactly how brand_assets came to mean two things.
    expect(Schema::hasColumn('user_files', 'client_id'))->toBeFalse();

    // And a brand having files does not give it any of these.
    Client::factory()->create();
    expect(UserFile::query()->count())->toBe(0);
});
