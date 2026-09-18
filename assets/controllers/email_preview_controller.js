import { Controller } from "@hotwired/stimulus";
import { generateCsrfToken } from "./csrf_protection_controller.js";

/*
 * Preview modal for the admin email-template form.
 *
 * Mounted per fieldset on `/admin/settings/email`. Each mount
 * scopes its own subject `<input>`, body `<textarea>`, dialog,
 * iframe, and CSRF-carrier form so the three fieldsets on the
 * page are fully independent — the preview reads and shows only
 * the fields adjacent to the clicked link.
 *
 * Click → `open()`:
 *
 *  1. Snapshot the current subject + body values.
 *  2. Mint a stateless CSRF token via the shared
 *     `generateCsrfToken()` helper against the hidden carrier
 *     form (double-submit cookie pattern; see the equivalent
 *     wiring on `role_picker_controller`).
 *  3. POST the JSON `{ subject, body, _token }` to the endpoint
 *     configured via `data-email-preview-url-value`.
 *  4. On success, drop the returned `html` into the iframe via
 *     `srcdoc` and show the subject in the modal header, then
 *     call `dialog.showModal()`. On failure, unhide the error
 *     paragraph and open the dialog anyway so the admin sees
 *     the failure state.
 *
 * The iframe carries `sandbox="allow-same-origin"` — the admin-
 * typed Markdown is trusted enough to render, but scripts and
 * form submissions inside the rendered HTML stay contained.
 *
 * Targets:
 *   subjectInput    — `<input>` holding the subject template
 *   bodyInput       — `<textarea>` holding the Markdown body
 *   dialog          — `<dialog>` root
 *   iframe          — `<iframe>` inside the dialog
 *   subjectDisplay  — `<span>` in the dialog header that shows
 *                     the resolved subject
 *   error           — `<p role="alert">` shown when the fetch
 *                     fails or returns a non-2xx response
 *   csrfForm        — hidden `<form>` carrier for the shared
 *                     `csrf-protection` input
 *
 * Values:
 *   url             — endpoint URL for the preview POST
 */
export default class extends Controller {
    static targets = [
        "subjectInput",
        "bodyInput",
        "dialog",
        "iframe",
        "subjectDisplay",
        "error",
        "csrfForm",
    ];
    static values = { url: String };

    connect() {
        // Close on backdrop click. `<dialog>` fires a `click`
        // event on the dialog itself when the user clicks the
        // backdrop; the target is then the dialog element and not
        // a descendant, which lets us distinguish it from a click
        // on the modal content.
        this.dialogTarget.addEventListener("click", (event) => {
            if (event.target === this.dialogTarget) {
                this.dialogTarget.close();
            }
        });
    }

    async open(event) {
        event.preventDefault();

        this.hideError();

        const subject = this.subjectInputTarget.value || "";
        const body = this.bodyInputTarget.value || "";
        const token = this.mintCsrfToken();

        let payload;
        try {
            const response = await fetch(this.urlValue, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                },
                credentials: "same-origin",
                body: JSON.stringify({ subject, body, _token: token }),
            });

            if (!response.ok) {
                this.showError();
                this.dialogTarget.showModal();
                return;
            }

            payload = await response.json();
        } catch {
            this.showError();
            this.dialogTarget.showModal();
            return;
        }

        this.subjectDisplayTarget.textContent = payload.subject || "";
        this.iframeTarget.setAttribute("srcdoc", payload.html || "");
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }

    mintCsrfToken() {
        generateCsrfToken(this.csrfFormTarget);
        const field = this.csrfFormTarget.querySelector('input[name="_token"]');
        return field ? field.value : "";
    }

    showError() {
        this.errorTarget.classList.remove("hidden");
    }

    hideError() {
        this.errorTarget.classList.add("hidden");
    }
}
