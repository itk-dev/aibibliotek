import { Controller } from "@hotwired/stimulus";

/*
 * Authenticated-user dropdown menu in the top nav.
 *
 * Mounted on the menu wrapper (`data-controller="user-menu"`).
 * The trigger button is the `trigger` target; the menu panel is
 * the `menu` target.
 *
 * Behaviour follows the WAI-ARIA "Menu Button" pattern:
 * - Click the trigger to toggle the menu.
 * - Escape closes the menu and returns focus to the trigger.
 * - A click anywhere outside the menu closes it.
 * - `aria-expanded` on the trigger stays in sync with `hidden` on
 *   the menu so screen readers announce the open/closed state.
 */
export default class extends Controller {
    static targets = ["trigger", "menu"];

    toggle(event) {
        event.preventDefault();
        if (this.menuTarget.classList.contains("hidden")) {
            this.open();
        } else {
            this.close();
        }
    }

    open() {
        this.menuTarget.classList.remove("hidden");
        this.triggerTarget.setAttribute("aria-expanded", "true");
    }

    close() {
        if (this.menuTarget.classList.contains("hidden")) {
            return;
        }
        this.menuTarget.classList.add("hidden");
        this.triggerTarget.setAttribute("aria-expanded", "false");
        this.triggerTarget.focus();
    }

    closeOnOutsideClick(event) {
        if (this.element.contains(event.target)) {
            return;
        }
        if (!this.menuTarget.classList.contains("hidden")) {
            this.menuTarget.classList.add("hidden");
            this.triggerTarget.setAttribute("aria-expanded", "false");
        }
    }
}
