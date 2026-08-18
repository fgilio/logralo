/**
 * Tap and hold on the same element. Announces itself with `long-press`
 * and `short-press`, and leaves the deciding to whoever spread it in.
 *
 * Arm a timer on pointerdown, drop it when the finger travels, and
 * swallow the click the press emits afterwards so an element never fires
 * both. This was two factories while the feed card had a hold of its own;
 * the card's went with the photo viewer, and the remaining half had one
 * caller.
 */
export default (options = {}) => ({
    delay: options.delay ?? 420,
    moveTolerance: options.moveTolerance ?? 10,

    timer: null,
    pointerId: null,
    startX: 0,
    startY: 0,
    suppressClick: false,
    onScroll: null,
    fired: false,
    pressing: false,

    destroy() {
        this.cancel();
    },

    /**
     * A scroll begun elsewhere steals the gesture on iOS without ever
     * firing pointercancel, so the press listens for one. The listener
     * lives only as long as the press does, so a page of cards does not
     * run one per card per frame.
     */
    start(event) {
        if (event.button !== undefined && event.button !== 0) return;
        if (this.pointerId !== null) return this.cancel();

        this.pointerId = event.pointerId;
        this.startX = event.clientX;
        this.startY = event.clientY;
        this.suppressClick = false;
        this.fired = false;
        this.pressing = true;

        this.onScroll ??= () => this.cancel();
        window.addEventListener("scroll", this.onScroll, {
            capture: true,
            passive: true,
        });

        try {
            event.currentTarget.setPointerCapture(event.pointerId);
        } catch {
            // Capture is a nicety; the window listeners still cover us.
        }

        this.timer = setTimeout(() => {
            this.timer = null;
            this.fired = true;
            this.suppressClick = true;
            this.pressing = false;

            if (navigator.vibrate) navigator.vibrate(12);

            this.$dispatch("long-press");
        }, this.delay);
    },

    move(event) {
        if (this.timer === null) return;

        if (this.travelled(event)) this.cancel();
    },

    end() {
        const wasArmed = this.timer !== null;

        this.cancel();

        if (wasArmed && !this.fired) this.$dispatch("short-press");
    },

    cancel() {
        if (this.timer !== null) {
            clearTimeout(this.timer);
            this.timer = null;
        }

        if (this.onScroll !== null) {
            window.removeEventListener("scroll", this.onScroll, {
                capture: true,
            });
        }

        this.pointerId = null;
        this.pressing = false;
    },

    /** A finger that travels this far was scrolling, not holding. */
    travelled(event) {
        return (
            Math.abs(event.clientX - this.startX) > this.moveTolerance ||
            Math.abs(event.clientY - this.startY) > this.moveTolerance
        );
    },

    onClick(event) {
        if (!this.suppressClick) return;

        this.suppressClick = false;
        event.preventDefault();
        event.stopPropagation();
    },
});
