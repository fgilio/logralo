/**
 * The box you leave a comment in.
 *
 * The draft is Alpine's rather than a `wire:model` property, so that the box
 * can empty the instant the arrow is tapped, the way every chat on the phone
 * does: `send` takes the line as an argument and answers whether it landed,
 * and the round trip is still running when the field is already clear. If it
 * comes back refused, the words are handed back rather than lost. (The old
 * binding cost no requests — plain `wire:model` is deferred — so what this
 * buys is the wait, not the traffic.)
 *
 * The box grows with what is in it, and that is `field-sizing: content` on the
 * textarea rather than anything here — the same way Flux sizes its own
 * `rows="auto"`.
 *
 * `options.max` is the cap the field and the Action both enforce, passed in so
 * the number is `config('logralo.comments.max_length')` and not a second
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
