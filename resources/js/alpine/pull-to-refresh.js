/**
 * Pull to refresh over the feed.
 *
 * Touch events, not pointer ones. A vertical drag belongs to the scroller
 * within a frame or two of starting, and the browser says so by firing
 * `pointercancel` — so a gesture built on pointers is taken away halfway
 * through every time, and the `preventDefault()` that was supposed to hold
 * on to it does nothing: by the time a `pointermove` runs the scroll is
 * already the compositor's. The first `touchmove` is the one moment the
 * page can still claim the gesture, and claiming it is all this does.
 *
 * Chrome on Android needs `overscroll-behavior-y: none` on top of that to
 * keep its own pull to refresh out of the way; iOS Safari has none of its
 * own, so there this replaces the rubber band.
 */
export default (options = {}) => ({
    threshold: options.threshold ?? 72,
    max: options.max ?? 110,
    /** How far the finger travels before the pull is a pull and not a tap. */
    slop: options.slop ?? 8,

    distance: 0,
    refreshing: false,

    /** The handlers `init` wires up by hand, kept so `destroy` can undo it. */
    listeners: {},

    /** The gesture could still become a pull. */
    tracking: false,
    /** It did, and the indicator is following the finger. */
    owning: false,
    startX: 0,
    startY: 0,

    /**
     * Registered by hand rather than with x-on, because `touchmove` has to
     * be able to preventDefault and a listener is passive unless it says
     * otherwise — which Alpine has no modifier for. The rest say so too,
     * rather than leaving the browser to guess. Touch events stay with the
     * element the gesture began on, so a pull that starts anywhere inside
     * the page reaches this to the end.
     */
    init() {
        this.listeners = {
            touchstart: (event) => this.start(event),
            touchmove: (event) => this.move(event),
            touchend: () => this.end(),
            touchcancel: () => this.reset(),
        };

        for (const [type, handler] of Object.entries(this.listeners)) {
            this.$root.addEventListener(type, handler, {
                passive: type !== "touchmove",
            });
        }
    },

    destroy() {
        for (const [type, handler] of Object.entries(this.listeners)) {
            this.$root.removeEventListener(type, handler);
        }
    },

    get scroller() {
        return document.scrollingElement ?? document.documentElement;
    },

    get progress() {
        return Math.min(this.distance / this.threshold, 1);
    },

    /**
     * A second finger is a pinch, a scrolled page is somebody reading, and
     * a drag inside an overlay belongs to the overlay: Flux paints its
     * sheets in the top layer but leaves them descendants of this element,
     * so their touches bubble down here and would refresh the page behind
     * an open sheet.
     *
     * Both overlay shapes this app writes are named, because the two are
     * not the same element: Flux renders a native `<dialog>`, while the
     * photo viewer is a plain `aria-modal` div that escapes today only by
     * teleporting itself out of this subtree.
     */
    start(event) {
        const touch = event.touches[0];

        this.tracking =
            !this.refreshing &&
            event.touches.length === 1 &&
            this.scroller.scrollTop <= 0 &&
            event.target.closest("dialog, [aria-modal='true']") === null;

        this.owning = false;
        this.startX = touch.clientX;
        this.startY = touch.clientY;
    },

    /**
     * The frame that decides, and there is only one of it. A move that
     * goes by without `preventDefault()` hands the gesture to the browser
     * for good: measured in Chromium, every touchmove after the first
     * unclaimed one arrives uncancelable. So the pull is claimed on the
     * very first move, well before the slop — which only decides when the
     * indicator starts following the finger, not who owns the gesture.
     *
     * Sideways has to be ruled out in the same breath, because claiming
     * early means claiming before the direction is obvious. The pulse
     * strip scrolls horizontally and sits at the top of the page, where
     * every pull begins, so a swipe along it is the one gesture that would
     * otherwise be taken for a pull and blocked.
     */
    move(event) {
        if (!this.tracking) return;

        const touch = event.touches[0];
        const delta = touch.clientY - this.startY;

        // Upwards, sideways, or a page that moved under the finger: a
        // scroll, and it stays one for the rest of the gesture. Only worth
        // asking until the pull is owned, and the `scrollTop` read is why
        // — once owned, the indicator has written to the DOM and reading
        // the scroll offset back would force a layout every frame.
        if (
            !this.owning &&
            (delta <= 0 ||
                Math.abs(touch.clientX - this.startX) > delta ||
                this.scroller.scrollTop > 0)
        ) {
            this.tracking = false;

            return;
        }

        this.owning ||= delta >= this.slop;

        // Resistance, so the indicator never tracks the finger one to one.
        // A finger that comes back up past where it started leaves the
        // indicator at rest rather than dragging it off the top.
        if (this.owning) {
            this.distance = Math.min(this.max, Math.max(0, delta) * 0.5);
        }

        if (event.cancelable) event.preventDefault();
    },

    async end() {
        // `owning` is not redundant with the distance: a finger that lands
        // and lifts while a refresh is already running finds `distance`
        // parked at the threshold, and would start a second one.
        const shouldRefresh = this.owning && this.distance >= this.threshold;

        if (!shouldRefresh) return this.reset();

        this.tracking = false;
        this.owning = false;

        this.refreshing = true;
        this.distance = this.threshold;

        try {
            // `$refresh` re-renders the page component only: the feed is
            // a nested component, and Livewire skips children on a parent
            // render, so it gets its own nudge. The two requests pool
            // into one round trip.
            window.Livewire.dispatchTo("feed", "mark-updated");
            await this.$wire.$refresh();
        } catch {
            // A pull with no signal behind it, or a session that expired
            // while the phone was in a pocket. Nothing here is listening for
            // the reason: the indicator has to come back up either way, and
            // an unawaited listener that rejects is an unhandled rejection.
        } finally {
            this.refreshing = false;
            this.reset();
        }
    },

    reset() {
        this.distance = 0;
        this.tracking = false;
        this.owning = false;
    },
});
