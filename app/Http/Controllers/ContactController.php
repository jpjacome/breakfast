<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactMessageRequest;
use App\Mail\ContactMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function store(StoreContactMessageRequest $request): RedirectResponse
    {
        $submission = $request->safe()->except('website');

        // CONTACT_INBOX is where the team reads these. Falls back to the app's
        // own from-address so a missing env var never loses a submission.
        $inbox = config('mail.contact_inbox') ?: config('mail.from.address');

        Mail::to($inbox)->send(new ContactMessage($submission));

        return redirect()
            ->route('contacto')
            ->with('status', 'Gracias. Recibimos tus datos y nuestro equipo se contactará contigo.');
    }
}
