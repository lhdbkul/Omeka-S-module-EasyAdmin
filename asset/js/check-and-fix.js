'use strict';

/**
 * Check and fix form: behaviour of the tasks that loop on a resource type with
 * an optional query.
 *
 * - Scope the query builder to the resource type selected in the radio. The
 *   query-form reads data('resourceType') on demand and, when its edit sidebar
 *   is open, it is reloaded for the new type.
 * - Show a visible warning on the query field when "All" is selected, since a
 *   single query is then applied identically to every type.
 * - Note: the Omeka Query element only supports items/item_sets/media; the
 *   others fall back to items.
 */
(function ($) {
    $(document).ready(function () {
        var $radios = $('input[name="resource_values[db_loop_save][resource_types]"]');
        if (!$radios.length) {
            return;
        }

        var $query = $('#db_loop_save-query').closest('.query-form-element');
        var warningText = $('#check-and-fix-form').data('dbLoopWarning') || '';
        var $warning = $('<p>')
            .addClass('messages warning db-loop-query-warning')
            .text(warningText)
            .prop('hidden', true);
        if ($query.length) {
            $query.after($warning);
        }

        var typed = ['items', 'item_sets', 'media'];
        function sync() {
            var value = $radios.filter(':checked').val();
            if ($query.length) {
                // The Query element only scopes items/item_sets/media; the
                // others fall back to items.
                var resourceType = typed.indexOf(value) !== -1 ? value : 'items';
                $query.data('resourceType', resourceType);
                // Reload the open advanced search form for the new type, so the
                // available filters match the selected resource type.
                var $sidebar = $('#query-sidebar-edit');
                if ($sidebar.hasClass('active')) {
                    var url = $query.data('sidebar-edit-url');
                    var query = $query.find('.query-form-query').val().trim().replace(/^\?+/, '');
                    Omeka.populateSidebarContent($sidebar, url + '?' + query, {
                        query_resource_type: resourceType,
                        query_partial_excludelist: JSON.stringify($query.data('partialExcludelist') || [])
                    });
                }
            }
            $warning.prop('hidden', value !== 'all');
        }

        $radios.on('change', sync);
        sync();
    });
})(jQuery);
