import FilterRangeSlider from './plugin/filter-range-slider.plugin';
import BrunkoFilterSearch from './plugin/filter-search.plugin';

const PluginManager = window.PluginManager;

// Extends the original FilterRange plugin (it keeps using the original events and listing.changeListing()).
PluginManager.override('FilterRange', FilterRangeSlider, '[data-filter-range]');

// Search field for long filter lists.
PluginManager.register('BrunkoFilterSearch', BrunkoFilterSearch, '[data-brunko-filter-search]');
