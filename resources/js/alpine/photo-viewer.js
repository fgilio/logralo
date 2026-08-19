/**
 * The photo, full screen.
 *
 * One viewer for the feed, filled by whichever card was tapped — the
 * `photo` payload is everything `x-feed.photo-button` knows and this has
 * no other way of learning without a round trip.
 *
 * Flux's modal owns the dialog itself — the top layer, the scroll lock,
 * the focus placeholder and Escape. What is left is the gesture every
 * phone photo viewer answers to: drag the picture and it follows, let go
 * far enough and it goes away. A tap dismisses too, which is what the
 * viewer this replaced did with any click at all.
 *
 * The drag is upward and nothing else. It used to be measured in both
 * axes, away from the start point, on the grounds that there was nothing
 * to swipe sideways to — and that stopped being true the day a thread of
 * comments landed under the photo. Down is now "let me read what people
 * said" and sideways is nothing at all, so a single direction is the only
 * one that can mean "close" without being guessed at.
 *
 * Reactions live here rather than only on the card. The counts are carried
 * in the payload and updated in place when somebody taps: the write goes
 * to the feed's own `react`, which is the component this dialog is
 * rendered inside, and waiting for its round trip to redraw a number the
 * finger just chose is the one thing a reaction must never do.
 */
export default (options = {}) => ({
    threshold: 96,
    tapTolerance: 10,

    /**
     * The emoji characters, keyed by the enum's own values. Handed in from
     * the template rather than written here, because `ReactionEmoji` is what
     * says the characters are changeable and a second copy would make that
     * false.
     */
    characters: options.characters ?? {},

    photo: {},
    pointerId: null,
    startX: 0,
    startY: 0,

    /** How far the picture is lifted. Never positive: down is not a way out. */
    y: 0,

    /**
     * How far the finger went in any direction, which is a different question
     * from how far the picture moved. Without it a downward drag lands on
     * `y === 0` and reads as a tap — closing the viewer on the one gesture
     * that means "let me see what is under here".
     */
    travelled: 0,

    /** Which emoji this member chose, and the tally across everybody. */
    reacted: null,
    counts: {},
    picking: false,

    /** A pointer is down on the picture, so the drag owns the transform. */
    get dragging() {
        return this.pointerId !== null;
    },

    /**
     * Where the picture sits, and how far gone it looks on the way out.
     * One binding rather than two, so a pointermove costs one style write.
     */
    get carried() {
        if (this.y === 0) return "";

        const faded = 1 - Math.min(-this.y / 480, 0.75);

        return `transform: translateY(${this.y}px); opacity: ${faded}; will-change: transform, opacity`;
    },

    /** The emoji shown beside the count, most-used first, mine kept. */
    get faces() {
        const shown = Object.entries(this.counts)
            .sort((a, b) => b[1] - a[1])
            .map(([emoji]) => emoji);

        if (this.reacted && !shown.includes(this.reacted)) {
            shown.unshift(this.reacted);
        }

        return shown.slice(0, 3);
    },

    get total() {
        return Object.values(this.counts).reduce((sum, n) => sum + n, 0);
    },

    show(photo) {
        this.cancel();
        this.picking = false;

        this.photo = photo;
        this.reacted = photo.reacted ?? null;
        // Copied rather than referenced: the payload is the card's, and a
        // tap in here must not rewrite what the card is drawn from.
        this.counts = { ...(photo.reactions ?? {}) };

        // The thread cannot travel in the payload. It changes after the card
        // was drawn, and one copy per card is exactly the weight this shared
        // dialog exists to avoid — so it is fetched when the photo opens.
        window.Livewire?.dispatch("photo-comments-open", {
            markId: photo.markId,
        });

        window.Flux?.modal("foto").show();
    },

    /** Tapping the same emoji removes it, a different one swaps it. */
    choose(emoji) {
        const previous = this.reacted;

        if (previous) this.bump(previous, -1);

        this.reacted = previous === emoji ? null : emoji;

        if (this.reacted) this.bump(this.reacted, 1);

        this.picking = false;

        this.$wire?.react(this.photo.markId, emoji);
    },

    bump(emoji, by) {
        const next = (this.counts[emoji] ?? 0) + by;

        if (next > 0) this.counts[emoji] = next;
        else delete this.counts[emoji];
    },

    start(event) {
        if (event.button !== undefined && event.button !== 0) return;

        this.pointerId = event.pointerId;
        this.startX = event.clientX;
        this.startY = event.clientY;
        this.travelled = 0;

        try {
            event.currentTarget.setPointerCapture(event.pointerId);
        } catch {
            // Capture is a nicety; the pointerup still lands on the photo.
        }
    },

    move(event) {
        if (event.pointerId !== this.pointerId) return;

        this.travelled = Math.hypot(
            event.clientX - this.startX,
            event.clientY - this.startY,
        );

        // Clamped at zero: dragging down does not move the picture, so the
        // gesture reads as a wall rather than as a spring that gave way.
        this.y = Math.min(event.clientY - this.startY, 0);
    },

    end(event) {
        if (event.pointerId !== this.pointerId) return;

        const lifted = -this.y;
        const moved = this.travelled;

        this.cancel();

        // A tap and a throw upward both mean the same thing. Everything else
        // — a short lift, a drag down, a drag sideways — is a gesture that
        // was not asking for the exit, and springs back.
        if (moved <= this.tapTolerance || lifted > this.threshold) {
            this.dismiss();
        }
    },

    cancel() {
        this.pointerId = null;
        this.y = 0;
        this.travelled = 0;
    },

    dismiss() {
        this.picking = false;

        window.Flux?.modal("foto").close();
    },
});
