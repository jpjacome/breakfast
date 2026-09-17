{{--
    Somebody wants to add your EXISTING account to their brand — queued item B.

    ⚠️ NOT mail/invitation.blade.php, and it must never be folded into it. That
    one goes to a person with no account and says "we made you one, pick a
    password" — reading the mailbox is the consent. This goes to somebody who
    has been signing in for months, about a brand they never chose, and the only
    thing it may do is ask.

    It is also a mail with a link asking for a decision, which is the exact
    shape people are taught to distrust. So it names who is inviting, says
    plainly that nothing has changed yet, and offers the refusal as an ordinary
    option rather than something to justify.

    Variables, all required:
      $name        who is being greeted
      $intro       one line: who invited them, and to what
      $actionUrl   the tokenised link to the invitation
      $expiresIn   how long it is good for, already worded ("14 días")
--}}
<x-mail::message>
# Hola, {{ $name }}.

{{ $intro }}

<x-mail::button :url="$actionUrl">
Ver la invitación
</x-mail::button>

<x-mail::panel>
**Tu cuenta no cambió.** No vas a ver nada de esa marca hasta que aceptes, y
puedes decir que no sin dar explicaciones.
</x-mail::panel>

La invitación vence en {{ $expiresIn }}.

Si no esperabas esto, ignóralo: sin tu respuesta no pasa nada.

— El equipo de {{ config('app.name') }}
</x-mail::message>
