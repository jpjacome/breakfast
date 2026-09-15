<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\AssetVisibility;
use App\Enums\DeliverableItem;
use App\Enums\PortalSection;
use App\Models\BrandAsset;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

/**
 * Images and video inside an entregable — section 5 of the beta review.
 *
 * Twelve of the 48 are text AND picture by nature: a Kapferer prism, a look and
 * feel, a service design map. Printing https://… where the diagram belongs is
 * not the entregable.
 *
 * ⚠️ WHAT THESE TESTS ARE REALLY GUARDING is that showing a file inline did not
 * quietly become a way around who may see it. Rendering an <img> is the same
 * request as clicking the link, and the same check runs on it — so the last two
 * tests here matter more than the first three.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->client = Client::factory()->create(['name' => 'Cafetería Norte']);

    $this->owner = User::factory()->clientOwner()->create([
        'client_id' => $this->client->id,
        'permissions' => [
            PortalSection::Estrategia->value => AccessLevel::Read->value,
            PortalSection::BrandAssets->value => AccessLevel::Read->value,
        ],
    ]);
});

function anAssetNamed(Client $client, string $name, string $mime, AssetVisibility $visibility = AssetVisibility::Compartido): BrandAsset
{
    return BrandAsset::factory()->create([
        'client_id' => $client->id,
        'title' => pathinfo($name, PATHINFO_FILENAME),
        'original_name' => $name,
        'mime' => $mime,
        'visibility' => $visibility,
    ]);
}

/** Write one entregable whose content is a link to $asset. */
function entregableLinking(Client $client, BrandAsset $asset, DeliverableItem $item = DeliverableItem::Relato): void
{
    $client->deliverables->update([
        $item->value => 'Así se ve: '.route('assets.download', $asset),
    ]);
}

/* --- what gets rendered -------------------------------------------------- */

test('an image link becomes an image', function () {
    $asset = anAssetNamed($this->client, 'prisma.png', 'image/png');
    entregableLinking($this->client, $asset);

    actingAs($this->owner)
        ->get(route('portal.estrategia'))
        ->assertOk()
        ->assertSee('<img src="'.route('assets.download', $asset).'"', escape: false)
        ->assertSee('entregable-media', escape: false);
});

test('a playable video link becomes a player', function () {
    $asset = anAssetNamed($this->client, 'manifiesto.mp4', 'video/mp4');
    entregableLinking($this->client, $asset);

    actingAs($this->owner)
        ->get(route('portal.estrategia'))
        ->assertOk()
        ->assertSee('<video src="'.route('assets.download', $asset).'"', escape: false)
        ->assertSee('controls', escape: false);
});

test('a .mov keeps its link rather than becoming a player nothing can play', function () {
    // A browser will not play it, so a <video> tag would hand the client a
    // black rectangle where a reference should be — worse than the link.
    $asset = anAssetNamed($this->client, 'making-of.mov', 'video/quicktime');
    entregableLinking($this->client, $asset);

    actingAs($this->owner)
        ->get(route('portal.estrategia'))
        ->assertOk()
        ->assertDontSee('<video', escape: false)
        ->assertSee('<a href="'.route('assets.download', $asset).'"', escape: false);
});

test('a PDF and an outside link are still links', function () {
    $pdf = anAssetNamed($this->client, 'brandbook.pdf', 'application/pdf');

    $this->client->deliverables->update([
        DeliverableItem::Relato->value => route('assets.download', $pdf)."\n\nhttps://ejemplo.com/foto.png",
    ]);

    actingAs($this->owner)
        ->get(route('portal.estrategia'))
        ->assertOk()
        // Scoped to the asset's own URL: the page has a logo of its own, and
        // asserting on a bare "<img" would be testing the sidebar.
        ->assertDontSee('<img src="'.route('assets.download', $pdf).'"', escape: false)
        // ⚠️ Someone else's URL ending in .png is NOT rendered as an image.
        // fromUrl() checks the host, so nothing off this site can be embedded
        // into a brand's page by writing a link into an entregable.
        ->assertSee('<a href="https://ejemplo.com/foto.png"', escape: false);
});

/* --- and the part that actually matters ---------------------------------- */

test('an internal image linked from an entregable still 404s for the client', function () {
    $asset = anAssetNamed($this->client, 'contrato-escaneado.png', 'image/png', AssetVisibility::Interno);
    entregableLinking($this->client, $asset);

    // The tag renders — the page cannot know who will look at it, and the
    // entregable's text is what Breakfast wrote either way…
    actingAs($this->owner)
        ->get(route('portal.estrategia'))
        ->assertOk()
        ->assertSee('<img', escape: false);

    // …but the request behind it is refused exactly as the link always was.
    // This is the whole safety argument for rendering media inline: it is the
    // same request with a different tag around it.
    actingAs($this->owner)
        ->get(route('assets.download', $asset))
        ->assertNotFound();
});

test('another brand image cannot be embedded into this brand page', function () {
    $other = Client::factory()->create(['name' => 'Otra Marca']);
    $theirs = anAssetNamed($other, 'su-logo.png', 'image/png');

    entregableLinking($this->client, $theirs);

    actingAs($this->owner)
        ->get(route('portal.estrategia'))
        ->assertOk();

    // canReachBrandAsset() compares client_id, so the src resolves to a 404.
    actingAs($this->owner)
        ->get(route('assets.download', $theirs))
        ->assertNotFound();
});

/* --- the resolver -------------------------------------------------------- */

test('fromUrl only answers for our own asset urls', function () {
    $asset = anAssetNamed($this->client, 'logo.png', 'image/png');

    expect(BrandAsset::fromUrl(route('assets.download', $asset))?->id)->toBe($asset->id)
        ->and(BrandAsset::fromUrl('https://otro-sitio.com/archivos/'.$asset->id))->toBeNull()
        ->and(BrandAsset::fromUrl(config('app.url').'/archivos/999999'))->toBeNull()
        ->and(BrandAsset::fromUrl(config('app.url').'/portal/estrategia'))->toBeNull();
});
