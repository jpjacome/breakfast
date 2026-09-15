<?php

namespace App\Http\Controllers\Admin;

use App\Actions\InviteBreakfastStaff;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStaffMemberRequest;
use App\Http\Requests\UpdateStaffMemberRequest;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /admin/equipo — the people who work at Breakfast.
 *
 * Everyone on staff can see the roster; only an Admin changes it. That split is
 * the Admin/Equipo distinction from UserRole: a staff account reaches every
 * brand in the system, so minting one is back-office, not day-to-day.
 *
 * The write methods are gated twice on purpose — the form requests authorize()
 * the role, and guardManageable() re-checks the record that was actually
 * handed in. Being an admin proves you may edit *somebody*, not this row.
 */
class StaffController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.staff', [
            'members' => User::query()
                ->whereIn('role', array_column(UserRole::breakfastRoles(), 'value'))
                // Eager-loaded so the roster ticks a hundred checkboxes without
                // a query per person.
                ->with('assignedClients')
                ->orderBy('name')
                ->get(),
            'canManage' => $request->user()->isAdmin(),

            // Every brand, not the acting user's — an Admin is the only one
            // who reaches this form and an Admin covers all of them anyway.
            'brands' => Client::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreStaffMemberRequest $request, InviteBreakfastStaff $invite): RedirectResponse
    {
        $result = $invite->handle(
            name: $request->validated('name'),
            email: $request->validated('email'),
            role: $request->role(),
        );

        $user = $result['user'];

        $user->assignedClients()->sync($request->clientIds());

        return back()->with([
            'status' => $result['delivered']
                ? "{$user->name} ya es parte del equipo. Le enviamos un enlace para crear su contraseña."
                : "Creamos la cuenta de {$user->name}, pero no se pudo enviar el correo — comparte la contraseña temporal.",
            // Flashed, so it survives exactly one redirect and then is gone.
            'temp_password' => $result['password'],
            'temp_password_for' => $user->email,
        ]);
    }

    public function update(UpdateStaffMemberRequest $request, User $user): RedirectResponse
    {
        $this->guardManageable($request, $user);

        $role = $request->role();

        // Demoting yourself would leave nobody able to undo it. Someone else's
        // role is fair game: whoever is acting is an admin, so demoting another
        // admin always leaves at least one standing.
        abort_if($role !== $user->role && $user->is($request->user()), 403);

        // No email here, and not by omission: the address is the account. It is
        // the username, the only way back in through a reset link, and what the
        // invitation was addressed to. A wrong address is fixed by removing the
        // account and inviting the right one — see destroy() and store().
        $user->update([
            'name' => $request->validated('name'),
            'role' => $role,
        ]);

        // Stored for Admins too even though their access does not depend on
        // it — see UserRole and the client_staff migration.
        $user->assignedClients()->sync($request->clientIds());

        return back()->with('status', "Actualizamos a {$user->name}.");
    }

    /** Re-send the "create your password" mail. */
    public function resend(Request $request, User $user, InviteBreakfastStaff $invite): RedirectResponse
    {
        $this->guardManageable($request, $user);

        return back()->with(
            'status',
            $invite->sendSetupLink($user)
                ? "Enlace reenviado a {$user->email}."
                : 'No se pudo enviar el correo. Revisa la configuración de mail.'
        );
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->guardManageable($request, $user);

        // Same reasoning as the role change in update(): the only account that
        // could strand the back-office is your own.
        abort_if($user->is($request->user()), 403);

        $name = $user->name;
        $user->delete();

        return back()->with('status', "Quitamos del equipo a {$name}.");
    }

    /**
     * The target must be Breakfast staff, and the person acting must be an
     * admin. Without the first check a crafted id would route a client user
     * through here, where roles are assigned without a brand.
     */
    private function guardManageable(Request $request, User $user): void
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($user->isBreakfast(), 404);
    }
}
