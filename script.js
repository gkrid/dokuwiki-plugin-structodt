jQuery(function() {
    /**
     * Aggregation table editor
     */
    const AggregationOdt = function (idx, table) {
        const $table = jQuery(table);
        let $form = null;

        const schema = $table.parents('.structaggregation').data('schema');
        if (!schema) return;

        const template = $table.parents('.structaggregation').data('template');
        if (!template) return;

        const filetype = $table.parents('.structaggregation').data('filetype');
        if (!filetype) return;

        /**
         * Adds odt export row buttons to each row
         */
        function addOdtRowButtons() {
            $table.find('tr').each(function () {
                const $me = jQuery(this);

                // already added here?
                if ($me.find('th.actionodt, td.actionodt').length) {
                    return;
                }

                const rid = $me.data('rid');
                const pid = $me.data('pid');
                const rev = $me.data('rev');
                // let isDisabled = '';

                // empty header cells
                if (!rid) {
                    $me.append('<th class="actionodt">' + LANG.plugins.struct.actions + '</th>');
                    return;
                }

                // delete buttons for rows
                const $td = jQuery('<td class="actionodt"></td>');

                const icon = DOKU_BASE + 'lib/images/fileicons/' + filetype + '.png'
                const url = new URL(window.location.href);
                url.searchParams.append('do', 'structodt');
                url.searchParams.append('action', 'render');
                url.searchParams.append('schema', schema);
                url.searchParams.append('pid', pid);
                url.searchParams.append('rev', rev);
                url.searchParams.append('rid', rid);
                url.searchParams.append('template', template);
                url.searchParams.append('filetype', filetype);
                title = LANG['plugins']['structodt']['btn_download'];
                const $btn = jQuery('<a href="'+url.href+'" title="' + title + '"><img src="'+icon+'" alt="'+filetype+'" class="icon"></a>')

                $td.append($btn);
                $me.append($td);

            });
        }
        addOdtRowButtons();
    };

    function init() {
        jQuery('div.structodt table').each(AggregationOdt);
    }
    jQuery(init);
});