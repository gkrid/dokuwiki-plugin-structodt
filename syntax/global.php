<?php
/**
 * DokuWiki Plugin structodt (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <it@rid.pl>
 */

// must be run within Dokuwiki
use dokuwiki\plugin\structodt\meta\AggregationEditorTableOdt;

if (!defined('DOKU_INC')) die();

class syntax_plugin_structodt_global extends syntax_plugin_struct_global
{

    /** @var string which class to use for output */
    protected $tableclass = AggregationEditorTableOdt::class;

    /**
     * Connect lookup pattern to lexer.
     *
     * @param string $mode Parser mode
     */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('----+ *struct odt global *-+\n.*?\n----+', $mode, 'plugin_structodt_global');
    }
}

// vim:ts=4:sw=4:et: