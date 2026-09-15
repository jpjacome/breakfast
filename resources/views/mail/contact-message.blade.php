Nueva solicitud de sesión introductoria desde el formulario de /contacto.

Nombre:            {{ $submission['first_name'] }} {{ $submission['last_name'] }}
Email:             {{ $submission['email'] }}
Whatsapp/Teléfono: {{ $submission['phone'] }}

Sobre su marca:
{{ $submission['about_brand'] ?: '(no escribió nada)' }}
