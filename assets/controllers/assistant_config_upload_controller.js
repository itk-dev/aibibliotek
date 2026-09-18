import { Controller } from "@hotwired/stimulus";

/*
 * Upload + validate the assistant config for the create form, in any
 * supported format (the server detects which one).
 *
 * Mounted on the form (`data-controller="assistant-config-upload"`).
 * The dropzone (`dropzone` target) accepts either a file drop or
 * a click that opens the hidden file input (`file` target); either
 * path routes into `handleFile()`. The editable JSON textarea is
 * the `config` target — users can also paste or type JSON
 * directly. Selecting or dropping a file kicks off:
 *
 *   1. Read the file contents via FileReader.
 *   2. For each check in the `checks` value, POST the JSON to the
 *      validate URL with that check's identifier and wait for the
 *      response. Each completed check moves the progress bar one
 *      notch forward — the progress is a count of finished checks,
 *      not a timer.
 *   3. If any check fails: show its errors, clear the textarea,
 *      stop iterating.
 *   4. If every check passes: pretty-print the JSON into the
 *      textarea (two-space indent, newlines preserved) so the
 *      operator can read and edit it before submit. The
 *      server-side flow re-minifies on save.
 *
 * The file itself is never submitted to the server — only the
 * textarea's text content ends up on the server. On invalid
 * uploads the textarea is cleared so the server-side validator
 * catches the same problem on submit.
 */
export default class extends Controller {
    static targets = ["file", "config", "progress", "status", "dropzone"];
    static values = { validateUrl: String, checks: Array };
    static classes = ["dragging"];

    openFilePicker(event) {
        // Guard against click bubbling from inside the file input
        // itself, which would cause an infinite click loop.
        if (event.target === this.fileTarget) {
            return;
        }
        event.preventDefault();
        this.fileTarget.click();
    }

    dragOver(event) {
        event.preventDefault();
        if (this.hasDropzoneTarget && this.hasDraggingClass) {
            this.dropzoneTarget.classList.add(...this.draggingClasses);
        }
    }

    dragLeave() {
        if (this.hasDropzoneTarget && this.hasDraggingClass) {
            this.dropzoneTarget.classList.remove(...this.draggingClasses);
        }
    }

    async drop(event) {
        event.preventDefault();
        if (this.hasDropzoneTarget && this.hasDraggingClass) {
            this.dropzoneTarget.classList.remove(...this.draggingClasses);
        }
        const file = event.dataTransfer?.files?.[0];
        if (!file) {
            return;
        }
        await this.handleFile(file);
    }

    async fileChanged() {
        const file = this.fileTarget.files?.[0];
        if (!file) {
            return;
        }
        await this.handleFile(file);
    }

    async handleFile(file) {
        let content;
        try {
            content = await file.text();
        } catch (err) {
            this.showError([err?.message || "Could not read file."]);
            return;
        }

        const total = this.checksValue.length;
        if (total === 0) {
            // No checks registered — nothing to validate; pretty-print
            // best-effort and fall back to the raw content if the file
            // isn't parseable as JSON.
            this.configTarget.value = this.prettyPrint(content);
            return;
        }

        this.beginProgress(total);
        this.statusTarget.textContent =
            this.statusTarget.dataset.validatingText || "Validating…";

        let passed = 0;
        for (const check of this.checksValue) {
            let result;
            try {
                const response = await fetch(this.validateUrlValue, {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        Accept: "application/json",
                    },
                    body: JSON.stringify({ json: content, check }),
                });
                result = await response.json();
            } catch (err) {
                this.endProgress();
                this.configTarget.value = "";
                this.showError([err?.message || "Validation request failed."]);
                return;
            }

            if (!result.valid) {
                this.endProgress();
                this.configTarget.value = "";
                this.showError(result.errors || []);
                return;
            }

            passed += 1;
            this.advanceProgress(passed, total);
        }

        // All checks passed.
        this.endProgress();
        this.configTarget.value = this.prettyPrint(content);
        this.statusTarget.textContent =
            this.statusTarget.dataset.validText || "Configuration is valid.";
        this.statusTarget.classList.remove("text-red-600");
        this.statusTarget.classList.add("text-primary");
    }

    /**
     * Reformat JSON with two-space indentation and newlines so the
     * operator can read and edit it. Falls back to the raw input
     * when the content isn't parseable as JSON — the textarea is
     * still editable, the server-side validator catches malformed
     * input on submit, and we don't accidentally erase what the
     * user typed.
     */
    prettyPrint(raw) {
        try {
            return JSON.stringify(JSON.parse(raw), null, 2);
        } catch {
            return raw;
        }
    }

    beginProgress(total) {
        const progress = this.progressTarget;
        progress.classList.remove("hidden");
        progress.max = total;
        progress.value = 0;
    }

    advanceProgress(passed, total) {
        this.progressTarget.max = total;
        this.progressTarget.value = passed;
    }

    endProgress() {
        // Leave the bar at its final value; the value already
        // reflects the number of completed checks.
    }

    showError(errors) {
        const prefix =
            this.statusTarget.dataset.invalidText || "Invalid configuration:";
        const message =
            errors.length === 0 ? prefix : `${prefix} ${errors.join("; ")}`;
        this.statusTarget.textContent = message;
        this.statusTarget.classList.remove("text-primary");
        this.statusTarget.classList.add("text-red-600");
    }
}
