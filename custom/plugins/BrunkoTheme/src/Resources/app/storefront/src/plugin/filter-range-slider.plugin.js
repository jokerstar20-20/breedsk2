import FilterRangePlugin from 'src/plugin/listing/filter-range.plugin';

/*
 * Extension of the original FilterRange plugin: adds a dual range slider.
 *
 * The original number inputs (.min-input / .max-input) stay the single source of values.
 * The slider only writes into them and dispatches the same "input" event the original plugin
 * listens to. Debounce, validation, getValues(), getLabels() and listing.changeListing()
 * therefore remain the original Shopware behaviour (AJAX listing update, history, filter labels).
 *
 * PluginManager.override() copies the own properties of this object onto the plugin prototype,
 * so the original methods are called via the prototype of the original class.
 */
const original = FilterRangePlugin.prototype;

export default {
    init() {
        original.init.call(this);

        this._brunkoInitSlider();
    },

    setValuesFromUrl(params) {
        const stateChanged = original.setValuesFromUrl.call(this, params);

        this._brunkoSyncFromInputs();

        return stateChanged;
    },

    reset(id) {
        original.reset.call(this, id);

        this._brunkoSyncFromInputs();
    },

    resetAll() {
        original.resetAll.call(this);

        this._brunkoSyncFromInputs();
    },

    _brunkoInitSlider() {
        this._brunkoSlider = this.el.querySelector('.brunko-range-slider');

        if (!this._brunkoSlider) {
            // Slider markup is not rendered (no price range): the original inputs stay visible.
            return;
        }

        this._brunkoControl = this._brunkoSlider.querySelector('.brunko-range-control');
        this._brunkoRangeMin = this._brunkoSlider.querySelector('.brunko-range-input-min');
        this._brunkoRangeMax = this._brunkoSlider.querySelector('.brunko-range-input-max');
        this._brunkoOutputMin = this._brunkoSlider.querySelector('.brunko-range-output-min');
        this._brunkoOutputMax = this._brunkoSlider.querySelector('.brunko-range-output-max');

        this._brunkoLower = parseFloat(this._brunkoRangeMin.min);
        this._brunkoUpper = parseFloat(this._brunkoRangeMin.max);
        this._brunkoFormatter = new Intl.NumberFormat(document.documentElement.lang || undefined, {
            maximumFractionDigits: 0,
        });

        this._brunkoRangeMin.addEventListener('input', () => this._brunkoOnSliderInput('min'));
        this._brunkoRangeMax.addEventListener('input', () => this._brunkoOnSliderInput('max'));

        this._brunkoSyncFromInputs();

        // Progressive enhancement: CSS shows the slider and hides the number inputs only now.
        this._brunkoSlider.classList.add('is-ready');
    },

    _brunkoOnSliderInput(source) {
        let min = parseFloat(this._brunkoRangeMin.value);
        let max = parseFloat(this._brunkoRangeMax.value);

        // The handles must not cross each other.
        if (min > max) {
            if (source === 'min') {
                min = max;
                this._brunkoRangeMin.value = min;
            } else {
                max = min;
                this._brunkoRangeMax.value = max;
            }
        }

        // Values at the outer bounds mean "no limit": leave the original input empty,
        // so no needless active filter is created.
        this._inputMin.value = min <= this._brunkoLower ? '' : String(min);
        this._inputMax.value = max >= this._brunkoUpper ? '' : String(max);

        this._brunkoRender(min, max);

        // Same event as typing into the original inputs.
        this._inputMin.dispatchEvent(new Event('input', { bubbles: true }));
        this._inputMax.dispatchEvent(new Event('input', { bubbles: true }));
    },

    _brunkoSyncFromInputs() {
        if (!this._brunkoSlider) {
            return;
        }

        const clamp = (value) => Math.min(this._brunkoUpper, Math.max(this._brunkoLower, value));
        const parsedMin = parseFloat(this._inputMin.value);
        const parsedMax = parseFloat(this._inputMax.value);

        const min = Number.isNaN(parsedMin) ? this._brunkoLower : clamp(parsedMin);
        const max = Number.isNaN(parsedMax) ? this._brunkoUpper : clamp(parsedMax);

        this._brunkoRangeMin.value = min;
        this._brunkoRangeMax.value = max;

        this._brunkoRender(min, max);
    },

    _brunkoRender(min, max) {
        const span = (this._brunkoUpper - this._brunkoLower) || 1;
        const start = ((min - this._brunkoLower) / span) * 100;
        const end = ((max - this._brunkoLower) / span) * 100;
        const unit = this.options.unit;

        this._brunkoControl.style.setProperty('--brunko-range-start', `${start}%`);
        this._brunkoControl.style.setProperty('--brunko-range-end', `${end}%`);

        const minText = `${this._brunkoFormatter.format(min)} ${unit}`;
        const maxText = `${this._brunkoFormatter.format(max)} ${unit}`;

        this._brunkoOutputMin.textContent = minText;
        this._brunkoOutputMax.textContent = maxText;
        this._brunkoRangeMin.setAttribute('aria-valuetext', minText);
        this._brunkoRangeMax.setAttribute('aria-valuetext', maxText);

        // When both handles sit near the upper end, the lower handle must stay reachable.
        const middle = (this._brunkoLower + this._brunkoUpper) / 2;
        this._brunkoSlider.classList.toggle('is-min-on-top', min > middle);
    },
};
