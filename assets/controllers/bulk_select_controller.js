import { Controller } from "@hotwired/stimulus";

/*
 * Header "select all" checkbox for the admin bulk-action tables.
 *
 * Mounted on the surrounding `<form>` with a `toggle` target (the
 * header checkbox) and one `item` target per row. Toggling the header
 * mirrors its state onto every row; toggling a row refreshes the
 * header, which shows the indeterminate state whenever the selection
 * is partial so the operator can tell "some" from "none" at a glance.
 *
 * Progressive enhancement: without JS the header checkbox does
 * nothing and rows are still selected individually, so the form
 * submits the same either way.
 */
export default class extends Controller {
    static targets = ["toggle", "item"];

    connect() {
        this.refresh();
    }

    toggleAll() {
        const checked = this.toggleTarget.checked;
        this.itemTargets.forEach((item) => {
            item.checked = checked;
        });
        this.refresh();
    }

    refresh() {
        if (!this.hasToggleTarget) {
            return;
        }

        const selected = this.itemTargets.filter((item) => item.checked).length;
        this.toggleTarget.checked =
            selected > 0 && selected === this.itemTargets.length;
        this.toggleTarget.indeterminate =
            selected > 0 && selected < this.itemTargets.length;
    }
}
