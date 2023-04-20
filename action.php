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

    protected function render_single($row, $templates, $ext='pdf') {
        /**
         * @var \helper_plugin_structodt
         */
        $helper = plugin_load('helper', 'structodt');

        $rendered_pages = [];
        try {
            foreach ($templates as $template) {
                $template = $helper->rowTemplate($row, $template);
                if ($template != '' && media_exists($template, '', false)) {
                    $mimetype = mimetype($template);
                    // pdf templates are available only in pdf output format
                    if ($mimetype[0] == 'pdf' && $ext == 'pdf') {
                        $rendered_page = $helper->tmpFileName('pdf');
                        copy(mediaFN($template), $rendered_page);
                        $rendered_pages[] = $rendered_page;
                    } elseif ($mimetype[0] == 'odt') {
                        $method = 'render' . strtoupper($ext);
                        $rendered_pages[] = $helper->$method($template, $row);
                    } else {
                        throw new \Exception('unknown template file format');
                    }
                }
            }
            if (count($rendered_pages) > 1) {
                $tmp_file = $helper->concatenate($rendered_pages);
                foreach ($rendered_pages as $page) {
                    @unlink($page);
                }
            } else {
                $tmp_file = $rendered_pages[0];
            }
        } catch (\Exception $e) {
            foreach ($rendered_pages as $page) {
                @unlink($page);
            }
            msg($e->getMessage(), -1);
        }
        return $tmp_file;
    }

    /**
     * Render file
     */
    public function action_render() {
        global $INPUT;
        $extensions = ['pdf', 'odt'];

        /**
         * @var \helper_plugin_structodt
         */
        $helper = plugin_load('helper', 'structodt');

        $templates = json_decode($INPUT->str('template'));
        $ext = $INPUT->str('filetype');
        if (!in_array($ext, $extensions)) {
            msg("Unknown file extension: $ext. Avaliable extensions: " . implode(', ', $extensions), -1);
            return false;
        }
        if (count($templates) > 1 && $ext != 'pdf') {
            msg("Multiple templates are available only for pdf format.", -1);
            return false;
        }

        $schema = $INPUT->str('schema');
        $pid = $INPUT->str('pid');
        $rev = $INPUT->str('rev');
        $rid = $INPUT->str('rid');

        try {
            $row = $helper->getRow($schema, $pid, $rev, $rid);
            if (is_null($row)) {
                throw new \Exception("Row with id: $pid doesn't exists");
            }
        } catch (\Exception $e) {
            msg($e->getMessage(), -1);
            return false;
        }

        $tmp_file = $this->render_single($row, $templates, $ext);
        $filename = empty($pid) ? $rid : noNS($pid);
        $helper->sendFile($tmp_file, $filename, $ext);
        @unlink($tmp_file);
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

        $template_string = htmlspecialchars_decode($INPUT->str('template_string'));
        $templates = json_decode($template_string);
        $schemas = $INPUT->arr('schema');
        $filter = $INPUT->arr('filter');

        /** @var Schema $first_schema */
        $rows = $helper->getRows($schemas, $first_schema, $filter);
        $files = [];
        /** @var Value $row */
        foreach ($rows as $row) {
            $tmp_file = $this->render_single($row, $templates);
            $files[] = $tmp_file;
        }

        //join files
        try {
            $tmp_file = $helper->concatenate($files);
        } catch (\Exception $e) {
            msg($e->getMessage(), -1);
            return false;
        } finally {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        $filename = $first_schema->getTranslatedLabel();
        $helper->sendFile($tmp_file, $filename, 'pdf');
        @unlink($tmp_file);
        exit();
    }
}

// vim:ts=4:sw=4:et:
