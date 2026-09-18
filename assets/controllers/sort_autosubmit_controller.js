import { Controller } from "@hotwired/stimulus";

/*
 * Auto-submit the catalogue sort form when the ordering changes.
 *
 * Mounted on the sort `<form>` (`data-controller="sort-autosubmit"`) with
 * `data-action="change->sort-autosubmit#submit"`. The form holds a single
 * `<select name="sort">` plus hidden inputs for the active filters, so a
 * change event always means the user picked a new ordering — submit
 * unconditionally.
 *
 * Progressive enhancement: without JS the `<noscript>` button submits the
 * form, so sorting works either way.
 */
export default class extends Controller {
    submit() {
        if (typeof this.element.requestSubmit === "function") {
            this.element.requestSubmit();
        } else {
            this.element.submit();
        }
    }
}
