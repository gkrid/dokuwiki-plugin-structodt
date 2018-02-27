<?php

namespace dokuwiki\plugin\structodt\meta;

use dokuwiki\plugin\struct\meta\SearchConfig;
use dokuwiki\plugin\struct\meta\Value;
use dokuwiki\plugin\struct\meta\AggregationTable;

class Odt extends AggregationTable {

    /** @var  string odt file used as export template */
    protected $template;

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
    }

    /**
     * Render a single result row
     *
     * @param int $rownum
     * @param array $row
     */
    protected function renderResultRow($rownum, $row) {
        global $ID;

        parent::renderResultRow($rownum, $row);
        //remove tablerow_close
        $doc_len = strlen($this->renderer->doc);
        $this->renderer->tablerow_close();
        //calculate tablerow_close length
        $tablerow_close_len = strlen($this->renderer->doc) - $doc_len;
        $this->renderer->doc = substr($this->renderer->doc, 0,  -2*$tablerow_close_len);

        if($this->mode == 'xhtml') {
            $pid = $this->resultPIDs[$rownum];

            $this->renderer->tablecell_open();
            $icon = DOKU_PLUGIN . 'structodt/images/odt.svg';
            $urlParameters = array('do' => 'structodt',
                                   'template' => $this->template,
                                   'pid' => hsc($pid));

            foreach($this->data['schemas'] as $key => $schema) {
                $urlParameters['schema[' . $key . '][0]'] = $schema[0];
                $urlParameters['schema[' . $key . '][1]'] = $schema[1];
            }

            $href = wl($ID, $urlParameters);
            $title = 'ODT export';
            $this->renderer->doc .= '<a href="' . $href . '" title="' . $title . '">' . inlineSVG($icon) . '</a>';
            $this->renderer->tablecell_close();
        }

        $this->renderer->tablerow_close();
    }
}