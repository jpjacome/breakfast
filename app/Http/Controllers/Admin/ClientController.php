<?php

namespace App\Http\Controllers\Admin;

use App\Actions\DeleteClient;
use App\Actions\StartBrandDraft;
use App\Enums\ClientStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteClientRequest;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\BrandDeliverables;
use App\Models\Client;
use App\Services\Checklist;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $clients = Client::query()
            ->visibleTo($request->user())
            ->withCount('brandAssets')
            ->when($request->string('q')->trim()->value(), function ($query, string $term) {
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', "%{$term}%")
                        ->orWhere('industry', 'like', "%{$term}%")
                        ->orWhere('contact_email', 'like', "%{$term}%");
                });
            })
            // 'papelera' is not a ClientStatus — it is the soft-delete state,
            // which is a different axis entirely. Sharing the one filter box
            // is a presentation choice; the query below is what keeps them
            // from being confused for each other.
            ->when(
                $request->string('status')->value() === 'papelera',
                fn ($query) => $query->onlyTrashed(),
                fn ($query) => $query->when(
                    $request->string('status')->value(),
                    fn ($q, $status) => $q->where('status', $status),
                ),
            )
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.clients.index', [
            'clients' => $clients,
            'statuses' => ClientStatus::cases(),
            'q' => $request->string('q')->value(),
            'status' => $request->string('status')->value(),
        ]);
    }

    /**
     * The new-brand screen, resuming a draft when the URL names one.
     *
     * The page rewrites its own URL to ?borrador={slug} on the first save, so
     * a reload comes back to the same row instead of starting a second one.
     */
    public function create(Request $request): View
    {
        $draft = null;
        $slug = trim((string) $request->query('borrador'));

        if ($slug !== '') {
            $draft = Client::query()
                ->visibleTo($request->user())
                ->where('slug', $slug)
                ->where('status', ClientStatus::Borrador)
                ->first();
        }

        return view('admin.clients.create', [
            // Borrador is not offered: a brand enters that state by being
            // started and leaves it by being finished, never from a dropdown.
            'statuses' => ClientStatus::selectable(),
            'draft' => $draft,
            'deliverables' => $draft?->deliverablesOrNew() ?? new BrandDeliverables,
            'conversation' => $draft?->onboardingMessages ?? collect(),
        ]);
    }

    /**
     * Finish the brand.
     *
     * The row usually exists already — the assistant or the autosave created it
     * as a Borrador the moment there was something to keep. This flips it to a
     * real brand. A form submitted with nothing ever saved still works: the
     * draft is created here instead.
     */
    public function store(StoreClientRequest $request, StartBrandDraft $drafts): RedirectResponse
    {
        $client = DB::transaction(function () use ($request, $drafts): Client {
            $draft = $request->existingDraft();

            if ($draft === null) {
                // Nothing was ever autosaved. force, because the form in hand
                // is the content — see StartBrandDraft::resolve().
                $draft = $drafts->resolve($request->user(), null, $request->clientAttributes(), force: true);
            } else {
                $drafts->update($draft, $request->clientAttributes());
            }

            // Whatever the assistant put in the board on this screen is saved
            // by the same button, so nothing the person just watched land is
            // lost to a second step they did not know about.
            $draft->deliverables()->firstOrNew()->fill([
                ...$request->deliverables(),
                'updated_by' => $request->user()->id,
            ])->save();

            return $drafts->finish($draft, $request->chosenStatus());
        });

        return redirect()
            ->route('admin.clients.process.edit', $client)
            ->with('status', "Marca «{$client->name}» creada. Sigue con el proceso y los entregables.");
    }

    /**
     * Archive a brand. Recoverable, and the default everywhere.
     *
     * Its people lose the portal immediately — see User::accessTo(), which
     * fails closed when the brand behind a client user is gone.
     */
    public function destroy(DeleteClientRequest $request, Client $client, DeleteClient $deleter): RedirectResponse
    {
        $deleter->archive($client);

        return redirect()
            ->route('admin.clients.index')
            ->with('status', "«{$client->name}» archivada. Puedes recuperarla desde la papelera.");
    }

    /**
     * Throw an unfinished brand away.
     *
     * Drafts do not go to the papelera. An abandoned draft is clutter, and
     * archiving it would move the clutter rather than remove it — so this is a
     * real delete, and the screen says what is in it first.
     *
     * No typed name, unlike a live brand: most drafts are still called "Marca
     * sin nombre", and asking somebody to type that to confirm is theatre. Any
     * Breakfast user who reaches the brand may discard it, because the person
     * who started it is usually the person who wants it gone, and making them
     * find an Admin for a half-filled form is how the clutter accumulates.
     */
    public function discardDraft(Client $client, DeleteClient $deleter): RedirectResponse
    {
        abort_unless($client->status->isDraft(), 404);

        $name = $client->name;

        $deleter->purge($client);

        return redirect()
            ->route('admin.clients.index')
            ->with('status', "Borrador «{$name}» descartado.");
    }

    public function restore(Request $request, string $slug, DeleteClient $deleter): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $client = Client::onlyTrashed()->where('slug', $slug)->firstOrFail();

        $deleter->restore($client);

        return redirect()
            ->route('admin.clients.show', $client)
            ->with('status', "«{$client->name}» está de vuelta, con todo lo que tenía.");
    }

    /**
     * Destroy a brand for good.
     *
     * Only from the papelera, so nothing is ever one click from permanent —
     * archiving is always the first step, and this is a decision taken about
     * something already out of the way.
     */
    public function purge(DeleteClientRequest $request, string $slug, DeleteClient $deleter): RedirectResponse
    {
        $client = Client::onlyTrashed()->where('slug', $slug)->firstOrFail();

        // The request validates the typed name against route('client'), which
        // this route does not bind — the row is trashed and would 404. Checked
        // here instead, on the same rule.
        abort_unless(
            mb_strtolower(trim((string) $request->input('confirmation'))) === mb_strtolower(trim($client->name)),
            422,
        );

        $name = $client->name;

        $deleter->purge($client);

        return redirect()
            ->route('admin.clients.index', ['status' => 'papelera'])
            ->with('status', "«{$name}» y todo lo suyo se eliminaron para siempre.");
    }

    /**
     * Change a brand's own details.
     *
     * The brand page could show these and never edit them: name, industry,
     * contact and status were writable on the way in — /clientes/nueva and the
     * draft autosave — and read-only forever after, so a contact who changed
     * job could not be corrected without going to the database.
     *
     * ⚠️ The slug deliberately does not follow the name. See
     * UpdateClientRequest: it is the brand's address in the URLs people
     * bookmark and in storage/app/marcas/{slug}, and neither should move
     * because a label was corrected.
     */
    public function update(UpdateClientRequest $request, Client $client): RedirectResponse
    {
        $client->update($request->clientAttributes());

        return redirect()
            ->route('admin.clients.show', $client)
            ->with('status', 'Datos de la marca actualizados.');
    }

    public function show(Client $client): View
    {
        $client->load([
            'brandAssets.uploader',
            'users',
        ]);

        return view('admin.clients.show', [
            'client' => $client,
            // Newest first: the next meeting and the last one are both near
            // the top, which is what somebody opening a brand is looking for.
            'meetings' => $client->meetings()->with('reminders')->orderByDesc('scheduled_at')->get(),
            // How many people would actually hear about a new meeting. Shown
            // so nobody ticks "avisar" for an audience of nobody and assumes
            // the client was told.
            'audience' => $client->meetingAudience()->count(),
            // SEG-05. Read-only here: the text is Breakfast's, the ticks are
            // the brand's, and this side only watches them.
            'checklist' => Checklist::for($client->deliverablesOrNew()),
            'ticks' => $client->checklistTicks()->with('checkedBy')->get()->keyBy('item_key'),
        ]);
    }
}
