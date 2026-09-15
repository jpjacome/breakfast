{{--
    What a file's visibility is, and the one click that changes it.

    ⚠️ THE BADGE IS ONLY ON THE INTERNAL ONES. Shared is the ordinary case and
    most of a folder; badging every row would be a wall of colour with nothing
    standing out, and the row worth catching at a glance is the file the client
    must not see. Silence means shared — stated in the hint above the list, not
    inferred from an absent badge.

    The button is here rather than behind a confirm dialog: this is reversible,
    it is the fix for a mistake, and a confirm on the undo makes the undo feel
    as dangerous as the mistake.

    Props:
      client
      asset
--}}
@props(['client', 'asset'])

<form method="POST" action="{{ route('admin.clients.assets.visibility', [$client, $asset]) }}"
      class="asset-visibility">
    @csrf
    @method('PATCH')

    @if ($asset->isInternal())
        <span class="admin-badge {{ $asset->visibility->badgeClass() }}">
            <x-tabler-lock aria-hidden="true" />
            {{ $asset->visibility->shortLabel() }}
        </span>
    @endif

    <button type="submit" class="admin-button admin-button-ghost admin-button-sm">
        {{ $asset->isInternal() ? 'Compartir con la marca' : 'Hacerlo interno' }}
    </button>
</form>
