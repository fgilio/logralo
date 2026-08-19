/**
 * The box you leave a comment in.
 *
 * The draft never leaves the browser until it is sent. A comment is a handful
 * of words typed in one go, and binding it to the server would put a request
 * behind a keystroke to buy nothing — so the field is Alpine's, and `send`
 * takes the line as an argument.
 *
 * That is also what makes the box empty the instant the arrow is tapped, the
 * way every chat on the phone does. The round trip is still running when the
 * field is already clear; if it comes back refused, the words are handed back
 * rather than lost.
 *
 * `options.max` is the cap the field and the Action both enforce, passed in
 * so the number is `config('logralo.comments.max_length')` and not a second
 * opinion about it.
 */
export default (options = {}) => ({
    max: options.max ?? Infinity,

    draft: "",
    sending: false,

    /** How much of the cap is left, shown once it is close enough to matter. */
    get left() {
        return this.max - this.draft.length;
    },

    get ready() {
        return this.draft.trim() !== "" && !this.sending;
    },

    init() {
        // Watched rather than handled on `input`, so a draft handed back after
        // a failed send resizes the box the same way typing into it does.
        this.$watch("draft", () => this.$nextTick(() => this.grow()));
        this.grow();
    },

    /**
     * One line to start with, up to four as the comment gets longer. A 280
     * character cap in front of a box that shows twenty of them is a peephole,
     * and the whole point of the field is reading back what you wrote.
     *
     * Collapsed before it is measured: `scrollHeight` is how tall the content
     * is *or* how tall the box already is, whichever is greater, so a box that
     * is never shrunk can only ever grow.
     */
    grow() {
        const field = this.$refs.field;

        if (!field) return;

        field.style.height = "auto";
        field.style.height = `${field.scrollHeight}px`;
    },

    async send() {
        if (!this.ready) return;

        // Squished on the way out as well as in the Action: the cap counts
        // characters, and the member should not spend theirs on spaces.
        const body = this.draft.trim();

        this.sending = true;
        this.draft = "";

        if (navigator.vibrate) navigator.vibrate(8);

        try {
            // `false` is a comment the server refused — an empty line, a mark
            // this member cannot write to. It said why in a toast; what is
            // left to do is give the words back.
            if ((await this.$wire.send(body)) === false) this.draft = body;
        } catch {
            // No answer at all: the request never landed, so neither did the
            // comment.
            this.draft = body;
        } finally {
            this.sending = false;
        }
    },
});
