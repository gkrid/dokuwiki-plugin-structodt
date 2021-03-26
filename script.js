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
            // const disableDeleteSerial = JSINFO.plugins.struct.disableDeleteSerial;

            $table.find('tr').each(function () {
                const $me = jQuery(this);

                // already added here?
                if ($me.find('th.action, td.action').length) {
                    return;
                }

                const rid = $me.data('rid');
                const pid = $me.data('pid');
                const rev = $me.data('rev');
                // let isDisabled = '';

                // empty header cells
                if (!rid) {
                    $me.append('<th class="action">' + LANG.plugins.struct.actions + '</th>');
                    return;
                }

                // delete buttons for rows
                const $td = jQuery('<td class="action"></td>');
                // if (rid === '') return;  // skip button addition for page data
                // disable button for serial data if so configured
                // if (rid && pid && disableDeleteSerial) {
                //     isDisabled = ' disabled';
                // }

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

                // const $btn = jQuery('<button><img src="'+icon+'" alt="'+filetype+'" class="icon"></button>')
                //     .addClass('delete')
                //     .attr('title', LANG.plugins.struct.lookup_delete)
                //     .click(function (e) {
                //         e.preventDefault();
                //         if (!window.confirm(LANG.del_confirm)) return;
                //
                //         jQuery.post(
                //             DOKU_BASE + 'lib/exe/ajax.php',
                //             {
                //                 call: 'plugin_structodt_download',
                //                 schema: schema,
                //                 rid: rid,
                //                 template: template,
                //                 filetype: filetype,
                //                 sectok: $me.parents('.structaggregation').find('input[name=sectok]').val()
                //             }
                //         )
                //             .fail(function (xhr) {
                //                 alert(xhr.responseText)
                //             })
                //     });

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