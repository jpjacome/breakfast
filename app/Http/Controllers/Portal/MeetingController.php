<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\PortalSection;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Reuniones, from the client's side. Read-only, always.
 *
 * There is no write path here at all — not hidden behind a permission check,
 * absent. Meetings are scheduled by Breakfast, and a brand's own team having
 * "Ver y editar" on Reuniones means they can read the notes, not move the
 * date. The route is still gated by section:reuniones, which is what stops
 * somebody typing the URL.
 */
class MeetingController extends Controller
{
    public function index(Request $request): View
    {
        $client = $request->user()->activeBrand();

        return view('portal.reuniones', [
            'section' => PortalSection::Reuniones,
            'upcoming' => $client->meetings()->upcoming()->get(),
            'past' => $client->meetings()->past()->get(),
        ]);
    }
}
