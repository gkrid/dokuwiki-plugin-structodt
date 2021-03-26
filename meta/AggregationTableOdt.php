<?php

namespace dokuwiki\plugin\structodt\meta;

use dokuwiki\plugin\struct\meta\SearchConfig;
use dokuwiki\plugin\struct\meta\Value;
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
        $this->pdf = $conf['pdf'];
        $this->helper_structodt = plugin_load('helper', 'structodt');
    }

    /**
     * Adds additional info to document and renderer in XHTML mode
     *
     * @see finishScope()
     */
    protected function startScope()
    {
        // unique identifier for this aggregation
        $this->renderer->info['struct_table_hash'] = md5(var_export($this->data, true));

        if ($this->mode != 'xhtml') return;

        $table = $this->columns[0]->getTable();

        $filetype = $this->pdf ? 'pdf' : 'odt';

        // wrapping div
        $this->renderer->doc .= '<div class="structaggregation structodt" data-schema="' . $table . '"
                                    data-template="' . $this->template . '" data-filetype="' . $filetype . '">';
    }

    /**
     * Render a single result row
     *
     * @param int $rownum
     * @param array $row
     */
//    protected function renderResultRow($rownum, $row) {
//        parent::renderResultRow($rownum, $row);
//        //remove tablerow_close
//        $doc_len = strlen($this->renderer->doc);
//        $this->renderer->tablerow_close();
//        //calculate tablerow_close length
//        $tablerow_close_len = strlen($this->renderer->doc) - $doc_len;
//        $this->renderer->doc = substr($this->renderer->doc, 0,  -2*$tablerow_close_len);
//
//        if ($this->mode == 'xhtml') {
//            $pid = $this->resultPIDs[$rownum];
//            $this->renderOdtButton($rownum);
//            if ($this->delete) {
//                $this->renderDeleteButton($pid);
//            }
//        }
//
//        $this->renderer->tablerow_close();
//    }

    /**
     * Display a media icon
     *
     * @param string $filename media id
     * @param string $size     the size subfolder, if not specified 16x16 is used
     * @return string html
     */
//    public static function media_printicon($ext, $size=''){
//
//        if (file_exists(DOKU_INC.'lib/images/fileicons/'.$size.'/'.$ext.'.png')) {
//            $icon = DOKU_BASE.'lib/images/fileicons/'.$size.'/'.$ext.'.png';
//        } else {
//            $icon = DOKU_BASE.'lib/images/fileicons/'.$size.'/file.png';
//        }
//
//        //max-width - fix chrome not showing the image
//        return '<img src="'.$icon.'" alt="'.$ext.'" class="icon" style="max-width:none;" />';
//    }

    /**
     * @param $pid
     */
//    protected function renderOdtButton($rownum) {
//        global $ID;
//
//        $pid = $this->resultPIDs[$rownum];
//
//        /** @var Value[] $row */
//        $row = $this->result[$rownum];
//        $media = self::rowTemplate($row, $this->template);
//
//        $this->renderer->tablecell_open();
//        $ext = $this->pdf ? 'pdf' : pathinfo($media, PATHINFO_EXTENSION);
//        $urlParameters = array(
//            'do' => 'structodt',
//            'action' => 'render',
//            'template' => $media,
//            'pdf' => $this->pdf,
//            'pid' => hsc($pid));
//
//        foreach($this->data['schemas'] as $key => $schema) {
//            $urlParameters['schema[' . $key . '][0]'] = $schema[0];
//            $urlParameters['schema[' . $key . '][1]'] = $schema[1];
//        }
//
//        $href = wl($ID, $urlParameters);
//        $title = $this->helper_structodt->getLang('btn_download');
//        $this->renderer->doc .= '<a href="' . $href . '" title="' . $title . '">';
//        $this->renderer->doc .= self::media_printicon($ext);
//        $this->renderer->doc .= '</a>';
//        $this->renderer->tablecell_close();
//    }

    /**
     * @param $pid
     */
//    protected function renderDeleteButton($pid) {
//        global $ID;
//
//        $schemas = $this->searchConfig->getSchemas();
//        $schema = $schemas[0];
//
//        $this->renderer->tablecell_open();
//        $urlParameters['do'] = 'structodt';
//        $urlParameters['action'] = 'delete';
//        $urlParameters['schema'] = $schema->getTable();
//        $urlParameters['pid'] = $pid;
//        $urlParameters['sectok'] = getSecurityToken();
//
//        $href = wl($ID, $urlParameters);
//        $this->renderer->doc .= '<a href="'.$href.'"><button><i class="ui-icon ui-icon-trash"></i></button></a>';
//        $this->renderer->tablecell_close();
//    }

    /**
     * Adds PDF export controls
     */
    protected function renderExportControls() {
        global $ID;

        parent::renderExportControls();

        if($this->mode != 'xhtml') return;
        if(!$this->data['pdf']) return;
        if(!$this->resultCount) return;

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

        $style='';
        if (!empty($this->data['csv'])) {
            $style='style="margin-left: 10em;"';
        }

        $this->renderer->doc .= '<a href="' . $href . '" class="export mediafile mf_pdf" ' . $style . '>'.$this->helper_structodt->getLang('btn_downloadAll').'</a>';
    }
}