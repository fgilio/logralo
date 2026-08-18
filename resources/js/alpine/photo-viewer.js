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
 * far enough from where it started and it goes away. A tap dismisses too,
 * which is what the viewer this replaced did with any click at all.
 *
 * The drag is measured in both axes and away from the start point rather
 * than down the screen: there is nothing to swipe sideways to, so a
 * sideways throw is the same intention as a downward one, and measuring
 * only the vertical would have counted it as a tap.
 *
 * Flux closes on a click outside the dialog's own box, and this dialog is
 * the whole screen, so `dismiss()` is the only way out besides Escape.
 */
export default () => ({
    threshold: 96,
    tapTolerance: 10,

    photo: {},
    pointerId: null,
    startX: 0,
    startY: 0,
    x: 0,
    y: 0,

    /** A pointer is down on the picture, so the drag owns the transform. */
    get dragging() {
        return this.pointerId !== null;
    },

    /**
     * Where the picture sits, and how far gone it looks on the way out.
     * One binding rather than two, so a pointermove costs one style write
     * and the distance is measured once.
     */
    get carried() {
        if (this.x === 0 && this.y === 0) return "";

        const faded = 1 - Math.min(Math.hypot(this.x, this.y) / 480, 0.75);

        return `transform: translate(${this.x}px, ${this.y}px); opacity: ${faded}; will-change: transform, opacity`;
    },

    show(photo) {
        this.cancel();
        this.photo = photo;

        window.Flux?.modal("foto").show();
    },

    start(event) {
        if (event.button !== undefined && event.button !== 0) return;

        this.pointerId = event.pointerId;
        this.startX = event.clientX;
        this.startY = event.clientY;

        try {
            event.currentTarget.setPointerCapture(event.pointerId);
        } catch {
            // Capture is a nicety; the pointerup still lands on the photo.
        }
    },

    move(event) {
        if (event.pointerId !== this.pointerId) return;

        this.x = event.clientX - this.startX;
        this.y = event.clientY - this.startY;
    },

    end(event) {
        if (event.pointerId !== this.pointerId) return;

        const travelled = Math.hypot(this.x, this.y);

        this.cancel();

        // A tap and a throw both mean the same thing. Everything between
        // the two was a drag that changed its mind, and springs back.
        if (travelled <= this.tapTolerance || travelled > this.threshold) {
            this.dismiss();
        }
    },

    cancel() {
        this.pointerId = null;
        this.x = 0;
        this.y = 0;
    },

    dismiss() {
        window.Flux?.modal("foto").close();
    },
});
