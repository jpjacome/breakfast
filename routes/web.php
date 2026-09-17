<?php

use App\Enums\PortalSection;
use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AccountTwoFactorController;
use App\Http\Controllers\Admin\AdminAssistantController;
use App\Http\Controllers\Admin\BrandAssetController as AdminBrandAssetController;
use App\Http\Controllers\Admin\BrandOnboardingController;
use App\Http\Controllers\Admin\ClientBrandEggController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\ClientDraftController;
use App\Http\Controllers\Admin\ClientProcessController;
use App\Http\Controllers\Admin\ClientUserController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FileManagerController;
use App\Http\Controllers\Admin\MeetingController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\BrandAssetController;
use App\Http\Controllers\BrandEggMapController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\Portal\AssistantController as PortalAssistantController;
use App\Http\Controllers\Portal\BrandAssetController as PortalBrandAssetController;
use App\Http\Controllers\Portal\BrandController as PortalBrandController;
use App\Http\Controllers\Portal\BrandEggController as PortalBrandEggController;
use App\Http\Controllers\Portal\BrandSwitchController;
use App\Http\Controllers\Portal\ChecklistController as PortalChecklistController;
use App\Http\Controllers\Portal\InvitationController;
use App\Http\Controllers\Portal\MeetingController as PortalMeetingController;
use App\Http\Controllers\Portal\NotificationController;
use App\Http\Controllers\Portal\ProfileController as PortalProfileController;
use App\Http\Controllers\Portal\TeamController;
use App\Http\Controllers\UserFileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
| The marketing site (home, servicios, blog, podcast…) lands here later.
*/

Route::view('/', 'site.home-remake')->name('home');
Route::view('/nosotros', 'site.nosotros')->name('nosotros');
Route::view('/podcast', 'site.podcast')->name('podcast');
Route::view('/servicios', 'site.servicios')->name('servicios');
Route::view('/carta', 'site.carta')->name('carta');

Route::view('/contacto', 'site.contacto')->name('contacto');

Route::post('/contacto', [ContactController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('contacto.store');

// The Brand Egg's map: which entregable feeds which layer.
//
// ⚠️ PUBLIC BUT UNLISTED, and the two words do different jobs. Public: no
// auth, because nothing on the page belongs to a brand or a user — it is the
// shape of the model. Unlisted: the layout is passed :noindex, and NOTHING
// links here, so it is reachable only by someone given the URL. Breakfast's
// decision of 2026-09-14, so the mapping can be reviewed without an account.
//
// It publishes the 48 entregables, which is Breakfast's own method. Anything
// added to this screen is added in public — see the controller.
Route::get('/brand-egg', [BrandEggMapController::class, 'index'])->name('brand-egg');

Route::view('/legal/privacidad', 'legal.placeholder')->name('legal.privacy');
Route::view('/legal/terminos', 'legal.placeholder')->name('legal.terms');

/*
|--------------------------------------------------------------------------
| Brand assets — one route, both sides
|--------------------------------------------------------------------------
| The link pasted into an entregable has to work for the Breakfast team
| writing it and for the client reading it, so one URL answers both and
| User::canReachBrandAsset() decides which of them may have it.
|
| Outside both groups on purpose: it belongs to neither /admin nor /portal.
*/

Route::middleware('auth')
    ->get('archivos/{asset}', [BrandAssetController::class, 'download'])
    ->name('assets.download');

/*
 * A file somebody pasted at an assistant — their own, not a brand's.
 *
 * Separate from archivos/{asset} because it answers a different question about
 * a different table: that one asks canReachBrandAsset(), which is about a
 * brand's visibility rules; this one asks UserFile::isReachableBy(), which is
 * ownership plus Breakfast. Folding them into one route would mean one method
 * holding two access models, which is how a gate ends up answering the wrong
 * question (see the note on portal.reunion above).
 */
Route::middleware('auth')
    ->get('mis-archivos/{file}', [UserFileController::class, 'download'])
    ->name('user-files.download');

/*
|--------------------------------------------------------------------------
| Admin — Breakfast staff only
|--------------------------------------------------------------------------
*/

// 'covers-client' is on the whole group on purpose. It only acts on routes
// with a {client} in them, so every brand-scoped route added later is guarded
// the day it is written rather than when somebody remembers. Equipo members
// see only the brands they were put on; Admins reach all of them.
Route::middleware(['auth', 'breakfast', 'covers-client'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', DashboardController::class)->name('home');

        // The dashboard assistant — every brand at once, read from the
        // database. Throttled like every other AI surface: each turn is a paid
        // call and the send button is one keystroke away.
        Route::post('asistente', AdminAssistantController::class)
            ->middleware(['throttle:20,1', 'ai-turn'])
            ->name('assistant');

        // Design test bench for the assistant orb. Goes away with the orb's
        // own view once it is mounted in the assistant panel.
        Route::view('orbe', 'admin.orb')->name('orb');

        Route::get('clientes', [ClientController::class, 'index'])->name('clients.index');
        Route::get('clientes/nueva', [ClientController::class, 'create'])->name('clients.create');
        Route::post('clientes', [ClientController::class, 'store'])->name('clients.store');

        // /clientes/nueva. The autosave brings the draft row into being on the
        // first write; the assistant is the same one as on the process screen,
        // pointed at that draft.
        Route::post('clientes/nueva/guardar', [ClientDraftController::class, 'save'])
            ->name('clients.draft.save');
        Route::post('clientes/nueva/asistente', [BrandOnboardingController::class, 'draft'])
            ->middleware(['throttle:10,1', 'ai-turn'])
            ->name('clients.draft.assistant');

        // Restore and purge take a raw slug, not a bound {client}: the row is
        // soft-deleted, so route-model binding would 404 on it.
        Route::post('clientes/papelera/{slug}/restaurar', [ClientController::class, 'restore'])
            ->name('clients.restore');
        Route::delete('clientes/papelera/{slug}', [ClientController::class, 'purge'])
            ->name('clients.purge');

        Route::get('clientes/{client}', [ClientController::class, 'show'])->name('clients.show');
        // The brand's own details — name, industria, contacto, estado. The
        // slug is not among them and does not follow the name; see
        // UpdateClientRequest.
        Route::put('clientes/{client}', [ClientController::class, 'update'])->name('clients.update');
        Route::delete('clientes/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');

        // Discarding an unfinished brand. Its own route because it is a real
        // delete rather than an archive, and because any Breakfast user on the
        // brand may do it — see the controller.
        Route::delete('clientes/{client}/borrador', [ClientController::class, 'discardDraft'])
            ->name('clients.draft.discard');

        Route::post('clientes/{client}/usuarios', [ClientUserController::class, 'store'])
            ->name('clients.users.store');
        Route::put('clientes/{client}/usuarios/{user}', [ClientUserController::class, 'update'])
            ->name('clients.users.update');
        Route::post('clientes/{client}/usuarios/{user}/reenviar', [ClientUserController::class, 'resend'])
            ->name('clients.users.resend');
        Route::delete('clientes/{client}/usuarios/{user}', [ClientUserController::class, 'destroy'])
            ->name('clients.users.destroy');

        // The process screen: the three steps and the 48 entregables.
        // See docs/entregables.md.
        Route::get('clientes/{client}/proceso', [ClientProcessController::class, 'edit'])
            ->name('clients.process.edit');
        Route::put('clientes/{client}/proceso', [ClientProcessController::class, 'update'])
            ->name('clients.process.update');
        Route::post('clientes/{client}/proceso/paso', [ClientProcessController::class, 'step'])
            ->name('clients.process.step');

        // The assistant beside the entregables board. Throttled because every
        // turn is a paid API call and the send button is one keystroke away —
        // and 'ai-turn' on top, because this is the route that reads brandbooks
        // and a reading holds a PHP worker long enough to starve the public
        // site. 10/min rather than 20: an upload costs far more than a chat
        // message, in money and in workers.
        Route::post('clientes/{client}/proceso/asistente', [BrandOnboardingController::class, 'store'])
            ->middleware(['throttle:10,1', 'ai-turn'])
            ->name('clients.process.assistant');

        // The Brand Egg — five synthesised layers above the 48 entregables.
        // See docs/brand-egg.md.
        Route::get('clientes/{client}/brand-egg', [ClientBrandEggController::class, 'edit'])
            ->name('clients.egg.edit');

        // ⚠️ {layer} BINDS STRAIGHT TO THE ENUM, so a segment that is not one
        // of the five 404s before the controller runs. Same fail-closed shape
        // as the rest of the app, and it means no match with a default branch
        // anywhere downstream.
        Route::put('clientes/{client}/brand-egg/{layer}', [ClientBrandEggController::class, 'update'])
            ->name('clients.egg.update');

        // ⚠️ BOTH GATES, AND THEY DO DIFFERENT JOBS (CLAUDE.md trap 5).
        // throttle counts requests per minute, because every layer is a paid
        // API call. 'ai-turn' bounds how many run AT ONCE, which is the thing
        // that takes the public site down: one click here can chain five calls
        // and hold a PHP worker for a hundred seconds, on a pool shared with
        // the marketing site. A rate limit cannot express that.
        Route::post('clientes/{client}/brand-egg/componer', [ClientBrandEggController::class, 'compose'])
            ->middleware(['throttle:10,1', 'ai-turn'])
            ->name('clients.egg.compose');

        Route::post('clientes/{client}/brand-egg/aprobar', [ClientBrandEggController::class, 'approve'])
            ->name('clients.egg.approve');

        // Layer 4's inventory: put a file in the Egg, or take it out. A toggle
        // rather than two routes, because that is what a click on a file is.
        Route::post('clientes/{client}/brand-egg/archivos/{asset}', [ClientBrandEggController::class, 'asset'])
            ->name('clients.egg.asset');

        // The meeting roster: every brand at once, plus the calendar. Its own
        // top-level screen because "what does the week look like" is a
        // question about Breakfast, not about any one brand.
        Route::get('reuniones', [MeetingController::class, 'index'])->name('meetings.index');
        Route::get('reuniones/nueva', [MeetingController::class, 'create'])->name('meetings.create');

        // Meetings. They live under the brand because that is what they belong
        // to, and 'covers-client' guards them for free by naming {client}.
        Route::post('clientes/{client}/reuniones', [MeetingController::class, 'store'])
            ->name('clients.meetings.store');
        Route::put('clientes/{client}/reuniones/{meeting}', [MeetingController::class, 'update'])
            ->name('clients.meetings.update');
        Route::post('clientes/{client}/reuniones/{meeting}/cancelar', [MeetingController::class, 'cancel'])
            ->name('clients.meetings.cancel');
        Route::delete('clientes/{client}/reuniones/{meeting}', [MeetingController::class, 'destroy'])
            ->name('clients.meetings.destroy');

        // The file manager: one folder per brand, opened by slug. The upload
        // and delete routes below are the same ones the process screen posts
        // to — one folder, one way to write to it.
        Route::get('archivos', [FileManagerController::class, 'index'])
            ->name('files.index');

        // ⚠️ BEFORE the {client} route below, or route-model binding claims the
        // word "sin-marca" and tries to find a brand by that slug. Files
        // attached to Brandy with no brand chosen live here.
        Route::get('archivos/sin-marca', [FileManagerController::class, 'unfiled'])
            ->name('files.unfiled');

        Route::get('archivos/{client}', [FileManagerController::class, 'show'])
            ->name('files.show');

        // The brand's folder. Only Breakfast writes to it.
        Route::post('clientes/{client}/archivos', [AdminBrandAssetController::class, 'store'])
            ->name('clients.assets.store');
        Route::delete('clientes/{client}/archivos/{asset}', [AdminBrandAssetController::class, 'destroy'])
            ->name('clients.assets.destroy');
        // Flip a file between shared and internal. Exists so a wrong choice on
        // the upload form is one click to fix rather than a delete — which
        // would also break any entregable linking the file.
        Route::patch('clientes/{client}/archivos/{asset}', [AdminBrandAssetController::class, 'visibility'])
            ->name('clients.assets.visibility');

        // What a file IS, and what the machine said it looks like. Ordinary
        // admin writes answering with back(), so they work from the file
        // manager and the process screen alike — and they are what the
        // right-click menu will call once that screen exists.
        Route::patch('clientes/{client}/archivos/{asset}/tipo', [AdminBrandAssetController::class, 'type'])
            ->name('clients.assets.type');
        Route::patch('clientes/{client}/archivos/{asset}/descripcion', [AdminBrandAssetController::class, 'reading'])
            ->name('clients.assets.reading');

        // Your own account. Name and password post to Fortify's endpoints
        // straight from the form, so only the two-factor writes are ours.
        Route::get('cuenta', AccountController::class)->name('account');
        Route::post('cuenta/dos-pasos', [AccountTwoFactorController::class, 'store'])
            ->name('account.two-factor.enable');
        Route::post('cuenta/dos-pasos/confirmar', [AccountTwoFactorController::class, 'confirm'])
            ->name('account.two-factor.confirm');
        Route::delete('cuenta/dos-pasos', [AccountTwoFactorController::class, 'destroy'])
            ->name('account.two-factor.disable');
        Route::post('cuenta/dos-pasos/codigos', [AccountTwoFactorController::class, 'recoveryCodes'])
            ->name('account.two-factor.recovery-codes');

        // The Breakfast team itself. Everyone on staff sees the roster; the
        // write routes check for Admin — see StaffController.
        Route::get('equipo', [StaffController::class, 'index'])->name('staff.index');
        Route::post('equipo', [StaffController::class, 'store'])->name('staff.store');
        Route::put('equipo/{user}', [StaffController::class, 'update'])->name('staff.update');
        Route::post('equipo/{user}/reenviar', [StaffController::class, 'resend'])->name('staff.resend');
        Route::delete('equipo/{user}', [StaffController::class, 'destroy'])->name('staff.destroy');
    });

/*
|--------------------------------------------------------------------------
| Portal — client users
|--------------------------------------------------------------------------
| Every section is gated by 'section:<slug>', which reads App\Enums\PortalSection
| through User::accessTo(). Hiding a link in the sidebar is presentation; this
| is what actually stops someone who types the URL.
|
| The section pages are stubs for now — what is real is the access model around
| them. Replace a view() with a controller as each one gets built; the gate
| stays as it is.
*/

Route::middleware(['auth'])->prefix('portal')->name('portal.')->group(function () {

    Route::view('/', 'portal.home')->name('home');

    /*
     * Answering an invitation to a brand — queued item B.
     *
     * ⚠️ NO `section:` GATE, because the person is by definition not in the
     * brand yet. Being signed in as the invited ADDRESS and holding the token
     * is the authorisation, and InvitationController checks both on every one
     * of these — an unknown token, somebody else's token and an already
     * answered one all 404 without saying which.
     */
    Route::get('/invitaciones/{token}', [InvitationController::class, 'show'])
        ->name('invitaciones.show');
    Route::post('/invitaciones/{token}/aceptar', [InvitationController::class, 'accept'])
        ->name('invitaciones.accept');
    Route::post('/invitaciones/{token}/rechazar', [InvitationController::class, 'decline'])
        ->name('invitaciones.decline');

    // The brand's own assistant, on the client dashboard — the same panel the
    // Breakfast side has. Throttled like every other AI surface: each turn is a
    // paid call and the send button is one keystroke away.
    //
    // No section gate: it answers from the brand's own entregables, which is
    // what every person in the brand is there to read. The brand it answers
    // about comes from the user, never from the request.
    Route::post('/asistente', PortalAssistantController::class)
        ->middleware(['throttle:20,1', 'ai-turn'])
        ->name('assistant');

    // Everybody has an inbox: what lands in it was already filtered by who
    // could see the thing it is about, so there is no section gate here.
    Route::post('/avisos/leidos', [NotificationController::class, 'read'])
        ->name('notifications.read');

    // Changing which of your own brands the portal is showing — ACC-02.
    //
    // No section gate: switching is not access to anything. What you may open
    // once you are there is decided per brand by 'section:<slug>' exactly as
    // before, and ActiveBrand::set() refuses a brand that is not yours.
    Route::post('/marca/{client}', BrandSwitchController::class)
        ->name('brand.switch');

    // All read-only by design — Breakfast writes, the brand reads. See the
    // four controllers.
    Route::get('/estrategia', [PortalBrandController::class, 'index'])
        ->middleware('section:estrategia')
        ->name('estrategia');

    /*
     * ⚠️ THE ONLY WRITE ON THIS SIDE OF THE PORTAL BESIDES PERFIL — SEG-05.
     *
     * The client ticking items off their implementation checklist. Gated on
     * READ of Estrategia rather than write, because there is no client write
     * level and never will be (User::grantCeiling caps the grantable sections
     * at Read): being able to open the page IS the permission, and what this
     * writes is the client's own answer, not the brand's content.
     *
     * See ChecklistController for how narrow it is kept.
     */
    Route::post('/estrategia/checklist', PortalChecklistController::class)
        ->middleware('section:estrategia')
        ->name('estrategia.checklist');

    /*
     * The brand's own Brand Egg. Read-only, gated on READ of Estrategia —
     * it is that section's content, seen whole rather than entregable by
     * entregable — and it 404s while the Egg is unapproved.
     *
     * ⚠️ THAT 404 IS THE ONE PLACE APPROVAL GATES ANYTHING in this app.
     * Everywhere else the state changes what something says. Here it decides
     * whether the page exists, because a member should not learn there is a
     * draft of their brand's essence they are not being shown. See
     * Portal\BrandEggController.
     */
    Route::get('/estrategia/brand-egg', [PortalBrandEggController::class, 'show'])
        ->middleware('section:estrategia')
        ->name('estrategia.egg');

    Route::get('/reuniones', [PortalMeetingController::class, 'index'])
        ->middleware('section:reuniones')
        ->name('reuniones');

    /*
     * Where a meeting notification lands — item 11. Checks access, switches
     * the active brand, and redirects to the list with a fragment.
     *
     * ⚠️ NO section:reuniones HERE, AND THE ABSENCE IS DELIBERATE. That
     * middleware asks about the ACTIVE brand, and this route's job is to CHANGE
     * the active brand — so it would answer about whichever brand somebody
     * happened to be looking at rather than the one they are being sent to.
     * The check moved into MeetingController::show(), where it is asked about
     * the meeting's own brand. Same reasoning as canReachBrandAsset()
     * (CLAUDE.md §5).
     */
    Route::get('/reuniones/{meeting}', [PortalMeetingController::class, 'show'])
        ->name('reunion');

    Route::get('/brand-assets', [PortalBrandAssetController::class, 'index'])
        ->middleware('section:brand-assets')
        ->name('brand_assets');

    // The four routes above ARE the grantable sections — there is no loop
    // filling in the rest any more, because there is no rest. The five that
    // used to be stubbed here were deleted from PortalSection; see its
    // docblock. A new grantable section is a controller written by hand, not a
    // case that quietly becomes a placeholder page.

    // --- owner-only ---------------------------------------------------------
    // /suscripcion lived here as a stub until billing is built; it comes back
    // with that work, planned in docs/suscripciones.md.
    Route::middleware('section:equipo')->group(function () {
        Route::get('/equipo', [TeamController::class, 'index'])->name('equipo');
        Route::post('/equipo', [TeamController::class, 'store'])->name('equipo.store');
        Route::put('/equipo/{user}', [TeamController::class, 'update'])->name('equipo.update');
        Route::delete('/equipo/{user}', [TeamController::class, 'destroy'])->name('equipo.destroy');
    });

    // --- always-on ----------------------------------------------------------
    // Perfil is the one page on this side with a write path: your own name and
    // your own password. Both forms post to Fortify's endpoints, not to ours —
    // see Portal\ProfileController.
    Route::get('/perfil', [PortalProfileController::class, 'index'])
        ->middleware('section:perfil')
        ->name('perfil');

});
