/**
 * Reacting to someone else's card.
 *
 * The bar has one way in, the ＋ beside the summary. Holding the card
 * opened it too until a photo became something you could open full screen:
 * the same press was then two features, and which one you got depended on
 * how long your finger stayed down.
 */
export default (options = {}) => ({
    markId: options.markId,

    showing: false,

    choose(emoji) {
        this.close();

        if (navigator.vibrate) navigator.vibrate(8);

        this.$wire.react(this.markId, emoji);
    },

    toggle() {
        this.showing = !this.showing;

        // One bar at a time: the others hear this and shut.
        if (this.showing) {
            this.$dispatch("reaction-bar-opened", this.markId);
        }
    },

    close() {
        this.showing = false;
    },
});
