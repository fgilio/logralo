import pressGesture from "./press-gesture";

/**
 * A goal card's tap and hold.
 *
 * The press carries the state the card was showing, which is what lets a call
 * that lands after the card has already changed under it — the second half of
 * a double tap — be dropped rather than acted on.
 */
export default (options = {}) => ({
    ...pressGesture(options),

    press() {
        this.$wire.press(this.$root.getAttribute("aria-pressed") === "true");
    },
});
