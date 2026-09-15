{{--
    Who an upload is for, on the two forms that fill a brand's folder.

    A component because the same form is on the process screen and on the file
    manager, and the two must not drift: a choice that exists on one screen and
    not the other is how a contract ends up shared because somebody happened to
    upload it from the wrong page.

    RADIOS, NOT A CHECKBOX. "Sólo para Breakfast ☐" states one option and leaves
    the other implied, and the implied one here is the one that shows a file to
    a client. Both are written out, both say what they mean, and the safe one is
    selected — nobody uploads a logo pack having to think about permissions.

    Props:
      selected  which option is pre-selected; defaults to Compartido
--}}
@props(['selected' => \App\Enums\AssetVisibility::default()])

<fieldset class="admin-choice">
    <legend>¿Quién lo ve?</legend>

    @foreach (\App\Enums\AssetVisibility::cases() as $option)
        <label class="admin-choice-option">
            <input type="radio" name="visibility" value="{{ $option->value }}"
                   @checked(old('visibility', $selected->value) === $option->value)>

            <span class="admin-choice-text">
                <b>
                    <x-dynamic-component :component="'tabler-'.$option->icon()" aria-hidden="true" />
                    {{ $option->label() }}
                </b>
                <span class="admin-hint">{{ $option->description() }}</span>
            </span>
        </label>
    @endforeach
</fieldset>
