<?php

class renderer_plugin_structodt extends Doku_Renderer {
    /**
     * Returns the format produced by this renderer.
     *
     * @return string always 'odt'
     */
    public function getFormat() {
        return 'odt';
    }

    /**
     * Render plain text data
     *
     * @param $text
     */
    function cdata($text) {
        $this->doc .= $text;
    }

    /**
     * Open a paragraph
     */
    public function p_open() {
//        $this->doc .= '<text:p>';
    }

    /**
     * Close a paragraph
     */
    public function p_close() {
        $this->doc .= '<text:line-break/><text:line-break/>';
    }

    /**
     * Create a line break
     */
    public function linebreak() {
        $this->doc .= '<text:line-break/>';
    }
}
