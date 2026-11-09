{{--
    How full the open conversation is, and what was folded up — item 5.

    ⚠️ IT SITS UNDER THE COMPOSER, where somebody is already looking when they
    wonder whether she still remembers the beginning. Putting it in the side
    panel would be tidier and would be read by nobody.

    ⚠️ THE SUMMARY IS EDITABLE HERE, and that is not a convenience. A summary is
    the one place in this app where model output becomes fact on a later turn
    with nobody accepting it first (CLAUDE.md §8 rule 4) — see
    SummarisesConversations, whose own docblock names this box as the reason
    that is acceptable: the correction arrives after the fact instead of before
    it, but it arrives where the thing is read.

    Hidden until a conversation is open. Styled by conversations.css, which the
    rendering page names.
--}}
<div class="conversation-meter" data-conversation-meter hidden>

    <div class="conversation-meter-bar" role="presentation">
        <span data-conversation-meter-fill></span>
    </div>

    <div class="conversation-meter-row">
        <p class="conversation-meter-note" data-conversation-meter-note></p>

        {{-- Somebody who knows a topic is finished should not have to wait for
             a threshold. The only button here that costs an API call, which is
             why its route carries the throttle and 'ai-turn'. --}}
        <button type="button" class="conversation-meter-fold" data-conversation-summarise>
            Resumir ahora
        </button>
    </div>

    <details class="conversation-summary" data-conversation-summary hidden>
        <summary>Lo que quedó resumido</summary>

        <label class="screen-reader-only" for="conversation-summary-text">
            Resumen de lo anterior
        </label>
        <textarea id="conversation-summary-text" rows="6"
                  data-conversation-summary-text></textarea>

        <button type="button" class="admin-button admin-button-secondary admin-button-sm"
                data-conversation-summary-save>
            Guardar resumen
        </button>
    </details>

</div>
