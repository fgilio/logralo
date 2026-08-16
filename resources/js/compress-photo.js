/**
 * Shrinking a camera original before it ever leaves the phone.
 *
 * A 48 MP sensor hands the file input roughly 190 MB of pixels, and the web
 * container has 512 MiB to decode them in — so the upload that kills the
 * container is one the server never gets to refuse politely. The cheapest
 * place to stop it is the device that took the photo, which has the picture
 * decoded already and nothing else to do.
 *
 * This is all platform, no library: `createImageBitmap` decodes off the main
 * thread and can resample on the way out, a canvas re-encodes, and
 * `convertToBlob` hands back a JPEG. The wasm codecs (jSquash's MozJPEG,
 * Squoosh, browser-image-compression's worker pool) buy maybe 15% smaller
 * files for ~600 KB of download on a phone that is already on mobile data,
 * and none of that matters here: the server re-encodes to 1080px anyway, so
 * the only number this file has to hit is "small enough to decode safely".
 *
 * Every failure path returns the original file. A photo that uploads slowly
 * is a worse day; a photo that never uploads is a broken app.
 */

/**
 * What is worth handing to the canvas. GIF and SVG are deliberately absent —
 * one would lose its animation and the other is not a raster image at all,
 * and neither comes out of a camera.
 *
 * An empty type is what some Android pickers report for a perfectly ordinary
 * JPEG, so it gets a try; the decode below is the real test.
 */
const RE_ENCODABLE = /^image\/(jpeg|png|webp|heic|heif|avif)$/i;

/**
 * Resize to fit `maxPixels` and re-encode as JPEG at `quality`.
 *
 * Returns the original file untouched when it is already a JPEG within
 * budget, when the result would not be smaller, or when anything at all goes
 * wrong. Anything else — a HEIC, a PNG, an over-budget JPEG — comes back as a
 * JPEG, which is also what stops a desktop Safari HEIC from reaching a
 * GD-only server that cannot read it.
 *
 * @param {File} file
 * @param {{maxPixels?: number, quality?: number}} options
 * @returns {Promise<File>}
 */
export async function compressPhoto(file, options = {}) {
    const maxPixels = options.maxPixels ?? 12_000_000;
    const quality = options.quality ?? 0.85;

    if (!(file instanceof Blob)) return file;
    if (file.type !== "" && !RE_ENCODABLE.test(file.type)) return file;

    let source = null;
    let resampled = null;

    try {
        // `from-image` is the spec default, but it was not always Chrome's,
        // and the canvas strips EXIF on the way out — so an orientation this
        // step declined to apply is one nothing downstream can recover, and
        // every portrait photo would reach the feed lying on its side.
        source = await createImageBitmap(file, {
            imageOrientation: "from-image",
        });

        const pixels = source.width * source.height;
        const scale = Math.min(1, Math.sqrt(maxPixels / pixels));

        // Already small enough, and already in the format the server wants:
        // re-encoding would only spend a generation of JPEG quality to make
        // the file marginally different.
        if (scale === 1 && file.type === "image/jpeg") return file;

        const width = Math.max(1, Math.round(source.width * scale));
        const height = Math.max(1, Math.round(source.height * scale));

        resampled = await resample(source, width, height);

        const blob = await encode(resampled ?? source, width, height, quality);

        if (blob === null || blob.size >= file.size) return file;

        return new File([blob], asJpegName(file.name), {
            type: "image/jpeg",
            lastModified: file.lastModified,
        });
    } catch {
        // An engine too old for one of these, a format it cannot decode, a
        // canvas the OS refused to allocate. The server still has its own
        // ceiling; this one was only ever the polite version.
        return file;
    } finally {
        source?.close();
        resampled?.close();
    }
}

/**
 * The downscale, done by the decoder rather than by us.
 *
 * `resizeQuality: "high"` is a proper filtered resample, which is what keeps
 * a 4× reduction from turning fine detail into aliasing. Older engines ignore
 * the resize members instead of refusing them, so the only way to know they
 * took is to measure what came back — and when they did not, `encode()` falls
 * back to letting `drawImage` scale, which every engine can do.
 *
 * @returns {Promise<ImageBitmap|null>} null when the caller should scale itself
 */
async function resample(source, width, height) {
    if (source.width === width && source.height === height) return null;

    try {
        const bitmap = await createImageBitmap(source, {
            resizeWidth: width,
            resizeHeight: height,
            resizeQuality: "high",
        });

        if (bitmap.width === width && bitmap.height === height) return bitmap;

        bitmap.close();
    } catch {
        // Fall through.
    }

    return null;
}

/** @returns {Promise<Blob|null>} */
async function encode(bitmap, width, height, quality) {
    const canvas = createCanvas(width, height);
    const context = canvas.getContext("2d", { alpha: false });

    if (context === null) return null;

    // JPEG has no alpha, and an opaque canvas starts out black — so a PNG
    // with a transparent corner would arrive with that corner blacked in.
    // White is what the server composites onto, so match it here.
    context.fillStyle = "#ffffff";
    context.fillRect(0, 0, width, height);
    context.imageSmoothingQuality = "high";
    context.drawImage(bitmap, 0, 0, width, height);

    return toBlob(canvas, quality);
}

/**
 * An `OffscreenCanvas` where there is one: no DOM node, and the browser is
 * free to drop the backing store the moment we let go of it. iOS Safari
 * counts every live canvas against one process-wide budget, and a feed of
 * photos is not the place to leave one lying around.
 */
function createCanvas(width, height) {
    if (typeof OffscreenCanvas === "function") {
        return new OffscreenCanvas(width, height);
    }

    const canvas = document.createElement("canvas");

    canvas.width = width;
    canvas.height = height;

    return canvas;
}

function toBlob(canvas, quality) {
    if (typeof canvas.convertToBlob === "function") {
        return canvas.convertToBlob({ type: "image/jpeg", quality });
    }

    return new Promise((resolve) => {
        canvas.toBlob(resolve, "image/jpeg", quality);
    });
}

/** The name follows the bytes: what we send is a JPEG, whatever came in was not. */
function asJpegName(name) {
    const base = String(name ?? "").replace(/\.[^./\\]*$/, "");

    return `${base || "foto"}.jpg`;
}
