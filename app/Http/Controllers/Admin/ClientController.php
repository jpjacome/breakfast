<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ClientStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientRequest;
use App\Models\Client;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $clients = Client::query()
            ->withCount('contextDocuments')
            ->when($request->string('q')->trim()->value(), function ($query, string $term) {
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', "%{$term}%")
                        ->orWhere('industry', 'like', "%{$term}%")
                        ->orWhere('contact_email', 'like', "%{$term}%");
                });
            })
            ->when($request->string('status')->value(), fn ($query, $status) => $query->where('status', $status))
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

    public function create(): View
    {
        return view('admin.clients.create', [
            'statuses' => ClientStatus::cases(),
        ]);
    }

    public function store(StoreClientRequest $request): RedirectResponse
    {
        $client = Client::create([
            ...$request->validated(),
            'onboarded_at' => now(),
        ]);

        return redirect()
            ->route('admin.clients.show', $client)
            ->with('status', "Marca «{$client->name}» creada. Ya puedes subirle contexto.");
    }

    public function show(Client $client): View
    {
        $client->load([
            'contextDocuments.uploader',
            'users',
        ]);

        return view('admin.clients.show', [
            'client' => $client,
        ]);
    }
}
