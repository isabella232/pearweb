<?php
/*
   +----------------------------------------------------------------------+
   | PEAR Web site version 1.0                                            |
   +----------------------------------------------------------------------+
   | Copyright (c) 2001-2005 The PHP Group                                |
   +----------------------------------------------------------------------+
   | This source file is subject to version 2.02 of the PHP license,      |
   | that is bundled with this package in the file LICENSE, and is        |
   | available at through the world-wide-web at                           |
   | http://www.php.net/license/2_02.txt.                                 |
   | If you did not receive a copy of the PHP license and are unable to   |
   | obtain it through the world-wide-web, please send a note to          |
   | license@php.net so we can mail you a copy immediately.               |
   +----------------------------------------------------------------------+
   | Authors: Michael Gauthier <mike@silverorange.com>                    |
   +----------------------------------------------------------------------+
   $Id$
*/

require_once 'site.php';

$doc_languages = array(
    'en' => 'English',
    'fr' => 'French',
    'de' => 'German',
    'ja' => 'Japanese',
    'nl' => 'Dutch',
    'hu' => 'Hungarian',
    // 'it' => 'Italian',
    'pl' => 'Polish',
    'ru' => 'Russian',
    // 'es' => 'Spanish',
);

$NEXT = $PREV = $UP = $HOME = array(false, false);
$TOC = array();

/**
 * Fills global variables with navigational links.
 *
 * @param array $data Array containing home, next, prev, up and toc keys.
 *
 * @return void
 */
function setupNavigation($data)
{
    global $NEXT, $PREV, $UP, $HOME, $TOC, $tstamp;
    $HOME    = @$data['home'];
    $HOME[0] = './';
    $NEXT    = @$data['next'];
    $PREV    = @$data['prev'];
    $UP      = @$data['up'];
    $TOC     = @$data['toc'];
    $tstamp  = gmdate('D, d M Y', getlastmod());
}

function wordWrapTitle($title, $indent = false)
{
    $title     = trim($title, "\t\n :");
    $wrapped   = array($title);
    $maxLength = ($indent) ? 22 : 24;

    if (strlen($title) > $maxLength && strpos($title, '::') !== false) {
        // break long titles on scope operator
        $wrapped = explode('::', $title);
        $partCount = count($wrapped);
        foreach ($wrapped as $i => $piece) {
            if ($i !== ($partCount - 1)) {
                $wrapped[$i] = $wrapped[$i] . '::';
            }
        }
    } elseif (strlen($title) > $maxLength) {
        // word wrap titles
        $wrapped = array();
        while (strlen($title) > $maxLength) {
            $chunk = substr($title, 0, $maxLength);
            $pos = strrpos($chunk, ' ');
            if ($pos === false) {
                $pos = $maxLength;
            }
            $wrapped[] = trim(substr($title, 0, $pos));
            $title = trim(substr($title, $pos));
        }
        $title = trim($title);
        if (strlen($title) > 0) {
            $wrapped[] = $title;
        }
    }

    return $wrapped;
}

function navigationSidebar($id, $thisTitle = '')
{
    global $NEXT, $PREV, $UP, $HOME, $TOC, $DOCUMENT_ROOT;
    global $LANG, $CHARSET;

    echo "\n\n<!-- START MANUAL SIDEBAR -->\n";
    echo "<div class=\"manual-sidebar\" id=\"manual-sidebar\">\n";

    echo " <div class=\"manual-sidebar-top\">\n";
    echo "  <ul>\n";
    echo "   <li>" . make_link('./', $HOME[1]) . "</li>\n";
    echo "  </ul>\n";
    echo " </div>\n\n";

    if (($HOME[1] != $UP[1]) && $UP[1]) {
        echo " <div class=\"manual-sidebar-up\">\n";
        echo "  <ul>\n";
        echo "   <li>" . make_link($UP[0], $UP[1]) . "</li>\n";
        echo "  </ul>\n";
        echo " </div>\n\n";
    }

    echo " <div class=\"manual-sidebar-pages\">\n";
    echo "  <ol>";

    $package_name = getPackageNameForId($id);
    $indent = false;

    $toc_count = count($TOC);
    for ($i = 0; $i < $toc_count; $i++) {
        list($url, $title) = $TOC[$i];
        if (!$url || !$title) {
            continue;
        }

        // decode any entities
        $title_fmt = html_entity_decode($title, ENT_QUOTES, $CHARSET);

        // trim unnecessary duplication of package name in title
        if (!is_null($package_name)) {
            $title_fmt = preg_replace('/^\s*' . $package_name . '[_\w]*::/', '', $title_fmt);
        }

        // word wrap it, get each line as an array element
        $title_parts = wordWrapTitle($title_fmt);

        // encode XML special chars for each line
        foreach ($title_parts as $j => $part) {
            $title_parts[$j] = @htmlspecialchars($part, ENT_QUOTES, $CHARSET);
        }

        // implode it back to a single string
        $title_fmt = implode('<br />', $title_parts);

        // if we're in indentation mode for methods, we have to stop the
        // indentation when we find 'Class Summary'
        if ($indent && substr($title_fmt, 0, 13) == 'Class Summary') {
            echo "</li>\n";
            echo '</ol>';
            $indent = false;
        }

        // No need to spell out the constructor, lets just keep it short
        if (substr($title_fmt, 0, 11) == 'constructor') {
            $title_fmt = 'Constructor';
        }

        // display the title
        $class = ($indent) ? 'manual-sidebar-page-nested' : 'manual-sidebar-page';
        echo "\n" . '   <li class="' . $class . '">'
                . (($url == $id) ? "<strong>$title_fmt</strong>"
                                     : make_link($url, $title_fmt)) . '</li>';

        // after 'Class Summary' (or 'constructor', if 'Class Summary' doesn't
        // exist, we want to indent the methods
        if (    substr($title_fmt, 0, 13) == 'Class Summary'
            || ($title_fmt == 'Constructor' && !$indent)
           ) {
            $indent = true;
            echo '<ol class="manual-sidebar-pages-nested">';
        }
    }

    if ($indent) {
        echo "  </li>\n";
        echo '</ol>';
    }

    echo "\n";

    echo "  </ol>\n";
    echo " </div>\n";

    // if we have a package name, add links to the package and the API docs
    if (!is_null($package_name)) {
        echo "\n";

        echo " <div class=\"manual-sidebar-info\">\n";
        echo "  <ul>\n";
        echo "   <li>" . make_link('/package/' . $package_name, 'Package Info') . "</li>\n";
        echo "   <li>" . make_link('/package/' . $package_name . '/docs/latest/', 'API Documentation') . "</li>\n";
        echo "  </ul>\n";
        echo " </div>\n";
    }

    echo "</div>\n";
    echo "<!-- END MANUAL SIDEBAR -->\n\n";
}

function navigationBar($id, $title, $loc)
{
    global $NEXT, $PREV, $tstamp, $CHARSET;

    $navClass = ($NEXT[1] || $PREV[1]) ?
        'manual-navigation' : 'manual-navigation manual-navigation-no-nav';

    echo "<!-- START MANUAL NAVIGATION -->\n";
    echo "<div class=\"{$navClass}\" id=\"manual-navigation-{$loc}\">\n";

    if ($PREV[1]) {
        $link = $PREV[1];
        if (strlen($link) > 45) {
            $link = str_replace('::', '::<br />', $link);
        }

        // not using make_link because of embedded <span>
        $accesskey = ($loc == 'top') ? ' accesskey="r"' : '';
        echo " <a class=\"manual-previous\" href=\"{$PREV[0]}\"{$accesskey}>";
        echo $link . "\n";
        echo '<span class="title">(P<span class="accesskey">r</span>evious)</span>';
        echo "</a>\n";
    }

    echo "\n";

    if ($NEXT[1]) {
        $link = $NEXT[1];
        if (strlen($link) > 45) {
            $link = str_replace('::', '::<br />', $link);
        }

        // not using make_link because of embedded <span>
        $accesskey = ($loc == 'top') ? ' accesskey="x"' : '';
        echo " <a class=\"manual-next\" href=\"{$NEXT[0]}\"{$accesskey}>";
        echo $link . "\n";
        echo '<span class="title">(Ne<span class="accesskey">x</span>t)</span>';
        echo "</a>\n";
    }

    echo "\n";

    echo " <div class=\"manual-clear\"></div>\n";

    if ($loc == 'bottom') {

        // info and download links
        echo " <div class=\"manual-info\">";
        echo "Last updated: {$tstamp}";
        // UTF-8 em-dash
        echo " \xe2\x80\x94 " . make_link('/manual/', 'Download Documentation');
        echo "</div>\n";

        echo "\n";

        // bug report links
        $package_name = getPackageNameForId($id);
        echo " <div class=\"manual-bug\">\n";
        echo '  Do you think that something on this page is wrong?';
        echo '  Please <a href="' . getBugReportLink($package_name) . '">file a bug report</a>.';
        echo "\n";
        echo " </div>\n";

        echo "\n";

        // language chooser
        global $LANGUAGES, $LANG;
        $langs = array();
        foreach ($LANGUAGES as $code => $name) {
            if (file_exists("../$code/$id")) {
                $langs[] = array(
                    'code'  => $code,
                    'title' => $name,
                    'link'  => make_link("../$code/$id", $name)
                );
            }
        }

        $file = substr($id, 0, -4);
        if (file_exists("html/{$file}.html")) {
            $langs[] = array(
                'code'  => null,
                'title' => 'Plain HTML',
                'link'  => make_link("html/{$file}.html", 'Plain HTML')
            );
        }

        if (count($langs)) {
            echo " <div class=\"manual-languages\">\n";
            echo 'View this page in:';
            echo "  <ul class=\"manual-language-list\">\n";
            $count = 0;
            foreach ($langs as $lang) {
                echo "   <li class=\"manual-language\">";
                if ($count > 0) {
                    // UTF-8 bullet
                    echo " &nbsp;\xe2\x80\xa2&nbsp; ";
                }
                if ($lang['code'] == $LANG) {
                    echo '<strong>' . $lang['title'] . '</strong>';
                } else {
                    echo $lang['link'];
                }
                echo "</li>\n";
                $count++;
            }
            echo "  </ul>\n";
            echo " </div>\n";
        }

        echo "\n";

        // user notes
        echo " <div class=\"manual-notes\" id=\"user-notes\">\n";
        echo "  <h3>User Notes:</h3>\n";
        echo "  " . getComments($id) . "\n";
        echo " </div>\n";
    }

    echo "</div>\n<!-- END MANUAL NAVIGATION -->\n\n";
}

/**
 * Extracts the package name from a given manual page id.
 *
 * @param string $id Docbook chunk id (Manual page id)
 *
 * @return string Package name, null if not found
 */
function getPackageNameForId($id)
{
    global $dbh;
    static $package_name = null;  // static variable to avoid multiple queries
    if (is_null($package_name)) {
        $res = preg_match('/^package\.[\w-]+\.([\w-]+).*\.php$/', $id, $matches);
        if ($res === 1) {
            $package = str_replace('-', '_', $matches[1]);
            $query = 'SELECT name FROM packages WHERE LCASE(name) = LCASE(?)';
            $package_name = $dbh->getOne($query, $package);
        }
    }
    return $package_name;
}

/**
 * Returns the URI to report a bug for the package
 *
 * @param string $package_name Package name, may be NULL for
 *                             documentation bugs
 *
 * @return string URI
 */
function getBugReportLink($package_name)
{
    $bug_report_link = '/bugs/report.php?package=Documentation';
    if (!is_null($package_name)) {
        $bug_report_link = '/bugs/report.php?package=' . $package_name;
    }
    return $bug_report_link;
}

/**
 * Generates and returns the notes comments HTML.
 *
 * @param string $uri Manual page id
 *
 * @return string HTML
 */
function getComments($uri)
{
    $output = '';

    require_once 'notes/ManualNotes.class.php';
    $manualNotes = new Manual_Notes();
    $comments = $manualNotes->getPageComments($uri, auth_check('pear.dev'));

    if (empty($comments)) {
        $output .= 'There are no user contributed notes for this page.';
    }

    foreach ($comments as $comment) {
        $manualNotes->display($comment);
    }

    return $output;
}

/**
 * Sends HTTP headers for the manual.
 *
 * @internal
 * Sets global $LANG and $CHARSET parameters to the values passed
 * to this function.
 *
 * @param string $charset Charset (encoding) to send
 * @param string $lang    2-letter language code
 *
 * @return void
 */
function sendManualHeaders($charset, $lang)
{
    global $LANG,$CHARSET;
    $LANG = $lang;
    $CHARSET = $charset;
    Header('Cache-Control: public, max-age=600');
    Header('Vary: Cookie');
    Header('Content-Type: text/html;charset=' . $charset);
    Header('Content-Language: ' . $lang);
}

/**
 * Displays HTML headers suitable to display a manual page.
 *
 * @param string $id           ID of the docbook chunk (manual page id)
 * @param string $title        Manual page title
 * @param string $extraHeaders Additional HTML header tags
 *
 * @return void
 */
function manualHeader($id, $title = '', $extraHeaders = null)
{
    global $HTDIG, $CHARSET;

    header('Content-Type: text/html; charset=' . $CHARSET);
    response_header(
        'Manual :: ' . $title,
        false,
        $extraHeaders . getManualHeaderNavLinks()
    );
    //create links to plain html and other languages
    if (!$HTDIG) {
        navigationBar($id, $title, 'top');
    }

    // draw manual sidebar
    navigationSidebar($id, $title);

    // start main manual content
    echo "<div class=\"manual-content\" id=\"manual-content\">\n";
}

/**
 * Display html footer and closing html tag
 *
 * @param string $id    ID of docbook chunk (manual page id)
 * @param string $title Manual page title
 *
 * @return void
 */
function manualFooter($id, $title = '')
{
    echo "</div>\n";

    global $HTDIG;
    if (!$HTDIG) {
        navigationBar($id, $title, 'bottom');
    }

    response_footer();
}

/**
 * Creates HTML <link rel..> tags that are used by user agents
 * that support it.
 *
 * @internal
 * Utilizes global $NEXT, $PREV, $UP, $HOME variables
 *
 * @return string HTML code that should be included in
 *                the HTML <head> tag.
 */
function getManualHeaderNavLinks()
{
    global $NEXT, $PREV, $UP, $HOME;

    $eh = '';
    if (isset($NEXT)) {
        $eh .= ' <link rel="next" href="' . $NEXT[0] . '" title="' . $NEXT[1] . '"/>' . "\n";
    }
    if (isset($PREV)) {
        $eh .= ' <link rel="prev" href="' . $PREV[0] . '" title="' . $PREV[1] . '"/>' . "\n";
    }
    if (isset($UP)) {
        $eh .= ' <link rel="up" href="' . $UP[0] . '" title="' . $UP[1] . '"/>' . "\n";
    }
    if (isset($HOME)) {
        $eh .= ' <link rel="home" href="' . $HOME[0] . '" title="' . $HOME[1] . '"/>' . "\n";
    }
    return $eh;
}
