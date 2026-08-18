import { compressPhoto } from "../compress-photo";

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
export default (options = {}) => ({
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
});
