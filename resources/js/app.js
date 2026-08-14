/**
 * Logralo's browser-side behaviour. Alpine ships inside Livewire's bundle, so
 * everything here registers on `alpine:init` and nothing is imported.
 */

document.addEventListener("alpine:init", () => {
    /**
     * Tap and hold on the same element, without a library.
     *
     * Pointer events only, so touch, mouse and pen take one code path. A drag
     * past the tolerance cancels the press, which is what stops a scroll from
     * turning into a long press, and the click that a long press emits
     * afterwards is swallowed so a card never fires both.
     */
    Alpine.data("longPress", (options = {}) => ({
        delay: options.delay ?? 420,
        moveTolerance: options.moveTolerance ?? 10,

        timer: null,
        pointerId: null,
        startX: 0,
        startY: 0,
        fired: false,
        suppressClick: false,
        pressing: false,

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

            const movedX = Math.abs(event.clientX - this.startX);
            const movedY = Math.abs(event.clientY - this.startY);

            if (movedX > this.moveTolerance || movedY > this.moveTolerance)
                this.cancel();
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
     * Sharing a card to WhatsApp.
     *
     * A tap sends the link, and the link's own `og:image` is what draws the
     * photo in the chat. That is the whole trick: the app used to have to
     * choose between sending a picture with no context and sending text over a
     * dead root URL, because `navigator.share` drops the caption when a file
     * comes with it. Handing the unfurl the picture ends the choice.
     *
     * Holding sends the composed image itself instead, for a story post or for
     * anywhere a preview will not render. That payload is fetched on
     * pointerdown, because an `await` between the gesture and
     * `navigator.share()` spends the transient activation on iOS and the sheet
     * never opens.
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
            this.warm();

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
                if (navigator.share) {
                    await navigator.share({ text: this.text, url: this.url });

                    return;
                }

                await this.fallback();
            } catch (error) {
                if (error?.name === "AbortError") return;

                await this.fallback();
            } finally {
                this.busy = false;
            }
        },

        /** Hold: the picture on its own. */
        async shareImage() {
            if (this.busy) return;
            this.busy = true;

            try {
                if (this.file) {
                    await navigator.share({ title: "", files: [this.file] });

                    return;
                }

                // Nothing to attach — a browser without file sharing, or a
                // card that has not finished rendering. The link still works.
                await this.share();
            } catch (error) {
                if (error?.name === "AbortError") return;

                await this.fallback();
            } finally {
                this.busy = false;
            }
        },

        async copy() {
            const message = `${this.text} ${this.url}`.trim();

            try {
                await navigator.clipboard.writeText(message);
                window.Flux?.toast?.("Copiado 📋");
            } catch {
                await this.fallback();
            }
        },

        async fallback() {
            const message = `${this.text} ${this.url}`.trim();

            if (navigator.clipboard?.writeText) {
                try {
                    await navigator.clipboard.writeText(message);
                    window.Flux?.toast?.("Copiado. Pegalo en WhatsApp 📋");

                    return;
                } catch {
                    // Fall through to the deep link.
                }
            }

            window.open(
                `https://wa.me/?text=${encodeURIComponent(message)}`,
                "_blank",
                "noopener",
            );
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
