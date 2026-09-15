{{--
    The assistant's orb.

    A canvas that configures itself. Everything here ends up on the element as
    data-orb-settings, and AssistantOrb merges it over its defaults on the way
    up — so a view says what it wants and no JavaScript has to be touched:

        <x-assistant-orb />
        <x-assistant-orb line-width="4" size="1.2" />
        <x-assistant-orb state="pensando" :settings="['states' => [
            'pensando' => ['spike' => [0.5, 2.2]],
        ]]" />

    The merge is deep, so that last example changes one pair of numbers in one
    state and leaves everything else at its default.

    The page still has to load the entry that constructs it:

        <x-layouts.app :scripts="['resources/js/orb-demo.js']">

    Sizing belongs to the page, not here — pass a class and let CSS decide.
--}}
@props([
    // Which loop it starts in: reposo | pensando | respondiendo.
    'state' => 'reposo',

    // The three that get changed most often, hoisted out of $settings so a
    // view can set them without writing a nested array.
    'lineWidth' => null,
    'size' => null,
    'transitionMs' => null,

    // Anything deeper: the full shape of ORB_DEFAULTS, partially applied.
    'settings' => [],
])

@php
    $config = $settings;

    // Cast, so "4" from an attribute does not reach the shader as a string.
    foreach ([
        'lineWidth' => $lineWidth,
        'size' => $size,
        'transitionMs' => $transitionMs,
    ] as $key => $value) {
        if ($value !== null) {
            $config[$key] = (float) $value;
        }
    }
@endphp

<canvas
    {{ $attributes->merge(['class' => 'orb-canvas']) }}
    data-orb
    data-orb-initial="{{ $state }}"
    @if ($config !== [])
        data-orb-settings="{{ json_encode($config) }}"
    @endif
></canvas>
