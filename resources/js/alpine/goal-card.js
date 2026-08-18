import pressGesture from "./press-gesture";

/**
 * A goal card's tap and hold.
 *
 * The press carries the state the card was showing, which is what tells a
 * deliberate un-mark apart from the second half of a double tap.
 */
export default (options = {}) => ({
    ...pressGesture(options),

    press() {
        this.$wire.press(this.$root.getAttribute("aria-pressed") === "true");
    },
});
