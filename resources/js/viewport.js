/**
 * The screen as it is actually visible, published for the whole page.
 *
 * A software keyboard shrinks the visual viewport and leaves the layout one
 * alone, so a full-screen `fixed` surface keeps its height and puts its own
 * bottom row — the box somebody is typing into — behind the keys typing into
 * it. `interactive-widget=resizes-content` is the meta tag that would say
 * otherwise and Safari ignores it, so the numbers are measured here instead.
 *
 * On the document rather than inside the component that wanted them first,
 * for the reason `protectMedia` gives: this is one fact about the window, and
 * every surface with a field at its bottom edge asks the same question about
 * it. Two custom properties and an attribute are the whole interface — a
 * surface reads them in CSS, and nothing here has to know which surfaces
 * those are.
 *
 * Nothing is published until the viewport first moves. Until then the CSS
 * fallbacks are exactly right — the visible screen is the window, and no
 * keyboard is covering anything — and measuring at load would force a
 * whole-page layout on the way to first paint to say so.
 */

/**
 * How much of the screen has to go before a keyboard is what took it. The
 * browser's own bar collapses and expands by fifty or so pixels with nobody
 * typing, and no keyboard on any phone is that short.
 */
const KEYBOARD = 150;

export default function trackViewport() {
    const viewport = window.visualViewport;

    if (!viewport) return;

    const publish = () => {
        const root = document.documentElement;

        root.style.setProperty("--viewport-height", `${viewport.height}px`);

        // iOS answers a focus near the bottom of a fixed surface by sliding
        // the visual viewport up inside the layout one, and a surface that
        // does not follow ends up scrolled half off the screen.
        root.style.setProperty("--viewport-top", `${viewport.offsetTop}px`);

        // `innerHeight` is the screen with nothing covering it: the keyboard
        // shrinks the visual viewport and leaves that one alone.
        root.toggleAttribute(
            "data-keyboard",
            viewport.height < window.innerHeight - KEYBOARD,
        );
    };

    viewport.addEventListener("resize", publish);
    viewport.addEventListener("scroll", publish);
}
