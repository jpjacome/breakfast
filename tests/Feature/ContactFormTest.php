<?php

use App\Mail\ContactMessage;
use Illuminate\Support\Facades\Mail;

$valid = [
    'first_name' => 'María',
    'last_name' => 'García',
    'email' => 'maria@lamarca.test',
    'phone' => '+57 300 000 0000',
    'about_brand' => 'Somos una panadería de barrio.',
];

test('a submission reaches the contact inbox', function () use ($valid) {
    Mail::fake();
    config(['mail.contact_inbox' => 'info@vamosdebreakfast.com']);

    $this->post(route('contacto.store'), $valid)
        ->assertRedirect(route('contacto'))
        ->assertSessionHas('status');

    Mail::assertSent(
        ContactMessage::class,
        fn (ContactMessage $mail) => $mail->hasTo('info@vamosdebreakfast.com'),
    );
});

test('the visitor is the reply-to, not the from', function () use ($valid) {
    Mail::fake();

    $this->post(route('contacto.store'), $valid);

    // From has to stay the app's own address or the message fails SPF at the
    // receiving end; hitting reply still has to reach the person who wrote in.
    Mail::assertSent(
        ContactMessage::class,
        fn (ContactMessage $mail) => $mail->hasReplyTo('maria@lamarca.test'),
    );
});

test('with no CONTACT_INBOX set it falls back to the from-address', function () use ($valid) {
    Mail::fake();
    config([
        'mail.contact_inbox' => null,
        'mail.from.address' => 'fallback@vamosdebreakfast.com',
    ]);

    $this->post(route('contacto.store'), $valid);

    Mail::assertSent(
        ContactMessage::class,
        fn (ContactMessage $mail) => $mail->hasTo('fallback@vamosdebreakfast.com'),
    );
});

test('the message carries every field the form collects', function () use ($valid) {
    Mail::fake();

    $this->post(route('contacto.store'), $valid);

    Mail::assertSent(ContactMessage::class, function (ContactMessage $mail) {
        $body = $mail->render();

        return str_contains($body, 'María García')
            && str_contains($body, 'maria@lamarca.test')
            && str_contains($body, '+57 300 000 0000')
            && str_contains($body, 'Somos una panadería de barrio.');
    });
});

test('a bot that fills the honeypot sends nothing', function () use ($valid) {
    Mail::fake();

    $this->post(route('contacto.store'), [...$valid, 'website' => 'http://spam.test'])
        ->assertSessionHasErrors('website');

    Mail::assertNothingSent();
});

test('an incomplete submission sends nothing', function () {
    Mail::fake();

    $this->post(route('contacto.store'), ['first_name' => 'Solo el nombre'])
        ->assertSessionHasErrors(['last_name', 'email', 'phone']);

    Mail::assertNothingSent();
});

test('about_brand is optional', function () use ($valid) {
    Mail::fake();

    $this->post(route('contacto.store'), [...$valid, 'about_brand' => null])
        ->assertSessionHasNoErrors();

    Mail::assertSent(ContactMessage::class);
});
