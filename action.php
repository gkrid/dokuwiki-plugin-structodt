<?php
/**
 * DokuWiki Plugin structodt (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <it@rid.pl>
 */

use dokuwiki\plugin\struct\meta\AccessTable;
use dokuwiki\plugin\struct\meta\Schema;
use dokuwiki\plugin\struct\meta\Search;
use dokuwiki\plugin\struct\meta\StructException;
use dokuwiki\plugin\struct\meta\Value;
use dokuwiki\plugin\structodt\meta\Odt;
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
        $keys = array('template', 'delete', 'pdf');
        $data = $event->data;

        $key = $data['key'];
        $val = trim($data['val']);

        if (!in_array($key, $keys)) return;

        $event->preventDefault();
        $event->stopPropagation();

        switch ($key) {
            case 'template':
                $data['config'][$key] = $val;
                break;
            case 'delete':
                $data['config'][$key] = (bool)$val;
                break;
            case 'pdf':
                if (!$val) {
                    $data['config'][$key] = false;
                } else {
                    //check for "unoconv"
                    $val = shell_exec('command -v unoconv');
                    if (empty($val)) {
                        msg('Cannot locate "unoconv". Falling back to ODT mode.', 0);
                        $data['config'][$key] = false;
                        break;
                    }
                    //check for "ghostscript"
                    $val = shell_exec('command -v ghostscript');
                    if (empty($val)) {
                        msg('Cannot locate "ghostscript". Falling back to ODT mode.', 0);
                        $data['config'][$key] = false;
                        break;
                    }
                    $data['config'][$key] = true;
                }
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
            call_user_func(array($this, $method));
        }
    }


    /**
     * Render file
     */
    protected function action_render() {
        global $INPUT;

        $template = $INPUT->str('template');
        $ext = $INPUT->bool('pdf') ? 'pdf' : 'odt';
        $schemas = $INPUT->arr('schema');
        $pid = $INPUT->str('pid');

        $row = $this->getRow($schemas, $pid);
        if (is_null($row)) {
            msg("Row with id: $pid doesn't exists", -1);
            return false;
        }

        $method = 'render' . strtoupper($ext);
        $tmp_file = $this->$method($template, $row);
        if (!$tmp_file) return;

        $this->sendFile($tmp_file, noNS($pid), $ext);
        unlink($tmp_file);
        exit();
    }

    /**
     * Render all files as single PDF
     */
    protected function action_renderAll() {
        global $INPUT;

        $template_string = $INPUT->str('template_string');
        $schemas = $INPUT->arr('schema');
        $filter = $INPUT->arr('filter');

        /** @var Schema $first_schema */
        $rows = $this->getRows($schemas, $first_schema, $filter);
        $files = [];
        /** @var Value $row */
        foreach ($rows as $pid => $row) {
            $template = Odt::rowTemplate($row, $template_string);
            $tmp_file = $this->renderPDF($template, $row);
            if (!$tmp_file) {
                array_map('unlink', $files);
                msg('Cannot render bulk pdf', -1);
                return;
            }
            $files[] = $tmp_file;
        }

        //join files
        $tmp_file = $this->tmpFileName('pdf');
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

        $this->sendFile($tmp_file, $first_schema->getTranslatedLabel(), 'pdf');
        unlink($tmp_file);
        exit();
    }

    /**
     *
     */
    protected function action_delete() {
        global $INPUT, $ID;
        $tablename = $INPUT->str('schema');
        $pid = $INPUT->int('pid');
        if (!$pid) {
            throw new StructException('No pid given');
        }
        if (!$tablename) {
            throw new StructException('No schema given');
        }
        action_plugin_struct_inline::checkCSRF();

        $schemadata = AccessTable::byTableName($tablename, $pid);
        if (!$schemadata->getSchema()->isEditable()) {
            throw new StructException('lookup delete error: no permission for schema');
        }
        $schemadata->clearData();

        header("Location: " . wl($ID));
    }

    /**
     * Generate temporary file name with full path in temporary directory
     *
     * @param string $ext
     * @return string
     */
    protected function tmpFileName($ext='') {
        global $conf;
        $name = $conf['tmpdir'] . '/structodt/' . uniqid();
        if ($ext) {
            $name .= ".$ext";
        }
        return $name;
    }

    /**
     * Render ODT file from template
     *
     * @param $template
     * @param $schemas
     * @param $pid
     *
     * @return string|bool
     * @throws \splitbrain\PHPArchive\ArchiveIOException
     * @throws \splitbrain\PHPArchive\FileInfoException
     */
    protected function renderODT($template, $row) {
        global $conf;

        $template_file = mediaFN($template);
        $tmp_dir = $this->tmpFileName() . '/';
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
            if ($content === false) {
                msg("Cannot open: $content_file", -1);
                $this->rmdir_recursive($tmp_dir);
                return false;
            }

            $content = $this->replace($content, $row);
            file_put_contents($content_file, $content);
        }


        $tmp_file = $this->tmpFileName('odt');

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
     * Render PDF file from template
     *
     * @param $template
     * @param $schemas
     * @param $pid
     *
     * @return string|bool
     * @throws \splitbrain\PHPArchive\ArchiveIOException
     * @throws \splitbrain\PHPArchive\FileInfoException
     */
    protected function renderPDF($template, $row) {
        $tmp_file = $this->renderODT($template, $row);
        if (!$tmp_file) return false;

        $wd = dirname($tmp_file);
        $bn = basename($tmp_file);
        $cmd = "cd $wd && HOME=$wd unoconv -f pdf $bn 2>&1";
        exec($cmd, $output, $return_var);
        unlink($tmp_file);
        if ($return_var != 0) {
            msg("PDF conversion error($return_var): " . implode('<br>', $output), -1);
            return false;
        }
        //change extension to pdf
        $tmp_file = substr($tmp_file, 0, -3) . 'pdf';

        return $tmp_file;
    }

    /**
     * Send ODT file using range request
     *
     * @param $tmp_file string path of sending file
     * @param $filename string name of sending file
     * $param $ext odt or pdf
     */
    protected function sendFile($tmp_file, $filename, $ext='odt') {
        $mime = "application/$ext";
        header("Content-Type: $mime");
        header("Content-Disposition: attachment; filename=\"$filename.$ext\";");

        http_sendfile($tmp_file);

        $fp = @fopen($tmp_file, "rb");
        if($fp) {
            //we have to remove file before exit
            define('SIMPLE_TEST', true);
            http_rangeRequest($fp, filesize($tmp_file), $mime);
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
     * @param $schemas
     * @param Schema $first_schema
     * @return Search
     */
    protected function getSearch($schemas, &$first_schema) {
        $search = new Search();
        if (!empty($schemas)) foreach ($schemas as $schema) {
            $search->addSchema($schema[0], $schema[1]);
        }
        $search->addColumn('*');
        $first_schema = $search->getSchemas()[0];

        if ($first_schema->isLookup()) {
            $search->addColumn('%rowid%');
        } else {
            $search->addColumn('%pageid%');
            $search->addColumn('%title%');
            $search->addColumn('%lastupdate%');
            $search->addColumn('%lasteditor%');
        }

        return $search;
    }

    /**
     * Get rows data, optionally filtered by pid
     *
     * @param string|array $schemas
     * @param Schema $first_schema
     * @return Value[][]
     */
    protected function getRows($schemas, &$first_schema, $filters=array())
    {
        $search = $this->getSearch($schemas, $first_schema);
        foreach ($filters as $filter) {
            $colname = $filter[0];
            $value = $filter[2];
            $comp = $filter[1];
            $op = $filter[3];
            $search->addFilter($colname, $value, $comp, $op);
        }
        $result = $search->execute();
        $pids = $search->getPids();
        return array_combine($pids, $result);
    }

    /**
     * Get single row by pid
     *
     * @param $schemas
     * @param $pid
     * @return Value[]|null
     */
    protected function getRow($schemas, $pid) {
        /** @var Schema $first_schema */
        $search = $this->getSearch($schemas, $first_schema);
        if ($first_schema->isLookup()) {
            $search->addFilter('%rowid%', $pid, '=');
        } else {
            $search->addFilter('%pageid%', $pid, '=');
        }
        $result = $search->execute();
        if (count($result) != 1) {
            return null;
        }
        return current($result);
    }

    /**
     * Perform $content replacements basing on $row Values
     *
     * @param string $content
     * @param Value[] $row
     * @return string
     */
    protected function replace($content, $row) {
        /** @var Value $value */
        foreach ($row as $value) {
            $label = $value->getColumn()->getLabel();
            $pattern = '/@@' . preg_quote($label) . '(?:\[(\d+)\])?@@/';
            $content = preg_replace_callback($pattern, function($matches) use ($value) {
                $dvalue = $value->getDisplayValue();
                if (isset($matches[1])) {
                    $index = (int)$matches[1];
                    if (!is_array($dvalue)) {
                        $dvalue = array_map('trim', explode('|', $dvalue));
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
