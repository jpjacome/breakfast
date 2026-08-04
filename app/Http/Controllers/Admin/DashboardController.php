<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ClientStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ContextDocument;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'clientCount' => Client::count(),
            'activeCount' => Client::where('status', ClientStatus::Activo)->count(),
            'documentCount' => ContextDocument::count(),
            'pendingCount' => ContextDocument::whereNull('processed_at')->count(),
            'recentClients' => Client::withCount('contextDocuments')
                ->latest()
                ->take(5)
                ->get(),
            'recentDocuments' => ContextDocument::with('client')
                ->latest()
                ->take(6)
                ->get(),
        ]);
    }
}
