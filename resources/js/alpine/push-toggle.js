/**
 * The one control that turns Web Push on for this browser.
 *
 * Per browser, not per member: the same person on a phone and on a laptop is
 * two subscriptions, and this only ever speaks for the one it is running in.
 * The browser is what actually holds the subscription, so it is read on every
 * init rather than tracked server-side, and the server row is what our end
 * sends to.
 *
 * The states it can be in are the states iOS forces on us. Safari exposes
 * neither Notification nor PushManager to a tab, only to a web app on the
 * home screen, so "install it first" is a real answer and not a fallback.
 */
const supported = () =>
    "serviceWorker" in navigator &&
    "PushManager" in window &&
    "Notification" in window;

const installed = () =>
    window.matchMedia("(display-mode: standalone)").matches ||
    window.navigator.standalone === true;

/**
 * `applicationServerKey` wants the raw bytes of the VAPID public key, and what
 * we hold is the base64url the generator printed.
 */
const decodeKey = (key) => {
    const padded = (key + "=".repeat((4 - (key.length % 4)) % 4))
        .replace(/-/g, "+")
        .replace(/_/g, "/");

    return Uint8Array.from(atob(padded), (character) =>
        character.charCodeAt(0),
    );
};

export default (publicKey) => ({
    publicKey,
    status: "loading",
    busy: false,
    error: "",

    async init() {
        await this.refresh();
    },

    async refresh() {
        this.error = "";

        if (this.publicKey === "") {
            this.status = "unconfigured";

            return;
        }

        if (!supported()) {
            this.status = installed() ? "unsupported" : "needs-install";

            return;
        }

        if (Notification.permission === "denied") {
            this.status = "denied";

            return;
        }

        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();

        this.status = subscription ? "on" : "off";
    },

    async enable() {
        this.busy = true;
        this.error = "";

        try {
            // Has to be asked for from a tap. A permission prompt raised any
            // other way is refused outright on iOS.
            const permission = await Notification.requestPermission();

            if (permission !== "granted") {
                await this.refresh();

                return;
            }

            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: decodeKey(this.publicKey),
            });

            await this.$wire.subscribeToPush(subscription.toJSON());
            this.status = "on";
        } catch {
            this.error = "No pudimos activarlas. Probá de nuevo.";
            await this.refresh();
        } finally {
            this.busy = false;
        }
    },

    async disable() {
        this.busy = true;
        this.error = "";

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription =
                await registration.pushManager.getSubscription();

            if (subscription) {
                // Told before it is dropped: once the browser has forgotten
                // the endpoint there is nothing left to name the row with.
                await this.$wire.unsubscribeFromPush(subscription.endpoint);
                await subscription.unsubscribe();
            }

            this.status = "off";
        } catch {
            this.error = "No pudimos desactivarlas. Probá de nuevo.";
            await this.refresh();
        } finally {
            this.busy = false;
        }
    },
});
