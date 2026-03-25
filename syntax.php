<?php
/**
 * DokuWiki Plugin ExtList (Syntax component)
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 *
 */
if (!defined('DOKU_INC')) die();

class syntax_plugin_extlist extends DokuWiki_Syntax_Plugin
{
    // --- Compatibility for PHP 8.2+: Explicitly declare properties ---
    public $Lexer;
    protected $mode;
    protected $macro_pattern;
    protected $entry_pattern, $match_pattern, $extra_pattern, $exit_pattern;
    protected $hl_lines = array();
    // ------------------------------------------------------------------

    public function getType()
    {   // Syntax Type
        return 'container';
    }

    public function getAllowedTypes()
    {   // Allowed Mode Types
        return array(
            'formatting',
            'substition',
            'disabled',
            'protected',
        );
    }

    public function getPType()
    {   // Paragraph Type
        return 'block';
    }


    protected $stack = array();
    protected $list_class = array(); // store class specified by macro

    // Enable hierarchical numbering for nested ordered lists
    protected $olist_level = 0;
    protected $olist_info = array();

    protected $use_div = true;



    /**
     * Connect pattern to lexer
     */
    public function preConnect()
    {
        // drop 'syntax_' from class name
        $this->mode = substr(get_class($this), 7);

        // macro to specify list class
        $this->macro_pattern = '\n(?: {2,}|\t{1,})~~(?:dl|ol|ul):[\w -]*?~~';

        // list patterns
        $olist_pattern    = '\-?\d+[.:] |-:?'; // ordered list item
        $ulist_pattern    = '\*:?';            // unordered list items
        $dlist_pattern    = ';;?|::?';         // description list item
        $continue_pattern = '\+:?';            // continued contents to the previous item

        $this->entry_pattern = '\n(?: {2,}|\t{1,})'.'(?:'
                       . $olist_pattern .'|'
                       . $ulist_pattern .'|'
                       . $dlist_pattern . ') *';
        $this->match_pattern = '\n(?: {2,}|\t{1,})'.'(?:'
                       . $olist_pattern .'|'
                       . $ulist_pattern .'|'
                       . $dlist_pattern .'|'
                       . $continue_pattern . ') *';

        // continued item content by indentation
        $this->extra_pattern = '\n(?: {2,}|\t{1,})(?![-*;:?+~])';

        $this->exit_pattern  = '\n';
    }

    public function connectTo($mode)
    {
        $this->Lexer->addEntryPattern('[ \t]*'.$this->entry_pattern, $mode, $this->mode);

        // macro syntax to specify class for next list [ul|ol|dl]
        $this->Lexer->addSpecialPattern('[ \t]*'.$this->macro_pattern, $mode, $this->mode);
        $this->Lexer->addPattern($this->macro_pattern, $this->mode);
    }

    public function postConnect()
    {
        // subsequent list item
        $this->Lexer->addPattern($this->match_pattern, $this->mode);
        $this->Lexer->addPattern('  ::? ', $this->mode);  // dt and dd in one line

        // continued list item content, indented by at least two spaces
        $this->Lexer->addPattern($this->extra_pattern, $this->mode);

        // terminate a list block
        $this->Lexer->addExitPattern($this->exit_pattern, $this->mode);
    }

    public function getSort()
    {   // sort number used to determine priority of this mode
        return 9; // just before listblock (10)
    }

    /**
     * interpret match string and returns markup information
     */
    protected function interpret($match)
    {
        // depth: count double spaces indent after '\n'
        $depth = substr_count(str_replace("\t", '  ', ltrim($match,' ')),'  ');
        $match = trim($match);

        $m = array('depth' => $depth);

        // check order list markup with number
        if (preg_match('/^(-?\d+)([.:])/', $match, $matches)) {
            $m += array(
                    'mk' => ($matches[2] == '.') ? '-' : '-:',
                    'list' => 'ol', 'item' => 'li', 'num' => $matches[1]
            );
            if ($matches[2] == ':') $m += array('p' => 1);
        } else {
            $m += array('mk' => $match);

            switch (substr($match, 0, 1)) {
                case '' :
                    $m += array('list' => NULL, 'item' => NULL);
                    break;
                case '+':
                    $m += array('list' => NULL, 'item' => NULL);
                    if ($match == '+:') {
                        $m += array('p' => 1);
                    } else {
                        $m['mk'] = '';
                    }
                    break;
                case '-': // ordered list
                    $m += array('list' => 'ol', 'item' => 'li');
                    if ($match == '-:') $m += array('p' => 1);
                    break;
                case '*': // unordered list
                    $m += array('list' => 'ul', 'item' => 'li');
                    if ($match == '*:') $m += array('p' => 1);
                    break;
                case ';': // description list term
                    $m += array('list' => 'dl', 'item' => 'dt');
                    if ($match == ';') $m += array('class' => 'compact');
                    break;
                case ':': // description list desc
                    $m += array('list' => 'dl', 'item' => 'dd');
                    if ($match == '::') $m += array('p' => 1);
                    break;
            }
        }
        return $m;
    }


    /**
     * check list type has changed
     */
    private function isListTypeChanged($m0, $m1)
    {
        // PHP 8.x compatibility: Use null coalescing and string casting to avoid warnings
        return (strncmp((string)($m0['list'] ?? ''), (string)($m1['list'] ?? ''), 1) !== 0);
    }

    /**
     * compute item marker for nested ordered list
     */
    private function olist_marker($level)
    {
        // PHP 8.x compatibility: Ensure key exists before access
        $num = $this->olist_info[$level] ?? 1;

        if (strpos((string)($this->list_class['ol'] ?? ''), 'alphabet') !== false){
            $modulus = ($num -1) % 26;
            $marker = '&#'.(9372 + $modulus).';';
            return $marker;
        }

        $marker = $this->olist_info[1] ?? 1;
        if ($level == 1) {
            return $marker.'.';
        } else {
            for ($i = 2; $i <= $level; $i++) {
                $marker .= '.'.($this->olist_info[$i] ?? 1);
            }
            return $marker;
        }
    }

    /**
     * store specified class for next list
     */
    private function storeListClass($str)
    {
            $str = trim($str);
            $this->list_class[substr($str,2,2)] = trim(substr($str,5,-2));
    }


    /**
     * write call to renderer
     */
    protected function _writeCall($tag, $attr, $state, $pos, $match, $handler)
    {
        $handler->addPluginCall($this->getPluginName(),
            array($state, $tag, $attr), $state, $pos, $match
        );
        
    }

    /**
     * handle open list
     */
    private function _openList($m, $pos, $match, $handler)
    {
        $tag = $m['list'];
        if ($tag == 'ol') {
            $attr = isset($m['num']) ? 'start="'.$m['num'].'"' : '';
            $this->olist_level++;
        } else {
            $attr = null;
        }
        $class = 'extlist';
        if (isset($this->list_class[$tag])) {
            $class.= ' '.$this->list_class[$tag];
        } else {
            $class.= ' '.$this->getConf($tag.'_class');
        }
        $class = rtrim($class);
        $attr.= !empty($attr) ? ' ' : '';
        $attr.= ' class="'.$class.'"';
        $this->_writeCall($tag,$attr,DOKU_LEXER_ENTER, $pos,$match,$handler);
    }

    /**
     * handle close list
     */
    private function _closeList($m, $pos, $match, $handler)
    {
        $tag = $m['list'];
        if ($tag == 'ol') {
            $this->olist_level--;
        }
        $this->_writeCall($tag,'',DOKU_LEXER_EXIT, $pos,$match,$handler);
    }

    /**
     * handle open list item
     */
    private function _openItem($m, $pos, $match, $handler)
    {
        $tag = $m['item'];
        $attr = '';
        switch ($m['mk']) {
            case '-':
            case '-:':
                if (isset($m['num'])) {
                    $this->olist_info[$this->olist_level] = $m['num'];
                    $attr = ' value="'.$m['num'].'"';
                }
                $lv = $this->olist_level;
                $attr.= ' data-marker="'.$this->olist_marker($lv).'"';
                break;
            case ';':
                $attr = 'class="'.($m['class'] ?? '').'"';
                break;
        }
        $this->_writeCall($tag,$attr,DOKU_LEXER_ENTER, $pos,$match,$handler);
    }

    /**
     * handle close list item
     */
    private function _closeItem($m, $pos, $match, $handler)
    {
        $tag = $m['item'];
        $this->_writeCall($tag,'',DOKU_LEXER_EXIT, $pos,$match,$handler);
    }

    /**
     * handle open list item wrapper
     */
    private function _openWrapper($m, $pos, $match, $handler)
    {
        switch ($m['mk']) {
            case ';':
            case ';;':
                $tag = 'span'; $attr = '';
                break;
            case ':':
            case '::':
                return;
                break;
            default:
                if (!$this->use_div) return;
                $tag = 'div';  $attr = 'class="li"';
                break;
        }
        $this->_writeCall($tag,$attr,DOKU_LEXER_ENTER, $pos,$match,$handler);
    }

    /**
     * handle close list item wrapper
     */
    private function _closeWrapper($m, $pos, $match, $handler)
    {
        switch ($m['mk']) {
            case ';':
            case ';;':
                $tag = 'span';
                break;
            case ':':
            case '::':
                return;
                break;
            default:
                if (!$this->use_div) return;
                $tag = 'div';
                break;
        }
        $this->_writeCall($tag,'',DOKU_LEXER_EXIT, $pos,$match,$handler);
    }

    /**
     * handle open paragraph
     */
    private function _openParagraph($pos, $match, $handler)
    {
        $this->_writeCall('p','',DOKU_LEXER_ENTER, $pos,$match,$handler);
    }

    /**
     * handle close paragraph
     */
    private function _closeParagraph($pos, $match, $handler)
    {
        $this->_writeCall('p','',DOKU_LEXER_EXIT, $pos,$match,$handler);
    }


    /**
     * handle parser match
     */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        switch ($state) {
        case DOKU_LEXER_SPECIAL:
            $this->storeListClass($match);
            break;

        case DOKU_LEXER_ENTER:
            $m1 = $this->interpret($match);
            if (($m1['list'] == 'ol') && !isset($m1['num'])) {
                $m1['num'] = 1;
            }
 
            $this->_openList($m1, $pos,$match,$handler);
            $this->_openItem($m1, $pos,$match,$handler);
            $this->_openWrapper($m1, $pos,$match,$handler);
            if (isset($m1['p'])) $this->_openParagraph($pos,$match,$handler);

            array_push($this->stack, $m1);
            break;

        case DOKU_LEXER_UNMATCHED:
            $handler->base($match, $state, $pos);
            break;

        case DOKU_LEXER_EXIT:
            $this->list_class = array();
            // fall through

        case DOKU_LEXER_MATCHED:
            if (substr($match, -2) == '~~') {
                $this->storeListClass($match);
                break;
            }

            $m0 = array_pop($this->stack);
            $m1 = $this->interpret($match);

            // DT and DD in one line
            if ($m0 && ($m1['depth'] == 0) && (($m0['item'] ?? '') == 'dt')) {
                $m1['depth'] = $m0['depth'];
            }

            // check indentation
            if (empty($m1['mk']) && ($m1['depth'] > 0)) {
                $handler->base("\n",  DOKU_LEXER_UNMATCHED, $pos);
                if ($m0) array_push($this->stack, $m0);
                break;
            }

            if ($m0 && isset($m0['p'])) $this->_closeParagraph($pos,$match,$handler);

            if ($m0 && ($m1['mk'] == '+:')) {
                if ($m0['depth'] > $m1['depth']) {
                    $this->_closeWrapper($m0, $pos,$match,$handler);
                } else {
                    $m1['depth'] = min($m0['depth'], $m1['depth']);
                }
                $m0['p'] = 1;
            } else if ($m0) {
                if ($m0['depth'] >= $m1['depth']) {
                    $this->_closeWrapper($m0, $pos,$match,$handler);
                }
            }

            // close items/lists if indent depth has decreased
            while ($m0 && isset($m0['depth']) && ($m0['depth'] > $m1['depth'])) {
                $this->_closeItem($m0, $pos,$match,$handler);
                $this->_closeList($m0, $pos,$match,$handler);
                $m0 = array_pop($this->stack);
            }

            if ($state == DOKU_LEXER_EXIT) {
                break;
            }

            // continued list item content with paragraph
            if ($m1['mk'] == '+:') {
                $this->_openParagraph($pos,$match,$handler);
                $m1['depth'] = $m0['depth'] ?? 0;
                $m1 = $m0 + array('p' => 1);
                array_push($this->stack, $m1);
                break;
            }

            // open list/item if depth/markup has changed
            if ($m0 && ($m0['depth'] < $m1['depth'])) {
                array_push($this->stack, $m0);
            } else if ($m0 && ($m0['depth'] == $m1['depth'])) {
                $this->_closeItem($m0, $pos,$match,$handler);
                if ($this->isListTypeChanged($m0, $m1)) {
                    $this->_closeList($m0, $pos,$match,$handler);
                    $m0['num'] = 0;
                }
            }

            if (!$m0 || ($m0['depth'] < $m1['depth']) || (isset($m0['num']) && ($m0['num'] === 0))) {
                if (isset($m1['num']) && !is_numeric($m1['num'])) $m1['num'] = 1;
                $this->_openList($m1, $pos,$match,$handler);
            } else {
                if (isset($m1['num']) && !is_numeric($m1['num'])) $m1['num'] = ($m0['num'] ?? 0) + 1;
            }

            $this->_openItem($m1, $pos,$match,$handler);
            $this->_openWrapper($m1, $pos,$match,$handler);
            if (isset($m1['p'])) $this->_openParagraph($pos,$match,$handler);

            array_push($this->stack, $m1);
            break;
        }
        return false;
    }

    /**
     * render output
     */
    public function render($format, Doku_Renderer $renderer, $data)
    {
        if ($format == 'xhtml') {
            return $this->render_xhtml($renderer, $data);
        }
        return false;
    }

    /**
     * render xhtml output
     */
    protected function render_xhtml(Doku_Renderer $renderer, $data)
    {
        list($state, $tag, $attr) = $data;
        switch ($state) {
            case DOKU_LEXER_ENTER:
                $renderer->doc.= $this->_open($tag, $attr);
                break;
            case DOKU_LEXER_MATCHED:
            case DOKU_LEXER_UNMATCHED:
                $renderer->cdata($tag);
                break;
            case DOKU_LEXER_EXIT:
                $renderer->doc.= $this->_close($tag);
                break;
        }
        return true;
    }

    /**
     * open a tag, a utility for render_xhtml()
     */
    protected function _open($tag, $attr = null)
    {
        if (!empty($attr)) $attr = ' '.$attr;
        list($before, $after) = $this->_tag_indent($tag);
        return $before.'<'.$tag.$attr.'>'.$after;
    }

    /**
     * close a tag, a utility for render_xhtml()
     */
    protected function _close($tag)
    {
        list($before, $after) = $this->_tag_indent('/'.$tag);
        return $before.'</'.$tag.'>'.$after;
    }

    /**
     * prefix and suffix of html tags
     *
     * Initialize this array only once instead of each time the method _tag_indent()
     * is called.
     */
    protected $indent = [
            'ol'  => [NL,NL],   '/ol'  => ['',NL],
            'ul'  => [NL,NL],   '/ul'  => ['',NL],
            'dl'  => [NL,NL],   '/dl'  => ['',NL],
            'li'  => ['  ',''], '/li'  => ['',NL],
            'dt'  => ['  ',''], '/dt'  => ['',NL],
            'dd'  => ['  ',NL], '/dd'  => ['',NL],
            'p'   => [NL,''],   '/p'   => ['',NL],
            'div' => [NL,''],   '/div' => ['',NL],
        ];

    /**
     * helper to get tag indent
     */
    private function _tag_indent($tag)
    {
        return $this->indent[$tag] ?? ['',''];
    }
}
