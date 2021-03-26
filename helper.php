<?php
/**
 * DokuWiki Plugin struct (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <it@rid.pl>
 */

// must be run within Dokuwiki
use dokuwiki\plugin\struct\meta\AccessTable;
use dokuwiki\plugin\struct\meta\Schema;
use dokuwiki\plugin\struct\meta\Search;
use dokuwiki\plugin\struct\meta\StructException;
use dokuwiki\plugin\struct\meta\Value;
use splitbrain\PHPArchive\FileInfo;
use splitbrain\PHPArchive\Zip;

if(!defined('DOKU_INC')) die();

class helper_plugin_structodt extends DokuWiki_Plugin {
    /**
     * Generate temporary file name with full path in temporary directory
     *
     * @param string $ext
     * @return string
     */
    public function tmpFileName($ext='') {
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
    public function renderODT($template, $row) {
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
    public function renderPDF($template, $row) {
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
    public function sendFile($tmp_file, $filename, $ext='odt') {
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
    public function readdir_recursive($path) {
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
    public function rmdir_recursive($path) {
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
    public function getSearch($schemas, &$first_schema) {
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
    public function getRows($schemas, &$first_schema, $filters=array())
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
    public function getRow($table, $pid, $rev, $rid) {
        try {
            if (AccessTable::isTypePage($pid, $rev)) {
                $schemadata = AccessTable::getPageAccess($table, $pid);
            } elseif (AccessTable::isTypeSerial($pid, $rev)) {
                $schemadata = AccessTable::getSerialAccess($table, $pid, $rid);
            } else {
                $schemadata = AccessTable::getGlobalAccess($table, $rid);
            }
            return $schemadata->getData();
        } catch (StructException $ignore) {
            return null;
        }
    }

    /**
     * Perform $content replacements basing on $row Values
     *
     * @param string $content
     * @param Value[] $row
     * @return string
     */
    public function replace($content, $row) {
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

    /**
     * @param $row
     * @param string $template
     * @return string
     */
    public function rowTemplate($row, $template) {
        global $ID;

        //do media file substitutions
        $media = preg_replace_callback('/\$(.*?)\$/', function ($matches) use ($row) {
            $possibleValueTypes = array('getValue', 'getCompareValue', 'getDisplayValue', 'getRawValue');
            list($label, $valueType) = explode('.', $matches[1], 2);
            if (!$valueType || !in_array($valueType, $possibleValueTypes)) {
                $valueType = 'getDisplayValue';
            }
            foreach ($row as $value) {
                $column = $value->getColumn();
                if ($column->getLabel() == $label) {
                    return call_user_func(array($value, $valueType));
                }
            }
            return '';
        }, $template);

        resolve_mediaid(getNS($ID), $media, $exists);
        if (!$exists) {
            msg("<strong>structodt</strong>: template file($media) doesn't exist", -1);
        }
        return $media;
    }
}