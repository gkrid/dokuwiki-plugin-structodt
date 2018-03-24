<?php
/**
 * DokuWiki Plugin structodt (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <it@rid.pl>
 */

use dokuwiki\plugin\struct\meta\Search;
use \splitbrain\PHPArchive\Zip;
use \splitbrain\PHPArchive\FileInfo;

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

        if ($data['key'] != 'template') return;

        $event->preventDefault();
        $event->stopPropagation();

        $media = trim($data['val']);
        resolve_mediaid(getNS($ID), $media, $exists);

        if (!$exists) {
            msg("<strong>structodt</strong>: template file doesn't exist", -1);
            return;
        }

        $data['config']['template'] = $media;
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
        global $INPUT;
        if ($event->data != 'structodt') return;

        $template = $INPUT->str('template');
        $schemas = $INPUT->arr('schema');
        $pid = $INPUT->str('pid');

        $tmp_file = $this->renderODT($template, $schemas, $pid);
        if ($tmp_file) {
            $this->sendODTFile($tmp_file, noNS($pid));
            unlink($tmp_file);
            exit();
        }
    }

    /**
     * Render ODT file from template
     *
     * @param $pid
     *
     * @return string
     */
    protected function renderODT($template, $schemas, $pid) {
        global $conf;

        $template_file = mediaFN($template);
        $tmp_dir = $conf['tmpdir'] . '/structodt/' . uniqid() . '/';
        if (!mkdir($tmp_dir, 0777, true)) {
            msg("could not create tmp dir - bad permissions?", -1);
            return false;
        }

        $template_zip = new Zip();
        $template_zip->open($template_file);
        $template_zip->extract($tmp_dir);

        //do replacements
        $files = array('content.xml', 'styles.xml');
        foreach ($files as $file) {
            $content_file = $tmp_dir . $file;
            $content = file_get_contents($content_file);
            $content = $this->replace($content, $schemas, $pid);
            file_put_contents($content_file, $content);
        }


        $tmp_file = $conf['tmpdir'] . '/structodt/' . uniqid() . '.odt';

        $tmp_zip = new Zip();
        $tmp_zip->create($tmp_file);
        foreach($this->readdir_recursive($tmp_dir) as $file) {
            $fileInfo = FileInfo::fromPath($file);
            $fileInfo->strip(substr($tmp_dir, 1));
            $tmp_zip->addFile($file, $fileInfo);
        }
        $tmp_zip->close();

        //remove temp dir
        $this->rmdir_recursive($tmp_dir);

        return $tmp_file;
    }

    /**
     * Send ODT file using range request
     *
     * @param $tmp_file string path of sending file
     * @param $filename string name of sending file
     */
    protected function sendODTFile($tmp_file, $filename) {
        header('Content-Type: application/odt');
        header('Content-Disposition: attachment; filename="' . $filename . '.odt";');

        http_sendfile($tmp_file);

        $fp = @fopen($tmp_file, "rb");
        if($fp) {
            //we have to remove file before exit
            define('SIMPLE_TEST', true);
            http_rangeRequest($fp, filesize($tmp_file), 'application/odt');
        } else {
            header("HTTP/1.0 500 Internal Server Error");
            print "Could not read file - bad permissions?";
        }
    }

    /**
     * Read directory recursively
     *
     * @param string $path
     * @return array of file full paths
     */
    protected function readdir_recursive($path) {
        $directory = new \RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory);
        $files = array();
        foreach ($iterator as $info) {
            if ($info->isFile()) {
                $files[] = $info->getPathname();
            }
        }

        return $files;
    }

    /**
     * Remove director recursively
     *
     * @param $path
     */
    protected function rmdir_recursive($path) {
        $directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directory,
                                               RecursiveIteratorIterator::CHILD_FIRST);
        foreach($iterator as $file) {
            if ($file->isDir()){
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($path);
    }

    /**
     * Perform template replacements
     *
     * @param string $content
     * @param string $schemas
     * @param string $pid
     * @return string
     */
    protected function replace($content, $schemas, $pid) {
        global $ID;

        $search = new Search();
        if(!empty($schemas)) foreach($schemas as $schema) {
            $search->addSchema($schema[0], $schema[1]);
        }
        $search->addColumn('*');
        $first_schema = $search->getSchemas()[0];
        if ($first_schema->isLookup()) {
            $search->addFilter('%rowid%', $pid, '=');
        } else {
            $search->addFilter('%pageid%', $pid, '=');

            $search->addColumn('%pageid%');
            $search->addColumn('%title%');
            $search->addColumn('%lastupdate%');
            $search->addColumn('%lasteditor%');
        }

        $search->addFilter('pid', $pid, '=');
        $result = $search->execute()[0];

        foreach ($result as $value) {
            $label = $value->getColumn()->getLabel();
            $pattern = '/@@' . preg_quote($label) . '(?:\[(\d+)\])?@@/';
            $content = preg_replace_callback($pattern, function($matches) use ($value) {
                $dvalue = $value->getDisplayValue();
                if (isset($matches[1])) {
                    $index = (int)$matches[1];
                    if (!is_array($dvalue)) {
                        $dvalue = array_map('trim', explode(',', $dvalue));
                    }
                    if (isset($dvalue[$index])) {
                        return $dvalue[$index];
                    }
                    return 'Array: index out of bound';
                }
                if (is_array($dvalue)) {
                    return implode(',', $dvalue);
                }
                return $dvalue;
            }, $content);
        }

        return $content;
    }
}

// vim:ts=4:sw=4:et:
