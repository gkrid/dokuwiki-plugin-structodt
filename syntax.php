<?php
/**
 * DokuWiki Plugin structodt (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <it@rid.pl>
 */

// must be run within Dokuwiki
use dokuwiki\plugin\structodt\meta\Odt;

if (!defined('DOKU_INC')) die();

class syntax_plugin_structodt extends syntax_plugin_struct_table {

    /** @var string which class to use for output */
    protected $tableclass = Odt::class;

    /**
     * Connect lookup pattern to lexer.
     *
     * @param string $mode Parser mode
     */
    public function connectTo($mode) {
        $this->Lexer->addSpecialPattern('----+ *struct odt *-+\n.*?\n----+', $mode, 'plugin_structodt');
    }

    /**
     * Render xhtml output or metadata
     *
     * @param string $mode Renderer mode (supported modes: xhtml)
     * @param Doku_Renderer $renderer The renderer
     * @param array $data The data from the handler() function
     *
     * @return bool If rendering was successful.
     */
    public function render($mode, Doku_Renderer $renderer, $data) {
        parent::render($mode, $renderer, $data);

        if ($mode == 'metadata') {
            $renderer->meta['plugin']['structodt']['odt'] = $data['odt'];
        }
    }

}

// vim:ts=4:sw=4:et: