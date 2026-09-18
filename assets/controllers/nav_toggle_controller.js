import { Controller } from "@hotwired/stimulus";

/*
 * Mobile navigation toggle.
 *
 * Mounted on the header container (`data-controller="nav-toggle"`).
 * The toggle button uses `data-action="click->nav-toggle#toggle"` and
 * the nav element is the `menu` target. Flips `aria-expanded` on the
 * button and the `hidden` utility class on the menu so screen readers
 * and the CSS toggle effect stay in sync.
 */
export default class extends Controller {
    static targets = ["menu"];

    toggle(event) {
        const button = event.currentTarget;
        const expanded = button.getAttribute("aria-expanded") === "true";
        button.setAttribute("aria-expanded", String(!expanded));

        if (!this.hasMenuTarget) {
            return;
        }
        this.menuTarget.classList.toggle("hidden", expanded);
        this.menuTarget.classList.toggle("flex", !expanded);
    }
}
