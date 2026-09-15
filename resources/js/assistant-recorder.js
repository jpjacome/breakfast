/**
 * Recording a voice note for Brandy, in a format she can actually be sent.
 *
 * ⚠️ THIS EXISTS BECAUSE OF ONE INCOMPATIBILITY, and it is worth stating before
 * anybody "simplifies" it away.
 *
 * MediaRecorder gives you what the browser feels like giving you: Chrome
 * records audio/webm, Firefox audio/ogg, Safari audio/mp4. The provider takes
 * wav, mp3, m4a, ogg, flac, aac and aiff — and NOT webm (see Attachment,
 * which refuses it deliberately; sending one is a 400). So on the most common
 * browser in the world, the obvious implementation produces the one format
 * that cannot be sent.
 *
 * Rather than gamble on codec negotiation, the recording is decoded and
 * re-encoded to WAV here. WAV is on the accepted list everywhere, needs no
 * library, and is a format every browser can produce from raw samples.
 *
 * Mono at 16 kHz, which is speech quality: a two-minute note lands around
 * 3.8 MB instead of 20+, and nothing about a voice memo needs more. The model
 * transcribes it; it is not going in a commercial.
 */

/** 16 kHz mono — enough for a voice, a fraction of the bytes. */
const SAMPLE_RATE = 16000;

export function recordingSupported() {
    return typeof MediaRecorder !== 'undefined'
        && !!navigator.mediaDevices?.getUserMedia;
}

/**
 * Start recording. Resolves to a handle with stop(), which gives back a WAV
 * File ready to upload, and cancel(), which throws the audio away.
 */
export async function startRecording() {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    const recorder = new MediaRecorder(stream);
    const chunks = [];

    recorder.addEventListener('dataavailable', (event) => {
        if (event.data.size) chunks.push(event.data);
    });

    recorder.start();

    // The microphone light stays on until every track is stopped, not just
    // the recorder. Leaving it lit after a voice note is its own kind of bug.
    const release = () => stream.getTracks().forEach((track) => track.stop());

    return {
        cancel() {
            recorder.stop();
            release();
        },

        stop() {
            return new Promise((resolve, reject) => {
                recorder.addEventListener('stop', async () => {
                    release();

                    try {
                        const blob = new Blob(chunks, { type: recorder.mimeType });
                        resolve(await toWavFile(blob));
                    } catch (error) {
                        reject(error);
                    }
                }, { once: true });

                recorder.stop();
            });
        },
    };
}

/** Whatever the browser recorded → a mono 16 kHz WAV File. */
async function toWavFile(blob) {
    const bytes = await blob.arrayBuffer();

    // decodeAudioData understands every container the browser can record,
    // which is the whole reason the conversion goes through it.
    const decoded = await new AudioContext().decodeAudioData(bytes);
    const samples = downmixTo16k(decoded);

    return new File([encodeWav(samples)], 'nota-de-voz.wav', { type: 'audio/wav' });
}

/**
 * Every channel folded into one, resampled to 16 kHz.
 *
 * Linear interpolation rather than a proper resampler: the input is a voice at
 * 44.1 or 48 kHz and the output is read by a transcription model, so the
 * artefacts a sinc filter would remove are not artefacts anybody or anything
 * here will notice.
 */
function downmixTo16k(buffer) {
    const channels = Array.from(
        { length: buffer.numberOfChannels },
        (_, i) => buffer.getChannelData(i),
    );

    const ratio = buffer.sampleRate / SAMPLE_RATE;
    const length = Math.floor(buffer.length / ratio);
    const out = new Float32Array(length);

    for (let i = 0; i < length; i++) {
        const at = Math.floor(i * ratio);
        let sum = 0;

        for (const channel of channels) sum += channel[at];

        out[i] = sum / channels.length;
    }

    return out;
}

/** Float samples → a 16-bit PCM WAV blob. The header is 44 bytes of spec. */
function encodeWav(samples) {
    const buffer = new ArrayBuffer(44 + samples.length * 2);
    const view = new DataView(buffer);

    const text = (offset, string) => {
        for (let i = 0; i < string.length; i++) view.setUint8(offset + i, string.charCodeAt(i));
    };

    text(0, 'RIFF');
    view.setUint32(4, 36 + samples.length * 2, true);
    text(8, 'WAVE');
    text(12, 'fmt ');
    view.setUint32(16, 16, true);           // PCM header size
    view.setUint16(20, 1, true);            // PCM, uncompressed
    view.setUint16(22, 1, true);            // mono
    view.setUint32(24, SAMPLE_RATE, true);
    view.setUint32(28, SAMPLE_RATE * 2, true); // byte rate
    view.setUint16(32, 2, true);            // block align
    view.setUint16(34, 16, true);           // bits per sample
    text(36, 'data');
    view.setUint32(40, samples.length * 2, true);

    let offset = 44;

    for (const sample of samples) {
        // Clamp before scaling: a value outside [-1, 1] wraps around into loud
        // noise rather than clipping quietly.
        const clamped = Math.max(-1, Math.min(1, sample));
        view.setInt16(offset, clamped * 0x7fff, true);
        offset += 2;
    }

    return new Blob([buffer], { type: 'audio/wav' });
}
