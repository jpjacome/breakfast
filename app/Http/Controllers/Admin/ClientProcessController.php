<?php

namespace App\Http\Controllers\Admin;

use App\Actions\AdvanceProcessStep;
use App\Enums\ProcessStep;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateDeliverablesRequest;
use App\Models\Client;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The process screen: the three steps and the 48 entregables.
 *
 * This is the admin's whole working surface for a brand's project. The client
 * sees a different, much smaller view of the same data — the three-step bar,
 * their files, and the entregables that have content. They never see this list.
 *
 * Steps and entregables are deliberately independent. Closing a step validates
 * nothing about what has been filled in, because the admin knows when a step
 * ended and a gate here would only teach people to fill boxes to unlock a
 * button. See AdvanceProcessStep.
 */
class ClientProcessController extends Controller
{
    public function __construct(private readonly AdvanceProcessStep $steps) {}

    public function edit(Client $client): View
    {
        return view('admin.clients.process', [
            'client' => $client,
            'deliverables' => $client->deliverablesOrNew(),
            'assets' => $client->brandAssets()->with('uploader')->get(),
        ]);
    }

    public function update(UpdateDeliverablesRequest $request, Client $client): RedirectResponse
    {
        $deliverables = $client->deliverables()->firstOrNew();

        $deliverables->fill([
            ...$request->deliverables(),
            'updated_by' => $request->user()->id,
        ])->save();

        $filled = $deliverables->filledCount();

        return redirect()
            ->route('admin.clients.process.edit', $client)
            ->with('status', "Entregables guardados. {$filled} de 48 con contenido.");
    }

    /**
     * Start, close or reopen one step.
     *
     * One endpoint rather than three: the three are the same decision seen
     * from different sides, and splitting them would put the same authorisation
     * and the same redirect in three places.
     */
    public function step(Request $request, Client $client): RedirectResponse
    {
        $validated = $request->validate([
            'step' => ['required', 'string'],
            'action' => ['required', 'in:iniciar,completar,reabrir'],
        ]);

        $step = ProcessStep::tryFrom($validated['step']);

        // Fail closed: an unknown step is a hand-posted form, not a mistake
        // worth guessing at.
        if ($step === null) {
            return back()->with('status', 'Ese paso no existe.');
        }

        $message = match ($validated['action']) {
            'iniciar' => $this->start($client, $step),
            'completar' => $this->complete($client, $step, $request->user()),
            'reabrir' => $this->reopen($client, $step),
        };

        return redirect()
            ->route('admin.clients.process.edit', $client)
            ->with('status', $message);
    }

    private function start(Client $client, ProcessStep $step): string
    {
        $record = $this->steps->start($client, $step);

        if ($record === null) {
            $blocking = $client->currentStep();

            return "No se pudo: «{$blocking?->label()}» sigue en curso. Ciérralo antes de "
                ."empezar «{$step->label()}» — sólo un paso puede estar en curso a la vez.";
        }

        return "Paso {$step->number()} iniciado: {$step->label()}.";
    }

    private function complete(Client $client, ProcessStep $step, User $by): string
    {
        $this->steps->complete($client, $step, $by);

        $next = $step->next();

        return $next === null
            ? "Paso {$step->number()} completado. El proceso de esta marca está cerrado."
            : "Paso {$step->number()} completado. Empieza el {$next->number()}: {$next->label()}.";
    }

    private function reopen(Client $client, ProcessStep $step): string
    {
        $this->steps->reopen($client, $step);

        return "Paso {$step->number()} reabierto: {$step->label()}.";
    }
}
