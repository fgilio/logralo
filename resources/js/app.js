/**
 * Logralo's browser-side behaviour. Alpine ships inside Livewire's bundle, so
 * everything here registers on `alpine:init`.
 */

import { compressPhoto } from "./compress-photo";

/**
 * The resize is the one piece of logic here with no server behind it, and the
 * browser suite cannot reach it through an upload: Pest's test server builds
 * its Laravel request with an empty files array, so a multipart body never
 * becomes a `$_FILES` entry and no upload can complete. This is the seam the
 * test drives instead.
 */
// Merged, not assigned: the head has already put the resize budget here.
window.Logralo = { ...window.Logralo, compressPhoto };

document.addEventListener("alpine:init", () => {
    /**
     * The half of a press and hold that every gesture here shares: arm a timer
     * on pointerdown, drop it when the finger travels, and swallow the click
     * the press emits afterwards so a card never fires both.
     *
     * Whoever spreads this in owes it a `cancel()`.
     */
    const holdGesture = (options = {}) => ({
        delay: options.delay ?? 420,
        moveTolerance: options.moveTolerance ?? 10,

        timer: null,
        pointerId: null,
        startX: 0,
        startY: 0,
        suppressClick: false,
        onScroll: null,

        destroy() {
            this.cancel();
        },

        /**
         * A scroll begun elsewhere steals the gesture on iOS without ever
         * firing pointercancel. The listener lives only as long as the press
         * does, so a page of cards does not run one per card per frame.
         */
        arm(event) {
            this.pointerId = event.pointerId;
            this.startX = event.clientX;
            this.startY = event.clientY;
            this.suppressClick = false;

            this.onScroll ??= () => this.cancel();
            window.addEventListener("scroll", this.onScroll, {
                capture: true,
                passive: true,
            });
        },

        disarm() {
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

    /**
     * Tap and hold on the same element. Announces itself with `long-press` and
     * `short-press`, and leaves the deciding to the card.
     */
    const pressGesture = (options = {}) => ({
        ...holdGesture(options),

        fired: false,
        pressing: false,

        start(event) {
            if (event.button !== undefined && event.button !== 0) return;
            if (this.pointerId !== null) return this.cancel();

            this.arm(event);
            this.fired = false;
            this.pressing = true;

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
            this.disarm();
            this.pressing = false;
        },
    });

    Alpine.data("longPress", pressGesture);

    /**
     * A goal card's tap and hold.
     *
     * The press carries the state the card was showing, which is what tells a
     * deliberate un-mark apart from the second half of a double tap.
     */
    Alpine.data("goalCard", (options = {}) => ({
        ...pressGesture(options),

        press() {
            this.$wire.press(
                this.$root.getAttribute("aria-pressed") === "true",
            );
        },
    }));

    /**
     * The camera button, and what happens between the shutter and the upload.
     *
     * The input is deliberately not on `wire:model`: that uploads whatever the
     * camera produced the instant the change event fires, and a 48 MP original
     * is both a minute of mobile data and 190 MB the moment PHP decodes it. So
     * the change handler shrinks the file first (see `compress-photo.js`) and
     * then hands the result to Livewire by hand.
     *
     * `busy` covers both halves, which is the other reason this replaced
     * `wire:loading`: that only knows about the upload, and the resize in
     * front of it is the slower of the two on an older phone.
     *
     * `options` carries only what differs between the two pickers on this
     * site: `property`, the Livewire property the finished file lands on, and
     * `then`, a component method to call once the bytes are up, for the picker
     * with no save button behind it — the avatar applies on pick, where a mark
     * waits for the note to be written.
     *
     * The resize budget is not among them. It comes off `window.Logralo.photo`,
     * which the head renders from `config/logralo.php`, so the budget and the
     * quality exist there and as fallbacks in `compress-photo.js`, and in no
     * third place that could drift from either.
     */
    Alpine.data("photoPicker", (options = {}) => ({
        options: { ...(window.Logralo?.photo ?? {}), ...options },

        busy: false,
        progress: 0,

        /**
         * Clearing the value first is what makes retaking the same photo
         * work: an input handed an identical file fires no change event.
         *
         * The busy guard is not just an affordance. The overlay lives inside
         * the camera button, so a tap on the spinner reaches this — and a
         * second `pick()` would race the first, with whichever upload landed
         * earliest calling `settle()` and freeing the save button while the
         * retake was still in flight.
         */
        open() {
            if (this.busy) return;

            this.$refs.camera.value = "";
            this.$refs.camera.click();
        },

        async pick(event) {
            const file = event.target.files?.[0];

            if (!file) return;

            this.busy = true;
            this.progress = 0;

            try {
                const upload = await compressPhoto(file, this.options);

                this.$wire.upload(
                    this.options.property ?? "photo",
                    upload,
                    () => {
                        this.settle();

                        if (this.options.then) this.$wire[this.options.then]();
                    },
                    () => this.settle(),
                    (sent) => (this.progress = sent.detail.progress),
                );
            } catch {
                // Whatever went wrong, it did not leave a photo behind — and it
                // must not leave a spinner sitting on top of the camera either.
                this.settle();
            }
        },

        settle() {
            this.busy = false;
            this.progress = 0;
        },
    }));

    /**
     * Sharing a card to WhatsApp.
     *
     * A tap sends the link, and the link's own `og:image` is what draws the
     * photo in the chat. That is the whole trick: the app used to have to
     * choose between sending a picture with no context and sending text over a
     * dead root URL, because `navigator.share` drops the caption when a file
     * comes with it. Handing the unfurl the picture ends the choice.
     *
     * Holding sends the composed image itself instead, for a story post or for
     * anywhere a preview will not render. That payload is fetched when the
     * hold opens the menu, not when the sheet is asked for: an `await` between
     * the gesture and `navigator.share()` spends the transient activation on
     * iOS and the sheet never opens, so the file has to be in hand already.
     * The menu's dwell time is what pays for it, and a plain tap — which never
     * sends a file — never downloads one.
     */
    Alpine.data("shareCard", (options = {}) => ({
        url: options.url ?? window.location.origin,
        text: options.text ?? "",
        cardUrl: options.cardUrl ?? null,
        imageUrl: options.imageUrl ?? null,
        filename: options.filename ?? "logralo.jpg",

        file: null,
        warmed: false,
        busy: false,

        /**
         * Render the unfurl image before WhatsApp asks for it.
         *
         * Cards are composed on first request and cached, so without this the
         * very first crawl pays for the compositing — and a preview that comes
         * back slowly is a preview the sender's client gives up on, leaving a
         * bare link in the chat.
         */
        warm() {
            if (this.warmed || !this.cardUrl) return;

            this.warmed = true;
            fetch(this.cardUrl, { credentials: "same-origin" }).catch(() => {});
        },

        /** The tall card, ready to hand to the share sheet as a file. */
        async prefetch() {
            if (this.file || !this.imageUrl || !navigator.canShare) return;

            try {
                const response = await fetch(this.imageUrl, {
                    credentials: "same-origin",
                });
                const blob = await response.blob();
                const file = new File([blob], this.filename, {
                    type: blob.type,
                });

                // iOS answers false for mixed payloads, so probe files alone.
                if (navigator.canShare({ files: [file] })) this.file = file;
            } catch {
                this.file = null;
            }
        },

        /** One tap: the link, and the preview it carries. */
        async share() {
            if (this.busy) return;
            this.busy = true;

            try {
                await this.sendLink();
            } finally {
                this.busy = false;
            }
        },

        /** Hold, then "Enviar la imagen": the picture on its own. */
        async shareImage() {
            if (this.busy) return;
            this.busy = true;

            try {
                // Nothing to attach — a browser without file sharing, or a
                // card that has not finished rendering. Falls back to the link,
                // which is why this calls sendLink() rather than share():
                // share() would trip over the busy flag this method just set
                // and silently do nothing at all.
                if (!this.file) {
                    await this.sendLink();

                    return;
                }

                await navigator.share({ title: "", files: [this.file] });
            } catch (error) {
                if (error?.name === "AbortError") return;

                await this.fallback();
            } finally {
                this.busy = false;
            }
        },

        /** The share itself, with no guard of its own. */
        async sendLink() {
            try {
                if (navigator.share) {
                    await navigator.share({ text: this.text, url: this.url });

                    return;
                }

                await this.fallback();
            } catch (error) {
                if (error?.name === "AbortError") return;

                await this.fallback();
            }
        },

        /** What goes in the chat when the share sheet is not what happens. */
        get message() {
            return `${this.text} ${this.url}`.trim();
        },

        async copy() {
            try {
                await navigator.clipboard.writeText(this.message);
                window.Flux?.toast?.("Copiado 📋");
            } catch {
                await this.fallback();
            }
        },

        async fallback() {
            if (navigator.clipboard?.writeText) {
                try {
                    await navigator.clipboard.writeText(this.message);
                    window.Flux?.toast?.("Copiado. Pegalo en WhatsApp 📋");

                    return;
                } catch {
                    // Fall through to the deep link.
                }
            }

            window.open(
                `https://wa.me/?text=${encodeURIComponent(this.message)}`,
                "_blank",
                "noopener",
            );
        },
    }));

    /**
     * Reacting to someone else's card.
     *
     * The bar has one way in, the ＋ beside the summary. Holding the card
     * opened it too until a photo became something you could open full screen:
     * the same press was then two features, and which one you got depended on
     * how long your finger stayed down.
     */
    Alpine.data("reactionPicker", (options = {}) => ({
        markId: options.markId,

        showing: false,

        choose(emoji) {
            this.close();

            if (navigator.vibrate) navigator.vibrate(8);

            this.$wire.react(this.markId, emoji);
        },

        toggle() {
            if (this.showing) {
                this.close();

                return;
            }

            this.open();
        },

        /** One bar at a time: the others hear this and shut. */
        open() {
            this.showing = true;
            this.$dispatch("reaction-bar-opened", this.markId);
        },

        close() {
            this.showing = false;
        },
    }));

    /**
     * The photo, full screen.
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
    Alpine.data("photoViewer", (options = {}) => ({
        name: options.name,
        threshold: options.threshold ?? 96,
        tapTolerance: options.tapTolerance ?? 10,

        pointerId: null,
        startX: 0,
        startY: 0,
        x: 0,
        y: 0,
        dragging: false,

        /** How far the picture has been carried from where it started. */
        get travelled() {
            return Math.hypot(this.x, this.y);
        },

        /** How far gone it looks while it is being thrown away. */
        get faded() {
            return 1 - Math.min(this.travelled / 480, 0.75);
        },

        start(event) {
            if (event.button !== undefined && event.button !== 0) return;

            this.pointerId = event.pointerId;
            this.startX = event.clientX;
            this.startY = event.clientY;
            this.dragging = true;

            try {
                event.currentTarget.setPointerCapture(event.pointerId);
            } catch {
                // Capture is a nicety; the pointerup still lands on the photo.
            }
        },

        move(event) {
            if (!this.dragging || event.pointerId !== this.pointerId) return;

            this.x = event.clientX - this.startX;
            this.y = event.clientY - this.startY;
        },

        end(event) {
            if (!this.dragging || event.pointerId !== this.pointerId) return;

            const travelled = this.travelled;

            this.cancel();

            // A tap and a throw both mean the same thing. Everything between
            // the two was a drag that changed its mind, and springs back.
            if (travelled <= this.tapTolerance || travelled > this.threshold) {
                this.dismiss();
            }
        },

        cancel() {
            this.dragging = false;
            this.pointerId = null;
            this.x = 0;
            this.y = 0;
        },

        dismiss() {
            this.cancel();
            window.Flux?.modal(this.name).close();
        },
    }));

    /**
     * Pull to refresh over the feed.
     *
     * Chrome on Android hands the gesture back once `overscroll-behavior-y` is
     * `none`; iOS Safari has no native pull to refresh at all, so this rides on
     * top of the rubber band and arms only at the very top of the page.
     */
    Alpine.data("pullToRefresh", (options = {}) => ({
        threshold: options.threshold ?? 72,
        max: options.max ?? 110,

        distance: 0,
        armed: false,
        refreshing: false,
        startY: 0,
        pointerId: null,

        init() {
            // Registered by hand rather than with x-on, because Alpine's
            // .passive is required for touch performance yet forbids the
            // preventDefault this needs.
            this.onMove = (event) => this.move(event);
            window.addEventListener("pointermove", this.onMove, {
                passive: false,
            });
        },

        destroy() {
            window.removeEventListener("pointermove", this.onMove);
        },

        get scroller() {
            return document.scrollingElement ?? document.documentElement;
        },

        get progress() {
            return Math.min(this.distance / this.threshold, 1);
        },

        start(event) {
            if (this.refreshing || this.scroller.scrollTop > 0) return;

            this.pointerId = event.pointerId;
            this.startY = event.clientY;
            this.armed = true;
        },

        move(event) {
            if (!this.armed || event.pointerId !== this.pointerId) return;

            const delta = event.clientY - this.startY;

            if (delta <= 0 || this.scroller.scrollTop > 0) return this.reset();

            // Resistance, so the indicator never tracks the finger one to one.
            this.distance = Math.min(this.max, delta * 0.5);

            if (event.cancelable) event.preventDefault();
        },

        async end() {
            if (!this.armed) return;

            const shouldRefresh = this.distance >= this.threshold;
            this.armed = false;

            if (!shouldRefresh) return this.reset();

            this.refreshing = true;
            this.distance = this.threshold;

            try {
                // `$refresh` re-renders the page component only: the feed is
                // a nested component, and Livewire skips children on a parent
                // render, so it gets its own nudge. The two requests pool
                // into one round trip.
                window.Livewire.dispatchTo("feed", "mark-updated");
                await this.$wire.$refresh();
            } finally {
                this.refreshing = false;
                this.reset();
            }
        },

        reset() {
            this.distance = 0;
            this.armed = false;
            this.pointerId = null;
        },
    }));
});
