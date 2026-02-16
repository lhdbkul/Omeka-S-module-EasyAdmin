/**
 * Chosen default options.
 *
 * Override of Omeka core chosen-options.js to add diacritic-insensitive search.
 * Typing "e" matches "é", "è", "ê", etc. and vice versa.
 *
 * @see https://harvesthq.github.io/chosen/
 * @see https://github.com/Daniel-KM/chosen-jquery
 */
var chosenOptions = {
    allow_single_deselect: true,
    disable_search_threshold: 10,
    width: '100%',
    include_group_label_in_selected: true,
    search_contains: false,
    normalize_search_text: function(text) {
        return typeof text === 'string'
            ? text.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            : text;
    },
};
