/**
 * Resolved on every call rather than once at import: `supported` is read when
 * a component initialises and the constructor when somebody taps, and the
 * browser suite installs a stand-in engine between those two moments.
 */
const engine = () => window.SpeechRecognition ?? window.webkitSpeechRecognition;

/**
 * Talking instead of typing, in the feedback sheet.
 *
 * The browser's own speech recognition, which on a phone means the engine
 * the operating system already ships. No audio passes through this app,
 * there is no key to buy and no bill to watch: what comes back is text,
 * written into the field as if a thumb had produced it, so the Action, the
 * row and the mail behind it never learn this exists.
 *
 * The language is the phone's rather than the app's. Recognition runs
 * against a model the device has installed, and the one it is certain to
 * have is the one its owner set it to — a Spanish tag on a phone in
 * English is how iOS answers `language-not-supported` instead of
 * listening. Spanish is only the fallback for a browser that says nothing.
 *
 * `options` carries what a second dictated field would differ by: `property`,
 * the Livewire property the words land on, and `max`, the cap that property's
 * own validation already enforces on the server.
 *
 * None of this is load-bearing. The button hides itself where the API is
 * missing, and every phone keyboard has a dictation key of its own; this
 * is a shortcut past the keyboard, not the door.
 */
export default (options = {}) => ({
    property: options.property ?? "body",
    max: options.max ?? Infinity,

    supported: Boolean(engine()),

    listening: false,
    recognition: null,
    dialog: null,

    /** The field as it stood when this stretch of talking began. */
    base: "",

    /**
     * A sheet dismissed mid-sentence would otherwise leave the microphone
     * open behind a closed door. `close` fires on a native `<dialog>`
     * however it went away — the send, the backdrop, Escape — and it does
     * not bubble, so it is listened for on the dialog itself.
     */
    init() {
        this.dialog = this.$root.closest("dialog");
        this.onDialogClose = () => this.stop();
        this.dialog?.addEventListener("close", this.onDialogClose);
    },

    destroy() {
        this.dialog?.removeEventListener("close", this.onDialogClose);
        this.stop();
    },

    toggle() {
        if (this.listening) return this.stop();

        this.start();
    },

    start() {
        const Recognition = engine();

        if (!Recognition) return;

        // Whatever is already in the box is kept. Somebody who typed half
        // a sentence and then gave up on the keyboard is finishing it, not
        // starting over.
        this.base = this.$wire[this.property] ?? "";

        const recognition = new Recognition();

        recognition.lang = navigator.language || "es";
        recognition.continuous = true;
        // Words on screen while they are still being said. Without this a
        // long report is several seconds of nothing happening, which reads
        // as a microphone that is not working.
        recognition.interimResults = true;

        recognition.onresult = (event) => this.hear(event);
        recognition.onerror = (event) => this.fail(event.error);
        // Every engine ends on its own — a pause, a limit of its own, the
        // stop below. The button follows it rather than the other way
        // around, so it never claims to be listening when nothing is.
        recognition.onend = () => {
            this.recognition = null;
            this.listening = false;
        };

        try {
            recognition.start();
        } catch {
            // A second start on a live instance throws; either way there
            // is nothing listening now.
            return;
        }

        this.recognition = recognition;
        this.listening = true;

        if (navigator.vibrate) navigator.vibrate(8);
    },

    stop() {
        const recognition = this.recognition;

        this.recognition = null;
        this.listening = false;

        try {
            recognition?.stop();
        } catch {
            // Already stopped, or never started. Nothing to wind down.
        }
    },

    /**
     * Rebuilt from the whole result list on every event rather than from
     * the new tail: an interim phrase is revised in place as the engine
     * changes its mind about it, and appending the revisions would say
     * everything twice.
     */
    hear(event) {
        let settled = "";
        let pending = "";

        for (let i = 0; i < event.results.length; i++) {
            const result = event.results[i];

            if (result.isFinal) settled += result[0].transcript;
            else pending += result[0].transcript;
        }

        this.write(settled + pending);
    },

    /**
     * Into the Livewire property, not the textarea: `wire:model` binds the
     * box to `$wire`, so this is the end the binding reads from and the box
     * follows. Whitespace is tidied on the spoken half only — engines vary
     * on the leading space and none of them emit newlines, while the typed
     * half is somebody's own line breaks.
     */
    write(spoken) {
        const said = spoken.replace(/\s+/g, " ").trimStart();
        const gap = this.base && !this.base.endsWith(" ") ? " " : "";

        this.$wire[this.property] = (this.base + gap + said).slice(0, this.max);
    },

    fail(error) {
        this.stop();

        // Silence, and a stop this code asked for. Neither is news.
        if (error === "no-speech" || error === "aborted") return;

        window.Flux?.toast?.({
            text:
                error === "not-allowed" || error === "service-not-allowed"
                    ? "Necesito permiso para usar el micrófono."
                    : "No pude escucharte. Escribilo y listo.",
            variant: "warning",
        });
    },
});
