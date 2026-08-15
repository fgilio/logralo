/**
 * Logralo's browser-side behaviour. Alpine ships inside Livewire's bundle, so
 * everything here registers on `alpine:init` and nothing is imported.
 */

document.addEventListener("alpine:init", () => {
    /**
     * The half of a press and hold that every gesture here shares: arm a timer
     * on pointerdown, drop it when the finger travels, and swallow the click
     * the press emits afterwards so a card never fires both.
     *
     * Pointer events only, so touch, mouse and pen take one code path. Whoever
     * spreads this in owes it a `cancel()`.
     */
    const holdGesture = (options = {}) => ({
        delay: options.delay ?? 420,
        moveTolerance: options.moveTolerance ?? 10,

        timer: null,
        pointerId: null,
        startX: 0,
        startY: 0,
        suppressClick: false,

        init() {
            // On iOS a scroll begun elsewhere can steal the gesture without
            // ever firing pointercancel.
            this.onScroll = () => this.cancel();
            window.addEventListener("scroll", this.onScroll, {
                capture: true,
                passive: true,
            });
        },

        destroy() {
            window.removeEventListener("scroll", this.onScroll, {
                capture: true,
            });
            this.clearTimer();
        },

        /** A finger that travels this far was scrolling, not holding. */
        travelled(event) {
            return (
                Math.abs(event.clientX - this.startX) > this.moveTolerance ||
                Math.abs(event.clientY - this.startY) > this.moveTolerance
            );
        },

        clearTimer() {
            if (this.timer !== null) {
                clearTimeout(this.timer);
                this.timer = null;
            }
        },

        onClick(event) {
            if (!this.suppressClick) return;

            this.suppressClick = false;
            event.preventDefault();
            event.stopPropagation();
        },
    });

    /**
     * Tap and hold on the same element, without a library. Announces itself
     * with `long-press` and `short-press`, and leaves the deciding to the card.
     */
    Alpine.data("longPress", (options = {}) => ({
        ...holdGesture(options),

        fired: false,
        pressing: false,

        start(event) {
            if (event.button !== undefined && event.button !== 0) return;
            if (this.pointerId !== null) return this.cancel();

            this.pointerId = event.pointerId;
            this.startX = event.clientX;
            this.startY = event.clientY;
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

            this.clearTimer();
            this.pressing = false;
            this.pointerId = null;

            if (wasArmed && !this.fired) this.$dispatch("short-press");
        },

        cancel() {
            this.clearTimer();
            this.pressing = false;
            this.pointerId = null;
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
     * Two ways into one bar: the ＋ button opens it, and holding the card opens
     * it under the finger, which can then slide onto an emoji and let go. The
     * hit test runs on coordinates rather than on hover, because a touch
     * pointer captured by the card never fires pointerenter on the bar.
     *
     * The bar is a selector, not a set of checkboxes: one reaction per member
     * per mark, and choosing the current one takes it away.
     */
    Alpine.data("reactionPicker", (options = {}) => ({
        ...holdGesture({ delay: 380, ...options }),

        markId: options.markId ?? null,

        showing: false,
        active: null,

        press(event) {
            if (event.button !== undefined && event.button !== 0) return;
            if (this.pointerId !== null) return this.cancel();

            this.pointerId = event.pointerId;
            this.startX = event.clientX;
            this.startY = event.clientY;

            this.timer = setTimeout(() => {
                this.timer = null;
                this.showing = true;
                // The click this press will emit belongs to the gesture, not to
                // whatever is underneath it.
                this.suppressClick = true;

                if (navigator.vibrate) navigator.vibrate(12);
            }, this.delay);
        },

        track(event) {
            if (this.pointerId === null || event.pointerId !== this.pointerId)
                return;

            // Still waiting on the timer: a finger that travels is a scroll.
            if (this.timer !== null) {
                if (this.travelled(event)) this.cancel();

                return;
            }

            if (event.cancelable) event.preventDefault();

            this.active = this.emojiAt(event.clientX, event.clientY);
        },

        release() {
            // Only a finger dragging over the bar ever sets `active`, so it is
            // both the choice and the proof that there was one.
            const chosen = this.active;

            this.cancel();

            // Letting go anywhere else leaves the bar open, so the tap path
            // still finishes what the hold started.
            if (chosen !== null) this.choose(chosen);
        },

        cancel() {
            this.clearTimer();
            this.pointerId = null;
            this.active = null;
        },

        emojiAt(x, y) {
            const element = document.elementFromPoint(x, y);

            return element?.closest("[data-emoji]")?.dataset.emoji ?? null;
        },

        choose(emoji) {
            this.close();

            if (this.markId) this.$wire.react(this.markId, emoji);
        },

        toggle() {
            this.showing = !this.showing;
            this.active = null;
        },

        close() {
            this.showing = false;
            this.active = null;
        },

        clearTimer() {
            if (this.timer !== null) {
                clearTimeout(this.timer);
                this.timer = null;
            }
        },

        onClick(event) {
            if (!this.suppressClick) return;

            this.suppressClick = false;
            event.preventDefault();
            event.stopPropagation();
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
