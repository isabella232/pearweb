<?php
/*
   +----------------------------------------------------------------------+
   | PEAR Web site version 1.0                                            |
   +----------------------------------------------------------------------+
   | Copyright (c) 2001-2006 The PHP Group                                |
   +----------------------------------------------------------------------+
   | This source file is subject to version 2.02 of the PHP license,      |
   | that is bundled with this package in the file LICENSE, and is        |
   | available at through the world-wide-web at                           |
   | http://www.php.net/license/3_01.txt.                                 |
   | If you did not receive a copy of the PHP license and are unable to   |
   | obtain it through the world-wide-web, please send a note to          |
   | license@php.net so we can mail you a copy immediately.               |
   +----------------------------------------------------------------------+
   | Authors: Pierre-Alain Joye <pajoye@php.net>                          |
   +----------------------------------------------------------------------+
   $Id$
*/

require_once 'pepr/pepr.php';

function rss_bailout() 
{
    header('HTTP/1.0 404 Not Found');
    echo "<h1>The requested URL " . ((filter_var($_SERVER['REQUEST_URI'], FILTER_SANITIZE_STRING))) . " was not found on this server.</h1>";
    exit();
}

/* if file is given, the file will be used to store the rss feed */
function rss_create($items, $channel_title, $channel_description, $dest_file = false)
{
    if (is_array($items) && count($items) > 0) {

        $rss_top = '<?xml version="1.0" encoding="iso-8859-1"?>
<rdf:RDF
    xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
    xmlns="http://purl.org/rss/1.0/"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
>
    <channel rdf:about="http://' . PEAR_CHANNELNAME . '">
    <link>http://' . PEAR_CHANNELNAME . '/</link>
    <dc:creator>' . PEAR_WEBMASTER_EMAIL . '</dc:creator>
    <dc:publisher>' . PEAR_WEBMASTER_EMAIL . '</dc:publisher>
    <dc:language>en-us</dc:language>';

        $items_xml = "<items>
<rdf:Seq>";
        $item_entries = '';

        foreach ($items as $item) {
            if (is_int($item['releasedate'])) {
                $date = date("Y-m-d\TH:i:s-05:00", $item['releasedate']);
            } else {
                $date = date("Y-m-d\TH:i:s-05:00", strtotime($item['releasedate']));
            }

            /* allows to override the default link */
            if (!isset($item['link'])) {
                $url = 'http://' . PEAR_CHANNELNAME . '/package/' . $item['name'] . '/download/' . $item['version'] . '/';
            } else {
                $url = $item['link'];
            }

            if (!empty($item['version'])) {
                $title = $item['name'] . ' ' . $item['version'];
            } else {
                $title = $item['name'];
            }

            $items_xml .= '<rdf:li rdf:resource="' . $url . '"/>' . "\n";
            $item_entries .= "<item rdf:about=" . '"' .$url . '"' . ">
 <title>$title</title>
 <link>$url</link>
 <content:encoded>" .  htmlspecialchars(nl2br($item['releasenotes'])) ."
 </content:encoded>
 <dc:date>$date</dc:date>
 <dc:publisher>" . htmlspecialchars($item['doneby']) . "</dc:publisher>
</item>\n";
            $item_entries .= "";
        }

        $items_xml .= "</rdf:Seq>
</items>\n";

        $rss_feed = $rss_top . $items_xml ."
<title>$channel_title</title>
<description>$channel_description</description>
</channel>

$item_entries
</rdf:RDF>";

        if ($dest_file && (!file_exists($dest_file) || filemtime($dest_file) < (time() - $timeout))) {
            $stream = fopen($url, 'r');
            $tmpf = tempnam('/tmp', 'RSS');
            // Note the direct write from the stream here
            file_put_contents($tmpf, $stream);
            fclose($stream);
            rename($tmpf, $dest_file);
        }
        header("Content-Type: text/xml; charset=iso-8859-1");
        echo $rss_feed;
    } else {
        rss_bailout();
    }
}

$url_redirect = isset($_SERVER['REDIRECT_SCRIPT_URL']) ? $_SERVER['REDIRECT_SCRIPT_URL'] : '';

if (!empty($url_redirect)) {
    $url_redirect = str_replace(array('/feeds/', '.rss'), array('', ''), $url_redirect);
    $elems = explode('_', $url_redirect);
    $type = $elems[0];
    $argument = htmlentities(strip_tags(str_replace($type . '_', '', $url_redirect)));
} else {
    $uri = $_GET['type'];
    $elems = explode('_', $uri);
    $type = $elems[0];
    $argument = htmlentities(strip_tags(str_replace($type . '_', '', $uri)));
}

switch ($type) {
case 'latest':
    include_once 'pear-database-release.php';
    $items = release::getRecent(10);
    $channel_title = 'PEAR: Latest releases';
    $channel_description = 'The latest releases in PEAR.';
    break;

case 'popular':
    include_once 'pear-database-release.php';
    $items = release::getPopular(10, true);
    foreach ($items as $i => $item) {
        $items[$i]['releasenotes'] = 'Downloads per day: ' . number_format($item['releasenotes'], 2);
    }
    $channel_title = 'PEAR: Popular releases';
    $channel_description = 'The most popular releases in PEAR.';
    break;

case 'bug' :
    $_REQUEST = array('id' => $argument, 'format' => 'rss');
    include dirname(dirname(__FILE__)) . '/bugs/rss/bug.php';
    exit;

case 'user':
    $user = $argument;
    include_once 'pear-database-user.php';
    if (!user::exists($user)) {
        rss_bailout();
    }

    $name = user::info($user, "name");
    $channel_title = "PEAR: Latest releases for " . $user;
    $channel_description = "The latest releases for the PEAR developer " . $user . " (" . $name['name'] . ")";
    $items = user::getRecentReleases($user);
    break;

case 'pkg':
    $package = $argument;
    include_once 'pear-database-package.php';
    if (package::isValid($package) == false) {
        rss_bailout();
        return PEAR::raiseError("The requested URL " . $_SERVER['REQUEST_URI'] . " was not found on this server.");
    }

    $channel_title = "Latest releases of " . $package;
    $channel_description = "The latest releases for the package " . $package;

    $items = package::getRecent(10, $package);
    break;

case 'cat':
    $category = $argument;
    include_once 'pear-database-category.php';
    if (category::isValid($category) == false) {
        rss_bailout();
    }

    $channel_title = "PEAR: Latest releases in category " . $category;
    $channel_description = "The latest releases in the category " . $category;

    $items = category::getRecent(10, $category);
    break;

case 'pepr':
    if ($argument=='pepr') {
        $channel_title = "PEPr: Latest proposals.";
        $channel_description = "The latest PEPr proposals.";
        $obj_items = proposal::getAll($dbh, null, 10);
    } elseif (isset($proposalStatiMap[$argument])) {
        $channel_title = "PEPr: Latest proposals with status " . $proposalStatiMap[$argument];
        $channel_description = "The latest PEPr proposals with status " . $proposalStatiMap[$argument];
        $obj_items = proposal::getAll($dbh, $argument, 10);
    } elseif (substr($argument, 0, 6) == 'search') {
        $searchString = substr($argument, 7);
        $channel_title = "PEPr: Latest proposals containing " . $searchString;
        $channel_description = "The latest PEPr proposals containing " . $searchString;
        $obj_items = proposal::search($searchString);
    } else {
        rss_bailout();
    }

    $items = array();
    foreach ($obj_items as $id => $item) {
        $item = $item->toRSSArray();
        $items[] = array(
            'name'         => $item['title'],
            'link'         => $item['link'],
            'releasenotes' => $item['desc'],
            'releasedate'  => (int)$item['date'],
            'version'      => ''
        );
    }
    break;

case 'bugs':
    /* to be done, new bug system supports it */
    rss_bailout();
    break;

default:
    rss_bailout();
    break;
}

// we do not use yet static files. It will be activated with the new backends.
// $file = dirname(__FILE__) . '/' .  $type . '_' . $argument . '.rss';
$file = false;
rss_create($items, $channel_title, $channel_description, $file);
