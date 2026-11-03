<?php
/**
 * DokuWiki Plugin struct (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <it@rid.pl>
 */

// must be run within Dokuwiki
use dokuwiki\File\MediaResolver;
use dokuwiki\Parsing\Parser;
use dokuwiki\plugin\struct\meta\AccessTable;
use dokuwiki\plugin\struct\meta\Schema;
use dokuwiki\plugin\struct\meta\Search;
use dokuwiki\plugin\struct\meta\StructException;
use dokuwiki\plugin\struct\meta\Value;
use dokuwiki\plugin\struct\types\Wiki;
use splitbrain\PHPArchive\FileInfo;
use splitbrain\PHPArchive\Zip;

if(!defined('DOKU_INC')) die();

class helper_plugin_structodt extends DokuWiki_Plugin {

    /**
     * Generate temporary file name and ensure base directory exists
     *
     * @param string $ext
     * @return string
     */
    public function tmpFileName($ext = '') {
        global $conf;

        $baseDir = rtrim(!empty($conf['tmpdir']) ? $conf['tmpdir'] : sys_get_temp_dir(), '/') . '/structodt';
        if (!is_dir($baseDir)) {
            io_mkdir_p($baseDir);
        }

        $unique = bin2hex(random_bytes(8)) . '_' . uniqid();
        $name = $baseDir . '/' . $unique;

        if ($ext) {
            $name .= '.' . ltrim($ext, '.');
        }
        return $name;
    }

    /**
     * Render ODT file from template
     */
    public function renderODT($template, $row) {
        $template_file = mediaFN($template);
        if (empty($template_file) || !file_exists($template_file)) {
            throw new \Exception("Template file not found: " . ($template_file ?: $template));
        }

        // Ścieżka katalogu BEZ kończącego slash dla io_mkdir_p
        $tmp_dir = $this->tmpFileName();
        if (!io_mkdir_p($tmp_dir)) {
            throw new \Exception("Could not create tmp dir: $tmp_dir - bad permissions?", -1);
        }

        $tmp_file = $this->tmpFileName('odt');

        try {
            $template_zip = new Zip();
            $template_zip->open($template_file);
            $template_zip->extract($tmp_dir . '/');

            // Podmiana danych w plikach XML
            $files = ['content.xml', 'styles.xml'];
            foreach ($files as $file) {
                $content_file = $tmp_dir . '/' . $file;
                if (!file_exists($content_file)) continue;

                $content = file_get_contents($content_file);
                if ($content === false) {
                    throw new \Exception("Cannot read: $content_file");
                }

                $content = $this->replace($content, $row);
                file_put_contents($content_file, $content);
            }

            $tmp_zip = new Zip();
            $tmp_zip->create($tmp_file);

            $dirPrefixLen = strlen(rtrim($tmp_dir, '/\\')) + 1;
            foreach ($this->readdir_recursive($tmp_dir) as $file) {
                $relativePath = substr($file, $dirPrefixLen);
                if ($relativePath === false || $relativePath === '') continue;
                $tmp_zip->addFile($file, str_replace('\\', '/', $relativePath));
            }
            $tmp_zip->close();

        } finally {
            if (!empty($tmp_dir) && is_dir($tmp_dir)) {
                $this->rmdir_recursive($tmp_dir);
            }
        }

        return $tmp_file;
    }

    protected function getGotenbergUrl() {
        $url = getenv('GOTENBERG_URL');
        if (!empty($url)) {
            return rtrim($url, '/');
        }
        $confUrl = $this->getConf('gotenberg_url');
        if (!empty($confUrl)) {
            return rtrim($confUrl, '/');
        }
        return 'http://gotenberg:3000';
    }

    /**
     * Render ODT and convert it to PDF via Gotenberg LibreOffice endpoint
     */
    public function renderPDF($template, $row) {
        $odtFile = $this->renderODT($template, $row);
        $pdfFile = $this->tmpFileName('pdf');

        $url = $this->getGotenbergUrl() . '/forms/libreoffice/convert';
        $ch = curl_init($url);
        $postFields = [
            'files' => new \CURLFile($odtFile, 'application/vnd.oasis.opendocument.text', 'document.odt')
        ];

        $fp = fopen($pdfFile, 'w+');
        curl_setopt_array($ch, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_FILE       => $fp,
            CURLOPT_TIMEOUT    => 120,
        ]);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        @unlink($odtFile);

        if (!$success || $httpCode !== 200) {
            @unlink($pdfFile);
            throw new \Exception("Gotenberg conversion failed with HTTP status code $httpCode");
        }

        return $pdfFile;
    }

    /**
     * Merge multiple PDF files into one via Gotenberg PDF engine merge endpoint
     */
    public function concatenate(array $files) {
        $outputPdf = $this->tmpFileName('pdf');
        $url = $this->getGotenbergUrl() . '/forms/pdfengines/merge';

        $postFields = [];
        $index = 0;
        foreach ($files as $file) {
            // Gotenberg merges in alphabetical order of the form filenames
            $formName = sprintf('files_%04d', $index);
            $fileName = sprintf('%04d.pdf', $index++);
            $postFields[$formName] = new \CURLFile($file, 'application/pdf', $fileName);
        }

        $ch = curl_init($url);
        $fp = fopen($outputPdf, 'w+');
        curl_setopt_array($ch, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_FILE       => $fp,
            CURLOPT_TIMEOUT    => 180,
        ]);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$success || $httpCode !== 200) {
            @unlink($outputPdf);
            throw new \Exception("Gotenberg merge failed with HTTP status code $httpCode");
        }

        return $outputPdf;
    }

    /**
     * Send file directly to client and delete the temporary file
     *
     * @param string $tmp_file Path to temporary file
     * @param string $filename Download filename (without extension)
     * @param string $ext File extension ('odt', 'pdf', 'zip')
     */
    public function sendFile($tmp_file, $filename, $ext = 'odt') {
        if (!file_exists($tmp_file) || !is_readable($tmp_file)) {
            header("HTTP/1.0 500 Internal Server Error");
            print "Could not read temporary file.";
            return;
        }

        $mimes = [
            'odt' => 'application/vnd.oasis.opendocument.text',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
        ];
        $mime = $mimes[$ext] ?? 'application/octet-stream';

        $cleanFilename = str_replace(['"', "\r", "\n"], '', $filename);
        $encodedFilename = rawurlencode($cleanFilename . '.' . $ext);

        // Wyczyszczenie wszelkich otwartych buforów wyjściowych DokuWiki
        while (ob_get_level()) {
            ob_end_clean();
        }

        header("Content-Type: $mime");
        header("Content-Disposition: attachment; filename=\"{$cleanFilename}.{$ext}\"; filename*=UTF-8''{$encodedFilename}");
        header("Content-Length: " . filesize($tmp_file));
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Pragma: public");

        // Wysłanie zawartości pliku do klienta
        readfile($tmp_file);

        // Usunięcie pliku tymczasowego
        @unlink($tmp_file);
        exit();
    }

    public function readdir_recursive($path) {
        if (!is_dir($path)) return [];
        $directory = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory);
        $files = [];
        foreach ($iterator as $info) {
            if ($info->isFile()) {
                $files[] = $info->getPathname();
            }
        }
        return $files;
    }

    public function rmdir_recursive($path) {
        if (empty($path) || !is_dir($path)) {
            return;
        }

        $directory = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        @rmdir($path);
    }

    public function getSearch($schemas, &$first_schema) {
        $search = new Search();
        if (!empty($schemas)) {
            foreach ($schemas as $schema) {
                $search->addSchema($schema[0], $schema[1]);
            }
        }
        $search->addColumn('*');

        $registeredSchemas = $search->getSchemas();
        $first_schema = !empty($registeredSchemas) ? reset($registeredSchemas) : null;

        $search->addColumn('%rowid%');
        $search->addColumn('%pageid%');
        $search->addColumn('%title%');
        $search->addColumn('%lastupdate%');
        $search->addColumn('%lasteditor%');

        return $search;
    }

    /**
     * Get rows data, optionally filtered by pid
     *
     * @param string|array $schemas
     * @param Schema $first_schema
     * @return Value[][]
     */
    public function getRows($schemas, &$first_schema, $filters = []) {
        $search = $this->getSearch($schemas, $first_schema);
        foreach ($filters as $filter) {
            $colname = $filter[0];
            $comp    = $filter[1] ?? '=';
            $value   = $filter[2] ?? '';
            $op      = $filter[3] ?? 'AND';
            $search->addFilter($colname, $value, $comp, $op);
        }
        return $search->getRows();
    }

    /**
     * Get single row by pid
     *
     * @param $schemas
     * @param $pid
     * @return Value[]|null
     */
    public function getRow($table, $pid, $rev, $rid) {
        $schema = [$table, ''];
        $ignore = null;
        $search = $this->getSearch([$schema], $ignore);

        if (AccessTable::isTypePage($pid, $rev)) {
            $search->addFilter('%pageid%', $pid, '=');
        } elseif (AccessTable::isTypeSerial($pid, $rev)) {
            $search->addFilter('%pageid%', $pid, '=');
            $search->addFilter('%rowid%', $rid, '=');
        } else {
            $search->addFilter('%rowid%', $rid, '=');
        }

        $result = $search->getRows();
        return $result[0] ?? null;
    }

    public function replace($content, $row) {
        /** @var Value $value */
        foreach ($row as $value) {
            $label = $value->getColumn()->getLabel();
            $pattern = '/@@' . preg_quote($label, '/') . '(?:\[(\d+)\])?@@/';

            $content = preg_replace_callback($pattern, function($matches) use ($value) {
                $dvalue = $value->getDisplayValue();

                // Obsługa indeksowania tablic @@kolumna[0]@@
                if (isset($matches[1])) {
                    $index = (int)$matches[1];
                    if (!is_array($dvalue)) {
                        $dvalue = array_map('trim', explode('|', (string)$dvalue));
                    }
                    if (isset($dvalue[$index])) {
                        return htmlspecialchars((string)$dvalue[$index], ENT_XML1, 'UTF-8');
                    }
                    return 'Array: index out of bound';
                }

                if (is_array($dvalue)) {
                    $escaped = array_map(function($item) {
                        return htmlspecialchars((string)$item, ENT_XML1, 'UTF-8');
                    }, $dvalue);
                    return implode(', ', $escaped);
                }

                // Obsługa składni Wiki
                if ($value->getColumn()->getType() instanceof Wiki) {
                    $Parser = new Parser(new \Doku_Handler());
                    $std_modes = ['linebreak', 'eol'];
                    $modes = [];

                    foreach ($std_modes as $m) {
                        $class = 'dokuwiki\\Parsing\\ParserMode\\' . ucfirst($m);
                        $obj   = new $class();
                        $modes[] = [
                            'sort' => $obj->getSort(),
                            'mode' => $m,
                            'obj'  => $obj
                        ];
                    }

                    foreach ($modes as $mode) {
                        $Parser->addMode($mode['mode'], $mode['obj']);
                    }

                    $instructions = $Parser->parse((string)$dvalue);
                    $Renderer = p_get_renderer('structodt');

                    if ($Renderer) {
                        foreach ($instructions as $instruction) {
                            if (method_exists($Renderer, $instruction[0])) {
                                call_user_func_array([$Renderer, $instruction[0]], $instruction[1] ?: []);
                            }
                        }
                        return $Renderer->doc;
                    }
                }

                return htmlspecialchars((string)$dvalue, ENT_XML1, 'UTF-8');
            }, $content);
        }

        return $content;
    }

    public function rowTemplate($row, $template) {
        return preg_replace_callback('/\$(.*?)\$/', function ($matches) use ($row) {
            $possibleValueTypes = ['getValue', 'getCompareValue', 'getDisplayValue', 'getRawValue'];
            $explode = explode('.', $matches[1], 2);
            $label = $explode[0];
            $valueType = 'getDisplayValue';

            if (isset($explode[1]) && in_array($explode[1], $possibleValueTypes, true)) {
                $valueType = $explode[1];
            }

            foreach ($row as $value) {
                $column = $value->getColumn();
                if ($column->getLabel() === $label) {
                    $res = call_user_func([$value, $valueType]);
                    if (is_array($res)) {
                        return implode(', ', $res);
                    }
                    return (string)$res;
                }
            }
            return '';
        }, $template);
    }
}