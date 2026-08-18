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
export default (options = {}) => ({
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
});
