/**
 * The gestures that save a photo, refused.
 *
 * "Pics or it didn't happen" is a promise made to one group of friends, and a
 * picture that leaves in one tap is not much of a promise. The browser offers
 * that tap in two places — a long press on Android, a right click on the
 * desktop — and both arrive here as the same `contextmenu`, which is
 * cancellable. The drag is the third way out and cancels the same way; iOS
 * takes its own path and is answered in CSS, next to the rule this pairs with.
 *
 * Both listeners sit on the document and match the element under the pointer,
 * so the feed can re-render whole and the viewer can fill itself from a
 * payload without either having to remember. Gifs are `<img>` and a gallery is
 * more of them, so those arrive covered. A `<video>` is matched too, but the
 * download button in the browser's own controls is out of reach of any event:
 * that one needs `controlslist="nodownload" disablepictureinpicture` on the
 * tag itself.
 *
 * A deterrent, not a lock. The URL is in the DOM, the network panel lists
 * every request, and every phone screenshots. This closes the save that
 * happens without thinking about it, which is the one that happens.
 */
const MEDIA = "img, picture, video";

const overMedia = (event) =>
    event.target instanceof Element && event.target.closest(MEDIA) !== null;

export default function protectMedia() {
    // Capture, so nothing between the media and the document can swallow the
    // event before it is refused.
    for (const gesture of ["contextmenu", "dragstart"]) {
        document.addEventListener(
            gesture,
            (event) => {
                if (overMedia(event)) event.preventDefault();
            },
            { capture: true },
        );
    }
}
