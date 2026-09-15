<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The portal inbox behind the bell.
 *
 * Notifications belong to the USER, not the brand, so there is no section gate
 * here: everybody has an inbox, and what lands in it was already filtered by
 * who could see the thing it is about — see Client::meetingAudience().
 */
class NotificationController extends Controller
{
    /** Mark everything read and go wherever they were headed. */
    public function read(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}
