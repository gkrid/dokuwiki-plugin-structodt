<?php

namespace dokuwiki\plugin\structodt\meta;

use dokuwiki\plugin\struct\meta\SearchConfig;
use dokuwiki\plugin\struct\meta\Value;
use dokuwiki\plugin\struct\meta\AggregationTable;

class Odt extends AggregationTable {

    /** @var  string odt file used as export template */
    protected $template;

    /** @var bool should we display delete button for lookup schemas */
    protected $delete;

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
        $this->delete = $conf['delete'];
    }

    /**
     * Render a single result row
     *
     * @param int $rownum
     * @param array $row
     */
    protected function renderResultRow($rownum, $row) {
        parent::renderResultRow($rownum, $row);
        //remove tablerow_close
        $doc_len = strlen($this->renderer->doc);
        $this->renderer->tablerow_close();
        //calculate tablerow_close length
        $tablerow_close_len = strlen($this->renderer->doc) - $doc_len;
        $this->renderer->doc = substr($this->renderer->doc, 0,  -2*$tablerow_close_len);

        if ($this->mode == 'xhtml') {
            $pid = $this->resultPIDs[$rownum];
            $this->renderOdtButton($rownum);
            if ($this->delete) {
                $this->renderDeleteButton($pid);
            }
        }

        $this->renderer->tablerow_close();
    }

    /**
     * @param $pid
     */
    protected function renderOdtButton($rownum) {
        global $ID;

        $pid = $this->resultPIDs[$rownum];

        /** @var Value[] $result */
        $result = $this->result[$rownum];
        //do media file substitutions
        $media = preg_replace_callback('/\$(.*?)\$/', function ($matches) use ($result) {
            $possibleValueTypes = array('getValue', 'getCompareValue', 'getDisplayValue', 'getRawValue');
            list($label, $valueType) = explode('.', $matches[1], 2);
            if (!$valueType || !in_array($valueType, $possibleValueTypes)) {
                $valueType = 'getDisplayValue';
            }
            foreach ($result as $value) {
                $column = $value->getColumn();
                if ($column->getLabel() == $label) {
                    return call_user_func(array($value, $valueType));
                }
            }
            return '';
        }, $this->template);

        resolve_mediaid(getNS($ID), $media, $exists);
        if (!$exists) {
            msg("<strong>structodt</strong>: template file($media) doesn't exist", -1);
        }

        $this->renderer->tablecell_open();
        $icon = DOKU_PLUGIN . 'structodt/images/odt.svg';
        $urlParameters = array('do' => 'structodt',
            'action' => 'render',
            'template' => $media,
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

    /**
     * @param $pid
     */
    protected function renderDeleteButton($pid) {
        global $ID;

        $schemas = $this->searchConfig->getSchemas();
        // we don't know exact schama
        if (count($schemas) > 1) return;
        $schema = $schemas[0];
        //only lookup support deletion
        if (!$schema->isLookup()) return;

        $this->renderer->tablecell_open();
        $urlParameters['do'] = 'structodt';
        $urlParameters['action'] = 'delete';
        $urlParameters['schema'] = $schema->getTable();
        $urlParameters['pid'] = $pid;
        $urlParameters['sectok'] = getSecurityToken();

        $href = wl($ID, $urlParameters);
        $this->renderer->doc .= '<a href="'.$href.'"><button><i class="ui-icon ui-icon-trash"></i></button></a>';
        $this->renderer->tablecell_close();
    }
}