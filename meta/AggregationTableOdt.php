<?php

namespace dokuwiki\plugin\structodt\meta;

use dokuwiki\plugin\struct\meta\SearchConfig;
use dokuwiki\plugin\struct\meta\AggregationTable;

class AggregationTableOdt extends AggregationTable {

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
        $this->pdf = $conf['pdf'] ?? false;
        $this->helper_structodt = plugin_load('helper', 'structodt');
    }

    /**
     * Adds additional info to document and renderer in XHTML mode
     *
     * @see finishScope()
     */
    public function startScope()
    {
        // unique identifier for this aggregation
        $this->renderer->info['struct_table_hash'] = md5(var_export($this->data, true));

        if ($this->mode == 'xhtml') {
            $classes = $this->getScopeClasses();
            $classes[] = 'structodt';

            $hash = $this->renderer->info['struct_table_hash'] ?? '';
            $id = $hash ? " id=\"$hash\" " : '';

            $table = $this->columns[0]->getTable();
            $filetype = $this->pdf ? 'pdf' : 'odt';

            $this->renderer->doc .= '<' . $this->tagName .  $id . ' class="' . implode(' ', $classes) . '"
                data-schema="' . $table . '"
                data-template="' . $this->template . '"
                data-filetype="' . $filetype . '">';
        }
    }

    /**
     * Adds PDF export controls
     */
    protected function renderExportControls() {
        global $ID;

        parent::renderExportControls();

        if ($this->mode != 'xhtml') return;
        if (!$this->data['pdf']) return;
        if (!$this->searchConfig->getCount()) return;

        // FIXME apply dynamic filters
        $urlParameters = array(
            'do' => 'structodt',
            'action' => 'renderAll',
            'template_string' => $this->template
        );

        foreach($this->data['schemas'] as $key => $schema) {
            $urlParameters['schema[' . $key . '][0]'] = $schema[0];
            $urlParameters['schema[' . $key . '][1]'] = $schema[1];
        }

        foreach($this->data['filter'] as $i => $filter) {
            foreach ($filter as $j => $value) {
                $urlParameters["filter[$i][$j]"] = $value;
            }
        }

        $href = wl($ID, $urlParameters);

        $margin = 0;
        if (!empty($this->data['csv'])) {
            $margin = 10;
        }

        $this->renderer->doc .= '<a href="' . $href . '" class="export mediafile mf_pdf" style="margin-left: ' . $margin . 'em;">'.$this->helper_structodt->getLang('btn_downloadAll_pdf').'</a>';

        if ($this->pdf) {
            $urlParameters['format'] = 'pdf';
            $href = wl($ID, $urlParameters);
            $this->renderer->doc .= '<a href="' . $href . '" class="export mediafile mf_pdf" style="margin-left: ' . $margin + 15 . 'em;">' .
                $this->helper_structodt->getLang('btn_downloadAll_pdf') .
                '</a>';
        }

    }
}