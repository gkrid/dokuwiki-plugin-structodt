<?php
/**
 * DokuWiki Plugin structodt (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <it@rid.pl>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class action_plugin_structodt extends DokuWiki_Action_Plugin {

    /**
     * Registers a callback function for a given event
     *
     * @param Doku_Event_Handler $controller DokuWiki's event controller object
     * @return void
     */
    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('PLUGIN_STRUCT_CONFIGPARSER_UNKNOWNKEY', 'BEFORE', $this, 'handle_configparser');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_action_act_prerpocess');

    }

    /**
     * Add our own config keys
     *
     * @param Doku_Event $event  event object by reference
     * @param mixed      $param  [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     * @return void
     */

    public function handle_configparser(Doku_Event &$event, $param) {
        global $ID;
        $data = $event->data;

        if ($data['key'] != 'odt') return;

        $event->preventDefault();
        $event->stopPropagation();

        $media = trim($data['val']);
        resolve_mediaid(getNS($ID), $media, $exists);

        if (!$exists) {
            msg("<strong>structodt</strong>: template file doesn't exist", -1);
            return;
        }

        $data['config']['odt'] = $media;
    }


    /**
     * Handle odt export
     *
     * @param Doku_Event $event  event object by reference
     * @param mixed      $param  [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     * @return void
     */

    public function handle_action_act_prerpocess(Doku_Event &$event, $param) {
        global $INPUT, $ID;
        if ($event->data != 'structodt') return;

        $meta = p_get_metadata($ID, 'plugin structodt');
        $template = $meta['odt'];
        $pid = $INPUT->str('pid');

        $tmp_file = $this->renderODT($pid, $template);
        $this->sendODTFile($tmp_file, noNS($pid));
    }

    /**
     * Render ODT file from template
     *
     * @param $pid
     *
     * @return string
     */
    protected function renderODT($pid, $template) {
        global $conf;

        $file = mediaFN($template);

        return $file;

        //$cachefile = tempnam($conf['tmpdir'] . '/structodt', 'structodt_');
    }

    protected function sendODTFile($tmp_file, $filename) {
        header('Content-Type: application/odt');
        header('Content-Disposition: attachment; filename="' . $filename . '.odt";');

        http_sendfile($tmp_file);

        $fp = @fopen($tmp_file, "rb");
        if($fp) {
            http_rangeRequest($fp, filesize($tmp_file), 'application/odt');
        } else {
            header("HTTP/1.0 500 Internal Server Error");
            print "Could not read file - bad permissions?";
        }
        exit();
    }

}

// vim:ts=4:sw=4:et:
