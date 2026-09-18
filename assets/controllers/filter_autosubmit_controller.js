import { Controller } from "@hotwired/stimulus";

/*
 * Auto-submit the catalogue filter form when a facet checkbox toggles.
 *
 * Mounted on the filter `<form>` (`data-controller="filter-autosubmit"`)
 * with `data-action="change->filter-autosubmit#submit"`. Change events
 * bubble from the descendant inputs, so a single action on the form
 * catches every facet checkbox. Only checkbox changes auto-submit — the
 * free-text search input still submits on Enter or via the Apply button,
 * so typing doesn't fire a request on every keystroke-driven change.
 *
 * Progressive enhancement: without JS the Apply button submits the form
 * as usual, so the filters work either way.
 */
export default class extends Controller {
    submit(event) {
        if (event.target.type !== "checkbox") {
            return;
        }

        if (typeof this.element.requestSubmit === "function") {
            this.element.requestSubmit();
        } else {
            this.element.submit();
        }
    }
}
