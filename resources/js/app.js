/**
 * Logralo's browser-side behaviour.
 *
 * Alpine ships inside Livewire's bundle, so nothing can register at import
 * time — `alpine:init` is the first moment `Alpine` exists. What registers is
 * a file per component under `alpine/`, which is the shape Alpine documents
 * for a bundled site and the reason this file stays a table of contents: the
 * name a Blade template says out loud, next to the module it resolves to.
 */

import { compressPhoto } from "./compress-photo";
import dictation from "./alpine/dictation";
import goalCard from "./alpine/goal-card";
import photoPicker from "./alpine/photo-picker";
import photoViewer from "./alpine/photo-viewer";
import pressGesture from "./alpine/press-gesture";
import pullToRefresh from "./alpine/pull-to-refresh";
import reactionPicker from "./alpine/reaction-picker";
import shareCard from "./alpine/share-card";

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
    Alpine.data("longPress", pressGesture);
    Alpine.data("goalCard", goalCard);
    Alpine.data("photoPicker", photoPicker);
    Alpine.data("shareCard", shareCard);
    Alpine.data("reactionPicker", reactionPicker);
    Alpine.data("photoViewer", photoViewer);
    Alpine.data("pullToRefresh", pullToRefresh);
    Alpine.data("dictation", dictation);
});
