<?php
/**
 * DokuWiki Plugin structodt (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <it@rid.pl>
 */

use dokuwiki\File\MediaResolver;
use dokuwiki\plugin\struct\meta\Schema;
use dokuwiki\plugin\struct\meta\Value;
use splitbrain\PHPArchive\Zip;

if (!defined('DOKU_INC')) die();

class action_plugin_structodt extends DokuWiki_Action_Plugin {

    protected static $gotenbergHealthy = null;

    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('PLUGIN_STRUCT_CONFIGPARSER_UNKNOWNKEY', 'BEFORE', $this, 'handle_struct_configparser');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_action_act_preprocess');
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

    protected function isGotenbergHealthy() {
        if (self::$gotenbergHealthy !== null) {
            return self::$gotenbergHealthy;
        }

        $url = $this->getGotenbergUrl() . '/health';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 2,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_NOBODY         => true,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        self::$gotenbergHealthy = ($code >= 200 && $code < 300);
        return self::$gotenbergHealthy;
    }

    public function handle_struct_configparser(Doku_Event &$event, $param) {
        $keys = ['template', 'pdf', 'hideform', 'filename'];

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
                if ($event->data['config'][$key]) {
                    if (!$this->isGotenbergHealthy()) {
                        msg('Cannot connect to Gotenberg server at ' . $this->getGotenbergUrl() . '. Falling back to ODT mode.', 0);
                        $event->data['config'][$key] = false;
                    }
                }
                break;
            case 'hideform':
                $event->data['config'][$key] = (bool) $val;
                break;
            case 'filename':
                $event->data['config'][$key] = trim($val);
                break;
        }
    }

    public function handle_action_act_preprocess(Doku_Event &$event, $param) {
        global $INPUT;
        if ($event->data !== 'structodt') return;

        $method = 'action_' . $INPUT->str('action');
        if (method_exists($this, $method)) {
            call_user_func([$this, $method]);
        }
    }

    protected function parseTemplates($raw): array {
        if (empty($raw)) return [];
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_filter(array_map('trim', $decoded));
        }
        return array_filter(array_map('trim', explode(',', $raw)));
    }

    protected function render_single($row, array $templates, $ext = 'pdf'): string {
        global $ID;
        /** @var \helper_plugin_structodt $helper */
        $helper = plugin_load('helper', 'structodt');

        if (empty($templates)) {
            throw new \Exception('No templates specified for rendering');
        }

        $rendered_pages = [];
        $tmp_file = null;

        try {
            foreach ($templates as $template) {
                $template = $helper->rowTemplate($row, $template);
                $resolver = new MediaResolver($ID);
                $template = $resolver->resolveId($template);

                if ($template !== '' && media_exists($template, '', false)) {
                    $mimetype = mimetype($template);
                    if ($mimetype[0] === 'pdf' && $ext === 'pdf') {
                        $rendered_page = $helper->tmpFileName('pdf');
                        copy(mediaFN($template), $rendered_page);
                        $rendered_pages[] = $rendered_page;
                    } elseif ($mimetype[0] === 'odt') {
                        $method = 'render' . strtoupper($ext);
                        if (!method_exists($helper, $method)) {
                            throw new \Exception("Unsupported export format: $ext");
                        }
                        $rendered_pages[] = $helper->$method($template, $row);
                    } else {
                        throw new \Exception('Unknown template file format: ' . $template);
                    }
                }
            }

            if (empty($rendered_pages)) {
                throw new \Exception('No valid template files could be found or rendered.');
            }

            if (count($rendered_pages) > 1) {
                $tmp_file = $helper->concatenate($rendered_pages);
            } else {
                $tmp_file = $rendered_pages[0];
            }

            return $tmp_file;

        } finally {
            if (count($rendered_pages) > 1 || $tmp_file === null) {
                foreach ($rendered_pages as $page) {
                    @unlink($page);
                }
            }
        }
    }

    public function action_render() {
        global $INPUT;
        $extensions = ['pdf', 'odt'];

        /** @var \helper_plugin_structodt $helper */
        $helper = plugin_load('helper', 'structodt');

        $templates = $this->parseTemplates($INPUT->str('template'));
        $ext = $INPUT->str('filetype');

        if (!in_array($ext, $extensions, true)) {
            msg("Unknown file extension: $ext. Available extensions: " . implode(', ', $extensions), -1);
            return false;
        }
        if (count($templates) > 1 && $ext !== 'pdf') {
            msg("Multiple templates are available only for PDF format.", -1);
            return false;
        }

        $schema = $INPUT->str('schema');
        $pid = $INPUT->str('pid');
        $rev = $INPUT->str('rev');
        $rid = $INPUT->str('rid');
        $filename = $INPUT->str('filename');

        try {
            $row = $helper->getRow($schema, $pid, $rev, $rid);
            if (is_null($row)) {
                throw new \Exception("Row with id: $pid doesn't exist");
            }

            $tmp_file = $this->render_single($row, $templates, $ext);

            if (empty($filename)) {
                $filename = empty($pid) ? $rid : noNS($pid);
            } else {
                $filename = $helper->rowTemplate($row, $filename);
            }

            $helper->sendFile($tmp_file, $filename, $ext);
            exit();

        } catch (\Exception $e) {
            msg($e->getMessage(), -1);
            return false;
        }
    }

    public function action_renderAll() {
        global $INPUT;

        /** @var \helper_plugin_structodt $helper */
        $helper = plugin_load('helper', 'structodt');

        $template_string = htmlspecialchars_decode($INPUT->str('template_string'));
        $templates = $this->parseTemplates($template_string);
        $schemas = $INPUT->arr('schema');
        $filter = $INPUT->arr('filter');
        $format = $INPUT->str('format', 'zip');
        $filename = $INPUT->str('filename');

        $files = [];
        $tmp_file = null;

        try {
            /** @var Schema|null $first_schema */
            $rows = $helper->getRows($schemas, $first_schema, $filter);
            if (empty($rows)) {
                msg('No records found to export.', 0);
                return false;
            }

            $usedNames = [];
            $i = 1;
            foreach ($rows as $row) {
                $page_file = $this->render_single($row, $templates);

                $baseName = empty($filename) ? (string)$i : $helper->rowTemplate($row, $filename);
                if (empty($baseName)) {
                    $baseName = (string)$i;
                }

                $targetName = $baseName;
                $counter = 1;
                while (isset($usedNames[$targetName])) {
                    $targetName = $baseName . '_' . $counter++;
                }
                $usedNames[$targetName] = true;

                $files[$targetName] = $page_file;
                $i++;
            }

            // Create target bundle
            if ($format === 'pdf') {
                $tmp_file = $helper->concatenate($files);
            } elseif ($format === 'zip') {
                $tmp_file = $helper->tmpFileName('zip');
                $archive = new Zip();
                $archive->create($tmp_file);
                foreach ($files as $name => $file) {
                    $archive->addFile($file, $name . '.pdf');
                }
                $archive->close();
            } else {
                throw new \Exception("Unsupported export format: $format");
            }

            // Intermediate files are no longer needed - unlink them immediately before exit/stream
            foreach ($files as $file) {
                if ($file && file_exists($file)) {
                    @unlink($file);
                }
            }
            $files = [];

            $exportLabel = ($first_schema instanceof Schema) ? $first_schema->getTranslatedLabel() : 'export';

            // sendFile sends headers, streams the file, deletes $tmp_file and calls exit()
            $helper->sendFile($tmp_file, $exportLabel, $format);
            exit();

        } catch (\Exception $e) {
            msg($e->getMessage(), -1);
            if ($tmp_file && file_exists($tmp_file)) {
                @unlink($tmp_file);
            }
            return false;
        } finally {
            // Clean up any remaining intermediate files if an exception was thrown before sendFile
            foreach ($files as $file) {
                if ($file && file_exists($file)) {
                    @unlink($file);
                }
            }
        }
    }
}