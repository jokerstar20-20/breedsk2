import Plugin from 'src/plugin-system/plugin.class';

/*
 * Local search for long filter lists (multi select, property select).
 * It only hides list items in the DOM, the filter state itself is not touched.
 */
export default class BrunkoFilterSearch extends Plugin {

    static options = {
        inputSelector: '.brunko-filter-search-input',
        dropdownSelector: '.filter-panel-item-dropdown',
        itemSelector: '.filter-multi-select-list-item',
        labelSelector: '.filter-multi-select-item-label',
    };

    init() {
        this._input = this.el.querySelector(this.options.inputSelector);
        this._dropdown = this.el.closest(this.options.dropdownSelector);

        if (!this._input || !this._dropdown) {
            return;
        }

        this._input.addEventListener('input', this._onInput.bind(this));
        this._input.addEventListener('keydown', (event) => {
            // Enter must not submit anything or toggle the filter.
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });
    }

    _onInput() {
        const term = this._input.value.trim().toLowerCase();

        this._dropdown.querySelectorAll(this.options.itemSelector).forEach((item) => {
            const label = item.querySelector(this.options.labelSelector);
            const text = (label ? label.textContent : item.textContent).trim().toLowerCase();

            item.hidden = term.length > 0 && !text.includes(term);
        });
    }
}
