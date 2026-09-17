<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\InviteUserToClient;
use App\Http\Controllers\Controller;
use App\Models\BrandInvitation;
use App\Services\ActiveBrand;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Answering an invitation to a brand — queued item B.
 *
 * ⚠️ NOTHING HERE IS GATED ON A SECTION, because the person is by definition
 * not in the brand yet. The token plus being signed in as the invited address
 * IS the authorisation, and both are checked on every action below.
 *
 * ⚠️ AND THE BRAND IS NEVER NAMED TO THE WRONG PERSON. A token that is not
 * theirs 404s before the view renders, so a forwarded link does not tell
 * whoever opens it which brand invited whom.
 */
class InvitationController extends Controller
{
    public function __construct(
        private readonly InviteUserToClient $invite,
        private readonly ActiveBrand $active,
    ) {}

    public function show(Request $request, string $token): View
    {
        $invitation = $this->open($request, $token);

        return view('portal.invitacion', ['invitation' => $invitation]);
    }

    /**
     * Yes — and this is the only place a pending invitation becomes a
     * membership.
     *
     * It switches to the new brand afterwards, because somebody who just
     * accepted wants to look at it, and leaving them in whatever brand they
     * were in makes the acceptance feel like it did nothing.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->open($request, $token);
        $user = $request->user();

        $this->invite->accept($invitation, $user);

        $this->active->set($user, $invitation->client);

        return redirect()->route('portal.home')
            ->with('status', "Ya eres parte de {$invitation->client->name}.");
    }

    /**
     * No.
     *
     * ⚠️ THE INVITER IS NOT TOLD. A refusal that reports back turns "no" into a
     * conversation the invited person has to have, which is most of the reason
     * somebody would accept an invitation they did not want. The row is marked
     * and that is the end of it: the owner sees the invitation stop being
     * pending, which is all they need to invite somebody else.
     */
    public function decline(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->open($request, $token);

        $invitation->forceFill(['declined_at' => now()])->save();

        return redirect()->route('portal.home')
            ->with('status', 'Listo, rechazaste la invitación.');
    }

    /**
     * The invitation this request may act on, or 404.
     *
     * Three refusals, all 404 rather than 403 (CLAUDE.md §6): an unknown token,
     * a token belonging to a different address, and one already answered or
     * expired. None of them says which — a link that reports "already accepted"
     * still confirms the brand and the address to whoever is holding it.
     */
    private function open(Request $request, string $token): BrandInvitation
    {
        $invitation = BrandInvitation::where('token', $token)->first();

        abort_if($invitation === null, 404);
        abort_unless($invitation->isFor($request->user()), 404);
        abort_unless($invitation->isPending(), 404);

        return $invitation;
    }
}
