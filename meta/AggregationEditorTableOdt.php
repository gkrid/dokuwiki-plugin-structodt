<?php

namespace dokuwiki\plugin\structodt\meta;

use dokuwiki\plugin\struct\meta\SearchConfig;
use dokuwiki\plugin\struct\meta\AggregationEditorTable;

class AggregationEditorTableOdt extends AggregationEditorTable {

    /** @var  string odt file used as export template */
    protected $template;

    /** @var bool download rendered files as PDFs */
    protected $pdf;

    /**
     * @var \helper_plugin_structodt
     */
    protected $helper_structodt;

    /**
     * Initialize the Aggregation renderer and executes the search
     *
     * You need to call @see render() on the resulting object.
     *
     * @param string $id
     * @param string $mode
     * @param \Doku_Renderer $renderer
     * @param SearchConfig $searchConfig
     */
    public function __construct($id, $mode, \Doku_Renderer $renderer, SearchConfig $searchConfig) {
        parent::__construct($id, $mode, $renderer, $searchConfig);
        $conf = $searchConfig->getConf();
        $this->template = $conf['template'];
        $this->pdf = $conf['pdf'];
        $this->helper_structodt = plugin_load('helper', 'structodt');
    }

    /**
     * Adds additional info to document and renderer in XHTML mode
     *
     * We add the schema name as data attribute
     *
     * @see finishScope()
     */
    protected function startScope()
    {
        // unique identifier for this aggregation
        $this->renderer->info['struct_table_hash'] = md5(var_export($this->data, true));

        if ($this->mode != 'xhtml') return;

        $table = $this->columns[0]->getTable();

        $config = $this->searchConfig->getConf();
        if (isset($config['filter'])) unset($config['filter']);
        $config = hsc(json_encode($config));

        $filetype = $this->pdf ? 'pdf' : 'odt';
        $template = $this->template;

        // wrapping div
        $this->renderer->doc .= "<div class=\"structaggregation structaggregationeditor structodt\" data-schema=\"$table\" data-searchconf=\"$config\"
                                    data-template=\"$template\" data-filetype=\"$filetype\">";

        // unique identifier for this aggregation
        $this->renderer->info['struct_table_hash'] = md5(var_export($this->data, true));
    }
}