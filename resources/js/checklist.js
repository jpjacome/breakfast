/**
 * The implementation checklist saves itself — SEG-05.
 *
 * The form works without this: it has a real submit button and posts like any
 * other form. What this adds is that ticking a box saves it, because a
 * checklist where you tick four things and then have to remember a button is a
 * checklist that loses ticks.
 *
 * ⚠️ IT POSTS THE FORM RATHER THAN A fetch(). The endpoint answers with a
 * redirect — bootstrap/app.php only renders JSON for api/*, so a failed
 * validation would reach a fetch as an opaque redirect and look like success
 * (CLAUDE.md trap 13). Submitting the form means the browser follows the
 * redirect and the page comes back with the saved state actually rendered,
 * which is also the only version of this that cannot drift out of sync.
 *
 * The cost is a page load per tick. Accepted: this list is a handful of items
 * somebody works through over weeks, not a control they hammer.
 */

const form = document.querySelector('[data-checklist]');

if (form) {
    // Tells the stylesheet the save button is redundant now. Set from here
    // rather than in the markup so the button is only ever hidden when the
    // script that replaces it is actually running.
    form.classList.add('is-live');

    let saving = false;

    form.addEventListener('change', (event) => {
        if (!event.target.matches('input[type="checkbox"]')) return;
        if (saving) return;

        saving = true;

        // Say so before the page goes: on a slow connection the gap between
        // the click and the reload is long enough to click again.
        const note = document.createElement('span');
        note.className = 'checklist-saved';
        note.textContent = 'Guardando…';
        form.append(note);

        form.requestSubmit();
    });
}
