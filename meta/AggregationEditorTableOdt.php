<?php

namespace dokuwiki\plugin\structodt\meta;

use dokuwiki\plugin\struct\meta\SearchConfig;
use dokuwiki\plugin\struct\meta\AggregationEditorTable;

class AggregationEditorTableOdt extends AggregationEditorTable {

    /** @var  string odt file used as export template */
    protected $template;

    /** @var bool download rendered files as PDFs */
    protected $pdf;

    /** @var bool hide the form for global and local structs */
    protected $hideform;

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
        $this->hideform = $conf['hideform'] ?? false;
        $this->filename = $conf['filename'] ?? '';
        $this->helper_structodt = plugin_load('helper', 'structodt');
    }

    /**
     * Adds additional info to document and renderer in XHTML mode
     *
     * We add the schema name as data attribute
     *
     * @see finishScope()
     */
    public function startScope()
    {
        // unique identifier for this aggregation
        $this->renderer->info['struct_table_hash'] = md5(var_export($this->data, true));

        if ($this->mode != 'xhtml') return;

        $table = hsc($this->columns[0]->getTable());

        $config = $this->searchConfig->getConf();
        if (isset($config['filter'])) unset($config['filter']);
        $config = hsc(json_encode($config));

        $filetype = $this->pdf ? 'pdf' : 'odt';
        $template = hsc(json_encode($this->template));

        $hideform = '';
        if ($this->hideform) {
            $hideform = 'hideform';
        }

        $filename = hsc($this->filename);
        // wrapping div
        $this->renderer->doc .= "<div class=\"structaggregation structaggregationeditor structodt $hideform\"
                                    data-schema=\"$table\" data-searchconf=\"$config\"
                                    data-template=\"$template\" data-filetype=\"$filetype\"
                                    data-filename=\"$filename\"'>";

        // unique identifier for this aggregation
        $this->renderer->info['struct_table_hash'] = md5(var_export($this->data, true));
    }

    /**
     * Adds PDF export controls
     */
    protected function renderExportControls() {
        global $ID;

        parent::renderExportControls();

        if($this->mode != 'xhtml') return;
        if(!$this->resultCount) return;

        // FIXME apply dynamic filters
        $urlParameters = array(
            'do' => 'structodt',
            'action' => 'renderAll',
            'template_string' => hsc(json_encode($this->template)),
            'filename' => $this->filename
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



        $margin=0;
        if (!empty($this->data['csv'])) {
            $margin = 10;
        }

        $href = wl($ID, $urlParameters);
        $this->renderer->doc .= '<a href="' . $href . '" class="export mediafile mf_zip" style="margin-left: ' . $margin . 'em;">' .
            $this->helper_structodt->getLang('btn_downloadAll') .
        '</a>';

        if ($this->pdf) {
            $urlParameters['format'] = 'pdf';
            $href = wl($ID, $urlParameters);
            $this->renderer->doc .= '<a href="' . $href . '" class="export mediafile mf_pdf" style="margin-left: ' . $margin + 11 . 'em;">' .
                $this->helper_structodt->getLang('btn_downloadAll') .
                '</a>';
        }
    }
}