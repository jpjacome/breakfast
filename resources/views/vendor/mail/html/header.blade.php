@props(['url'])
{{--
    The Breakfast wordmark, as an image, with the app name as its alt text.

    Gmail and Outlook block remote images until the reader clicks "show
    images", so the alt text is not decoration — it is what most people see
    the first time. It says the brand name for that reason, and nothing in
    this mail depends on the picture arriving.

    The src has to be absolute: mail has no page to resolve a relative path
    against. asset() builds it from APP_URL, so this renders as
    https://vamosdebreakfast.com/img/logo.png in production and points at
    whatever APP_URL says locally.
--}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('img/logo.png') }}" class="logo" alt="{{ config('app.name') }}">
</a>
</td>
</tr>
