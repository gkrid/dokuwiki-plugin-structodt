<?php
/**
 * DokuWiki Plugin structodt (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <it@rid.pl>
 */

use dokuwiki\plugin\struct\meta\Schema;
use dokuwiki\plugin\struct\meta\Value;

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
        $controller->register_hook('PLUGIN_STRUCT_CONFIGPARSER_UNKNOWNKEY', 'BEFORE', $this, 'handle_strut_configparser');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_action_act_prerpocess');
    }

    /**
     * Add "template" config key
     *
     * @param Doku_Event $event  event object by reference
     * @param mixed      $param  [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     * @return void
     */

    public function handle_strut_configparser(Doku_Event &$event, $param) {
        $keys = ['template', 'pdf', 'hideform'];

        $key = $event->data['key'];
        $val = trim($event->data['val']);

        if (!in_array($key, $keys)) return;

        $event->preventDefault();
        $event->stopPropagation();

        switch ($key) {
            case 'template':
                $event->data['config'][$key] = array_map('trim', explode(',', $val));
                break;
            case 'pdf':
                $event->data['config'][$key] = (bool) $val;
                if ($event->data['config']) {
                    //check for "unoconv"
                    $val = shell_exec('command -v unoconv');
                    if (empty($val)) {
                        msg('Cannot locate "unoconv". Falling back to ODT mode.', 0);
                        $event->data['config'][$key] = false;
                        break;
                    }
                    //check for "ghostscript"
                    $val = shell_exec('command -v ghostscript');
                    if (empty($val)) {
                        msg('Cannot locate "ghostscript". Falling back to ODT mode.', 0);
                        $event->data['config'][$key] = false;
                        break;
                    }
                }
                break;
            case 'hideform':
                $event->data['config'][$key] = (bool) $val;
                break;
        }
    }

    /**
     * Handle odt export
     *
     * @param Doku_Event $event event object by reference
     * @param mixed $param [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     * @return void
     * @throws \splitbrain\PHPArchive\ArchiveIOException
     * @throws \splitbrain\PHPArchive\FileInfoException
     */

    public function handle_action_act_prerpocess(Doku_Event &$event, $param) {
        global $INPUT;
        if ($event->data != 'structodt') return;

        $method = 'action_' . $INPUT->str('action');
        if (method_exists($this, $method)) {
            call_user_func([$this, $method]);
        }
    }

    /**
     * Render file
     */
    public function action_render() {
        global $INPUT;

        /**
         * @var \helper_plugin_structodt
         */
        $helper = plugin_load('helper', 'structodt');

        $templates = json_decode($INPUT->str('template'));
        $ext = $INPUT->str('filetype');
        if (count($templates) > 1 && $ext != 'pdf') {
            msg("Multiple templates are available only for pdf format.", -1);
            return false;
        }

        $schema = $INPUT->str('schema');
        $pid = $INPUT->str('pid');
        $rev = $INPUT->str('rev');
        $rid = $INPUT->str('rid');

        $row = $helper->getRow($schema, $pid, $rev, $rid);
        if (is_null($row)) {
            msg("Row with id: $pid doesn't exists", -1);
            return false;
        }

        $templates = array_map(function ($template) use ($helper, $row) {
            return $helper->rowTemplate($row, $template);
        }, $templates);

        if (count($templates) == 1) {
            $template = $templates[0];
            $method = 'render' . strtoupper($ext);
            $tmp_file = $helper->$method($template, $row);
            if (!$tmp_file) return;

            if ($pid) {
                $filename = noNS($pid);
            } else {
                $filename = $rid;
            }
        }

        $helper->sendFile($tmp_file, $filename, $ext);
        unlink($tmp_file);
        exit();
    }

    /**
     * Render all files as single PDF
     */
    public function action_renderAll() {
        global $INPUT;

        /**
         * @var \helper_plugin_structodt
         */
        $helper = plugin_load('helper', 'structodt');

        $template_string = $INPUT->str('template_string');
        $schemas = $INPUT->arr('schema');
        $filter = $INPUT->arr('filter');

        /** @var Schema $first_schema */
        $rows = $helper->getRows($schemas, $first_schema, $filter);
        $files = [];
        /** @var Value $row */
        foreach ($rows as $row) {
            $template = $helper->rowTemplate($row, $template_string);
            $tmp_file = $helper->renderPDF($template, $row);
            if (!$tmp_file) {
                array_map('unlink', $files);
                msg('Cannot render bulk pdf', -1);
                return;
            }
            $files[] = $tmp_file;
        }

        //join files
        $tmp_file = $helper->tmpFileName('pdf');
        $cmd = "ghostscript -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -sOutputFile=$tmp_file ";
        $cmd .= implode(' ', $files);
        $cmd .= ' 2>&1';
        exec($cmd, $output, $return_var);
        array_map('unlink', $files);
        if ($return_var != 0) {
            msg("PDF merge error($return_var): " . implode('<br>', $output), -1);
            @unlink($tmp_file);
            return;
        }

        $helper->sendFile($tmp_file, $first_schema->getTranslatedLabel(), 'pdf');
        unlink($tmp_file);
        exit();
    }
}

// vim:ts=4:sw=4:et:
