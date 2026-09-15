{{--
    Entregable text, with any URLs in it turned into links — and any of OUR
    files shown as the image or video they are.

    An entregable that IS an asset holds the file's link as its content, so a
    brand page that printed the raw URL would be showing somebody a string to
    copy by hand.

    ⚠️ MEDIA IS SHOWN, NOT DESCRIBED — section 5 of the beta review. Twelve
    entregables (Relato, Prisma de Kapferer, Look & Feel, Arquitectura, Brand X,
    Campaña Paid, Banco de ideas, Optimización de perfiles, Perfil de contenido,
    Framework cultural, Service Design, Identidad gráfica) are text AND picture
    by nature, and a diagram of the Kapferer prism reduced to https://… is not
    the entregable. So a link to /archivos/{id} whose row is an image becomes an
    <img>, a playable video becomes a <video>, and everything else keeps the
    anchor it always had. The entregable is STILL TEXT — rule 1 of CLAUDE.md §8
    is untouched. Only the rendering changed.

    ⚠️ THE GATE IS UNAFFECTED, and this is the part worth being sure about.
    /archivos/{id} checks User::canReachBrandAsset() on every single request and
    serves Content-Disposition: inline, so an <img> pointing at an internal
    asset 404s for a client exactly as the link did. Showing an image is not a
    way around a permission; it is the same request with a different tag around
    it. Nothing here reads the file, and nothing here decides who may.

    ESCAPING HAPPENS FIRST, linkifying second. The other order would let a
    crafted entregable inject markup — the content comes from the admin form,
    but "it comes from staff" is not a security model.

    Props:
      text  the entregable's stored content
--}}
@props(['text'])

@php
    use App\Models\BrandAsset;

    $safe = e(trim((string) $text));

    // One pass over the URLs. Each is either one of our asset links — in which
    // case the row decides what tag it gets — or somebody else's, which stays
    // an anchor and opens in a new tab.
    $linked = preg_replace_callback(
        '~(https?://[^\s<]+[^\s<.,:;"\')\]])~',
        function (array $match): string {
            $url = $match[1];

            $asset = BrandAsset::fromUrl($url);

            if ($asset?->isImage()) {
                // No lazy attribute on purpose: these sit inside the entregable
                // somebody came to read, not below the fold of a feed.
                return '<img src="'.e($url).'" alt="'.e($asset->title).'" class="entregable-media">';
            }

            if ($asset?->isPlayableVideo()) {
                // preload="metadata": enough for a poster frame and a duration,
                // without pulling a 200MB file down for a page nobody scrolled to.
                return '<video src="'.e($url).'" class="entregable-media" controls preload="metadata"></video>';
            }

            return '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer">'.e($url).'</a>';
        },
        $safe,
    ) ?? $safe;

    $paragraphs = preg_split('/\n{2,}/', $linked) ?: [];
@endphp

@foreach ($paragraphs as $paragraph)
    @if (trim($paragraph) !== '')
        {{-- nl2br so single line breaks survive: a five-value list typed one
             per line is the normal shape of half these entregables. --}}
        <p>{!! nl2br(trim($paragraph)) !!}</p>
    @endif
@endforeach
