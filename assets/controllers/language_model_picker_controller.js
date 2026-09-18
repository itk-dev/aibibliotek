import { Controller } from "@hotwired/stimulus";
import Choices from "choices.js";

/*
 * Choices.js on the wizard's language-model `<select>`.
 *
 * The template pre-renders the option list as the union of
 * ModelMap::choices() and every persisted `languageModel`
 * value already in the catalogue (server-side, so no-JS
 * clients see the same options). This controller wraps the
 * select in Choices.js's select-one search combobox and
 * adds two behaviours the raw widget doesn't offer:
 *
 * 1. Alias-aware filter: we own the `search` event
 *    (`searchChoices: false`) and re-emit a filtered choice
 *    list that matches value, label, or any alias declared
 *    in model_map.yaml. Typing `openai/gpt` narrows the
 *    dropdown to the canonical `gpt-4o` row.
 *
 * 2. Free-tag on the fly: if the typed value doesn't match
 *    any known option, we append it to the filtered list as
 *    a pickable row. Enter or click commits it verbatim, and
 *    the committed value gets promoted to the known set so
 *    subsequent keystrokes don't duplicate it. The pill
 *    shows whatever the curator typed; server-side
 *    normalisation is what folds aliases to canonical ids.
 *
 * Turning JS off leaves a plain `<select>` behind, populated
 * with the same option list.
 */
export default class extends Controller {
    static values = {
        aliases: Object,
        placeholder: String,
        addItemText: String,
    };

    connect() {
        // The controller is bound to a wrapper `<div>` (not the `<select>`
        // itself) so Choices.js can re-parent the select into its own DOM
        // wrapper without dragging the controller node out of the tree —
        // that would fire disconnect/connect in a loop.
        this.select = this.element.querySelector("select");
        if (!this.select) {
            return;
        }

        // Snapshot the pre-rendered option list so the search hook
        // can rebuild the choice list from a stable source of truth.
        this.known = Array.from(this.select.options)
            .filter((o) => "" !== o.value)
            .map((o) => ({ value: String(o.value), label: o.textContent }));
        this.knownLower = new Set(this.known.map((c) => c.value.toLowerCase()));

        // Map canonical id → array of lower-cased alias tokens the
        // filter hook consults when a typed query misses the value +
        // label match.
        this.aliasesLower = new Map();
        const aliases = this.aliasesValue || {};
        for (const id of Object.keys(aliases)) {
            const list = Array.isArray(aliases[id]) ? aliases[id] : [];
            this.aliasesLower.set(
                id,
                list.map((a) => String(a).toLowerCase()),
            );
        }

        this.instance = new Choices(this.select, {
            allowHTML: false,
            searchEnabled: true,
            searchChoices: false,
            searchResultLimit: 50,
            shouldSort: false,
            removeItemButton: false,
            placeholder: true,
            placeholderValue: this.placeholderValue || "",
            searchPlaceholderValue: this.placeholderValue || "",
            addItemText: (value) =>
                (this.addItemTextValue || 'Add "__QUERY__"').replace(
                    "__QUERY__",
                    String(value),
                ),
        });

        this.onSearch = this.onSearch.bind(this);
        this.onChoice = this.onChoice.bind(this);
        this.select.addEventListener("search", this.onSearch);
        this.select.addEventListener("choice", this.onChoice);
    }

    disconnect() {
        if (this.select) {
            this.select.removeEventListener("search", this.onSearch);
            this.select.removeEventListener("choice", this.onChoice);
        }
        if (this.instance) {
            this.instance.destroy();
            this.instance = null;
        }
    }

    onSearch(event) {
        const query = String(event.detail?.value ?? "").trim();
        const q = query.toLowerCase();

        const filtered =
            "" === q
                ? this.known
                : this.known.filter((c) => {
                      if (c.value.toLowerCase().includes(q)) {
                          return true;
                      }
                      if (c.label.toLowerCase().includes(q)) {
                          return true;
                      }
                      const aliasList = this.aliasesLower.get(c.value) || [];
                      return aliasList.some((a) => a.includes(q));
                  });

        const list = filtered.map((c) => ({
            value: c.value,
            label: c.label,
        }));

        if ("" !== q && !this.knownLower.has(q)) {
            list.push({ value: query, label: query });
        }

        this.instance.setChoices(list, "value", "label", true);
    }

    onChoice(event) {
        const value = event.detail?.choice?.value;
        if (!value) {
            return;
        }
        const lower = String(value).toLowerCase();
        if (this.knownLower.has(lower)) {
            return;
        }
        this.known.push({ value: String(value), label: String(value) });
        this.knownLower.add(lower);
    }
}
