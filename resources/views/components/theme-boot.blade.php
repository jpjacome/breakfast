{{--
    Applies the saved theme before the page paints.

    This belongs in <head>, above the stylesheet, and nowhere else: if it ran
    any later the browser would paint the light default first and a dark-mode
    user would see a white flash on every navigation.

    Light is the default, so there is nothing to do unless "dark" was chosen.
    The choice is written by x-theme-toggle, which is the other half of this.
--}}
<script>
    try {
        if (localStorage.getItem('breakfast-theme') === 'dark') {
            document.documentElement.dataset.theme = 'dark';
        }
    } catch (e) {
        // Storage is blocked (private mode, third-party rules). The page still
        // works, it just opens light every time.
    }
</script>
