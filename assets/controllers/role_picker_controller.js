import { Controller } from "@hotwired/stimulus";
import { generateCsrfToken } from "./csrf_protection_controller.js";

/*
 * Inline role-mutation dropdown on /admin/users.
 *
 * Mounted per-row on the wrapper around the <select> + aria-live
 * feedback span (in the "Skift rolle" column). The role label
 * itself lives in the sibling "Rolle" cell, identified by a
 * `data-role-picker-row-label` attribute on the same <tr>. The
 * controller walks up to the row and back down to find that span
 * so it can swap its text on success — Stimulus targets can't
 * cross cell boundaries within a <tr>, but a single DOM lookup
 * scoped to `closest('tr')` works fine.
 *
 * CSRF on the JSON path
 * ---------------------
 * The project's CSRF is stateless (double-submit cookie pattern):
 * `csrf_token('intent')` server-side renders only the intent name,
 * and the bundled `csrf-protection` Stimulus controller mints the
 * real random token + pairs it with a `__Host-{intent}_{token}`
 * cookie at submit time. Form posts get this automatically via the
 * global submit listener; for our JSON fetch path we replicate the
 * same flow by hosting a hidden `<form>` carrier (rendered by the
 * Twig partial) and calling the shared `generateCsrfToken()` helper
 * at submit time. The minted token then travels in the JSON body
 * and the matching cookie travels in the request headers — the
 * server's `SameOriginCsrfTokenManager` validates the pair without
 * ever seeing a session.
 *
 * Targets:
 *   select   — the <select> the user manipulates.
 *   feedback — aria-live="polite" span used for success / error copy.
 *   csrfForm — hidden <form> carrier containing the csrf-protection
 *              <input>; passed to `generateCsrfToken()` so the
 *              shared minting logic populates the cookie + field.
 *
 * Values:
 *   url            — endpoint URL for this row (`/admin/users/{id}/role`).
 *   successMessage — translated success message shown in the feedback
 *                    span (the actual role label comes back from the
 *                    server response).
 */
export default class extends Controller {
    static targets = ["select", "feedback", "csrfForm"];
    static values = {
        url: String,
        successMessage: String,
    };

    async submit() {
        const role = this.selectTarget.value;
        if (!role) {
            return;
        }

        this.selectTarget.disabled = true;
        this.feedbackTarget.textContent = "";
        this.feedbackTarget.classList.remove("text-red-600");
        this.feedbackTarget.classList.add("text-text-muted");

        const token = this.mintCsrfToken();

        try {
            const response = await fetch(this.urlValue, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                },
                credentials: "same-origin",
                body: JSON.stringify({
                    role,
                    _token: token,
                }),
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                this.showError(payload.message || "");
                return;
            }

            const labelEl = this.rowLabelElement();
            if (labelEl) {
                labelEl.textContent = payload.label || "";
            }
            this.feedbackTarget.textContent = this.successMessageValue;
            this.selectTarget.value = "";
        } catch (error) {
            this.showError(error?.message || "");
        } finally {
            this.selectTarget.disabled = false;
        }
    }

    showError(message) {
        this.feedbackTarget.classList.remove("text-text-muted");
        this.feedbackTarget.classList.add("text-red-600");
        this.feedbackTarget.textContent = message;
        // Restore the dropdown to its placeholder so the failed value
        // doesn't look like the new state.
        this.selectTarget.value = "";
    }

    // Look up the label cell in the sibling "Rolle" column of the
    // same row. Returns null if the row markup ever drifts and the
    // attribute hook is missing — the controller stays defensive
    // rather than throwing.
    rowLabelElement() {
        const row = this.element.closest("tr");
        if (!row) {
            return null;
        }
        return row.querySelector("[data-role-picker-row-label]");
    }

    // Run the shared csrf-protection minting logic against our hidden
    // carrier form so the random token and matching cookie are paired
    // up exactly the way the form submit path expects. Returns the
    // freshly-minted token to embed in the JSON body.
    mintCsrfToken() {
        generateCsrfToken(this.csrfFormTarget);
        const field = this.csrfFormTarget.querySelector('input[name="_token"]');
        return field ? field.value : "";
    }
}
