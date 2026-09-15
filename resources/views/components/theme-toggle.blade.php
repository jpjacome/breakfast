{{--
    The light/dark switch, mounted in the rail of both back-office shells.

    A button rather than a link or a checkbox: it changes how the page looks,
    not where you are, and unlike the sidebar's checkbox toggle the choice has
    to survive the next page load — which means storage, which means script.

    Its accessible name is "Modo oscuro" and aria-pressed says whether that is
    on, so a screen reader announces a state rather than a destination. The
    visible icon says the same thing the other way round: it shows the face you
    are switching TO. Styles live in general.css under .theme-toggle.

    x-theme-boot, in <head>, is the half that applies the stored choice before
    the page paints. Both halves know the same storage key.
--}}
<button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false">
    <x-tabler-moon class="theme-toggle-moon" aria-hidden="true" />
    <x-tabler-sun class="theme-toggle-sun" aria-hidden="true" />
    <span class="screen-reader-only">Modo oscuro</span>
</button>

<script>
    (function () {
        var root = document.documentElement;
        var buttons = document.querySelectorAll('[data-theme-toggle]');

        function announce(isDark) {
            buttons.forEach(function (button) {
                button.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            });
        }

        // The boot script already decided; this only catches the button up.
        announce(root.dataset.theme === 'dark');

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                var isDark = root.dataset.theme !== 'dark';

                if (isDark) {
                    root.dataset.theme = 'dark';
                } else {
                    delete root.dataset.theme;
                }

                announce(isDark);

                try {
                    localStorage.setItem('breakfast-theme', isDark ? 'dark' : 'light');
                } catch (e) {
                    // Storage is blocked. The switch still works for this page.
                }
            });
        });
    })();
</script>
