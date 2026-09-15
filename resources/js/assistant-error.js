/**
 * What to say when a turn does not come back.
 *
 * ⚠️ THE STATUS CODE IS ALWAYS PRINTED. Both panels used to answer every
 * failure with "no se pudo contactar al asistente", which is true of a dead
 * network, an expired session, a file too big, a busy pool and a provider
 * outage alike — five different problems with five different answers, all
 * wearing the same sentence. On 2026-08-18 that cost an afternoon: the server
 * was returning 503 because a brandbook was holding a PHP worker, and the only
 * way to discover it was reading the raw access log over SSH. A "(503)" on
 * screen would have started that conversation at the answer.
 *
 * So the shape is: a sentence somebody can act on, then the number somebody can
 * search for. The sentence is for the person using it, the number is for
 * whoever they forward the screenshot to.
 *
 * The server's own message always wins where there is one — `{error: …}` from
 * a controller, or Laravel's `{errors: {…}}` from a FormRequest — because it
 * knows what actually happened and this file only knows the status.
 */

/** Status → what a person can do about it. */
const MEANING = {
    413: 'El archivo pesa demasiado para el servidor. Prueba con un PDF más ligero '
        + 'o con sólo las páginas que importan.',
    419: 'La sesión caducó. Recarga la página y vuelve a enviarlo.',
    429: 'Demasiadas consultas seguidas, o el asistente está atendiendo otra. '
        + 'Espera unos segundos.',
    500: 'Algo falló al procesar la consulta.',
    // ⚠️ NO NAMES A SUPPLIER. This used to read "el proveedor de IA no
    // respondió", which told a client about a company they have no
    // relationship with and gave them nothing to do about it. The server's own
    // sentence wins where there is one — see App\Services\Ai\AssistantFailure,
    // which decides what a client may read and what staff may read. This is the
    // fallback for when nothing came back at all.
    502: 'No obtuve respuesta esta vez. Vuelve a intentarlo.',
    // The one this file was written for. It is not a bug in the assistant: the
    // request was killed for taking too long or for arriving while the server
    // was busy with another reading.
    503: 'El servidor cortó la petición — suele pasar cuando un archivo tarda '
        + 'mucho en leerse. Espera un momento y prueba otra vez, o manda un PDF '
        + 'más corto.',
    504: 'La respuesta tardó demasiado. Vuelve a intentarlo.',
};

/**
 * The sentence to show for a failed response.
 *
 * @param {Response} response  the fetch response, already known to be !ok
 * @param {object} data        whatever JSON came with it, or {}
 */
export function explain(response, data = {}) {
    const said = data.error ?? Object.values(data.errors ?? {}).flat()[0];

    if (said) {
        // A 422 is somebody's own file or message being refused and reads fine
        // on its own; anything else gets the number appended so an unexpected
        // failure stays traceable even when the server explained itself.
        return response.status === 422 ? said : `${said} (${response.status})`;
    }

    return `${MEANING[response.status] ?? 'No se pudo contactar al asistente.'} (${response.status})`;
}

/**
 * The sentence for a request that never got a response at all.
 *
 * A different case from the above and worth its own words: nothing reached the
 * server, so nothing was spent and nothing was recorded.
 */
export function explainNetwork() {
    return 'Se cortó la conexión con el asistente. No se envió nada.';
}
