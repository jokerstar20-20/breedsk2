import BrunkoFilterSearch from './plugin/filter-search.plugin';

const PluginManager = window.PluginManager;

// Search field for long filter lists.
PluginManager.register('BrunkoFilterSearch', BrunkoFilterSearch, '[data-brunko-filter-search]');
