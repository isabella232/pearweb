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
   | Authors: Martin Jansen <mj@php.net>                                  |
   |          Richard Heyes <richard@php.net>                             |
   +----------------------------------------------------------------------+
   $Id$
 */

require_once 'HTML/Form.php';

response_header('Package Statistics');
?>

<h1>Package Statistics</h1>

<script type="text/javascript">
<!--
function reloadMe()
{
    var newLocation = <?php echo json_encode($_SERVER['PHP_SELF']); ?>+'?'
                      + 'cid='
                      + document.forms[1].cid.value
                      + '&pid='
                      + document.forms[1].pid.value
                      + '&rid='
                      + document.forms[1].rid.value;

    document.location.href = newLocation;

}
//-->
</script>

<?php
$_GET['cid'] = isset($_GET['cid']) ? (int) $_GET['cid'] : 0;
$_GET['pid'] = isset($_GET['pid']) ? (int) $_GET['pid'] : 0;
$_GET['rid'] = isset($_GET['rid']) ? (int) $_GET['rid'] : 0;

$query = "SELECT * FROM packages"
         . (!empty($_GET['cid']) ? " WHERE category = '" . $_GET['cid'] . "' AND " : " WHERE ")
         . " packages.package_type = '" . SITE . "'"
         . " ORDER BY name";

$sth = $dbh->query($query);
$packages = array();
while ($row = $sth->fetchRow(DB_FETCHMODE_ASSOC)) {
    $packages[$row['id']] = $row['name'];
}

echo ' <form action="package-stats.php" method="get">'."\n";
echo ' <table>'."\n";
echo '  <caption style="background-color: #CCCCCC;">Select Package</caption>'."\n";
echo '  <tr>'."\n";
echo '  <td>'."\n";
echo '   <select name="cid" onchange="javascript:reloadMe();">'."\n";
echo '    <option value="">Select category ...</option>'."\n";
require_once 'pear-database-category.php';
foreach (category::listAll() as $value) {
    $selected = '';
    if (isset($_GET['cid']) && $_GET['cid'] == $value['id']) {
        $selected = ' selected="selected"';
    }
    echo '<option value="' . $value['id'] . '"' . $selected . '>' . $value['name'] . "</option>\n";
}

echo "  </select>\n";
echo "  </td>\n";
echo "  <td>\n";

if (isset($_GET['cid']) && $_GET['cid'] != '') {
    echo "  <select name=\"pid\" onchange=\"javascript:reloadMe();\">\n";
    echo '    <option value="">Select package ...</option>'."\n";

    foreach ($packages as $value => $name) {
        $selected = '';
        if (isset($_GET['pid']) && $_GET['pid'] == $value) {
            $selected = ' selected="selected"';
        }
        echo '    <option value="' . $value . '"' . $selected . '>' . $name . "</option>\n";
    }

    echo "</select>\n";
} else {
    echo "<input type=\"hidden\" name=\"pid\" value=\"\" />\n";
}

echo "  </td>\n";
echo "  <td>\n";

if (isset($_GET['pid']) && (int)$_GET['pid']) {
    echo "  <select onchange=\"javascript:reloadMe();\" name=\"rid\" size=\"1\">\n";
    echo '   <option value="">All releases</option>'."\n";

    $query = "SELECT id, version FROM releases WHERE package = '" . $_GET['pid'] . "'";
    $rows = $dbh->getAll($query, DB_FETCHMODE_ASSOC);

    usort(
        $lines,
        function ($a, $b) {
            return version_compare($a['version'], $b['version']);
        }
    );
    foreach ($rows as $row) {
        $selected = '';
        if (isset($_GET['rid']) && $_GET['rid'] == $row['id']) {
            $selected = ' selected="selected"';
        }
        echo '    <option value="' . $row['id'] . '"' . $selected . '>' . $row['version'] . "</option>\n";
    }

    echo "  </select>\n";
} else {
    echo '<input type="hidden" name="rid" value="" />'."\n";
}

echo "  </td>\n";
echo '  <td><input type="submit" name="submit" value="Go" /></td>'."\n";
echo "</tr>\n";
echo "</table>\n";
echo "</form>\n";

if (isset($_GET['pid']) && (int)$_GET['pid']) {
    include_once 'pear-database-statistics.php';
    include_once 'pear-database-package.php';
    $info = package::info($_GET['pid'], null, false);

    if (isset($info['releases']) && sizeof($info['releases'])>0) {
        echo '<h2>&raquo; Statistics for Package &quot;<a href="/package/' . $info['name'] . '">' . $info['name'] . "</a>&quot;</h2>\n";
        $td  = "Number of releases: <strong>" . count($info['releases']) . "</strong><br />\n";
        $td .= 'Total downloads: <strong>' . number_format(statistics::package($_GET['pid']), 0, '.', ',') . "</strong><br />\n";
    } else {
        $td = 'No package or release found.';
    }
?>
    <table cellspacing="0" cellpadding="3" style="border: 0px; width: 90%;">
    <caption style="background-color: #CCCCCC;">General Statistics</caption>
    <tr>
     <td style="text-align: left;"><?php echo $td; ?></td>
    </tr>
    </table>

<?php
if (count($info['releases']) > 0) {
?>
    <br />
    <table cellspacing="0" cellpadding="3" style="border: 0px; width: 90%;">
    <caption style="background-color: #CCCCCC;">Release Statistics</caption>
    <tr>
        <th style="text-align: left;">Version</th>
        <th style="text-align: left;">Downloads</th>
        <th style="text-align: left;">Released</th>
        <th style="text-align: left;">Last Download</th>
    </tr>
<?php
$rid = isset($_GET['rid']) ? $_GET['rid'] : '';
$release_statistics = statistics::release($_GET['pid'], $rid);

foreach ($release_statistics as $key => $value) {
    $version = make_link(
        '/package/' . $info['name'] .
        '/download/' . $value['release'], $value['release']
    );
    echo ' <tr>';
    echo '  <td>' . $version . "</td>\n";
    echo '  <td>' . number_format($value['dl_number'], 0, '.', ',');
    echo "  </td>\n";
    echo '  <td>';
    echo format_date(strtotime($value['releasedate']), 'Y-m-d');
    echo "  </td>\n";
    echo '  <td>';
    echo format_date(strtotime($value['last_dl']));
    echo "  </td>\n";
    echo " </tr>\n";
}
echo "</table>\n";

echo '<br />';
// Print the graph
$type   = isset($_GET['type']) && $_GET['type'] == 'bar' ? '&type=bar' : '';
$driver = isset($_GET['driver']) && $_GET['driver'] == 'image' ? '&driver=image' : '';
$url    = sprintf(
    'package-stats-graph.php?pid=%s&amp;releases=%s%s%s',
    (int)$_GET['pid'], isset($_GET['rid']) ? (int)$_GET['rid'] : '', $driver, $type
);
if (isset($_GET['driver']) && $_GET['driver'] == 'gd') {
    echo '<img src="' . $url . '" id="stats_graph" width="743" height="250" alt="" />';
} else {
?>
<div id="svg-container"> <!-- wrapper for the graph object -->
    <!--[if IE]>
     <embed src="<?php echo $url; ?>" type="image/svg+xml" id="stats_graph_svg" width="743" height="250" />
    <![endif]-->
    <!--[if !IE]>-->
     <object data="<?php echo $url; ?>" type="image/svg+xml" id="stats_graph_svg" width="743" height="250">
      You need a browser capeable of SVG to display this image.
     </object>
    <!--<![endif]-->
</div>
<?php
}
// Print the graph control stuff
$releases = $dbh->getAssoc('SELECT id, version FROM releases WHERE package = ' . (int)$_GET['pid']);
natsort($releases);
?>
<br /><br />

<script type="text/javascript">
<!--
    function clearGraphList()
    {
        graphForm = document.forms['graph_control'];
        for (i = 0; i < graphForm.graph_list.options.length; i++) {
            graphForm.graph_list.options[i] = null;
        }
    }

    function addGraphItem()
    {
        graphForm = document.forms['graph_control'];
        selectedRelease = graphForm.releases.options[graphForm.releases.selectedIndex];

        if (selectedRelease.value != "") {
            newText  = 'Release ' + selectedRelease.text;
            newValue = selectedRelease.value;
            graphForm.graph_list.options[graphForm.graph_list.options.length] = new Option(newText, newValue);

        } else {
            alert('Please select a release');
        }
    }

    function removeGraphItem()
    {
        graphForm = document.forms['graph_control'];
        graphList = graphForm.graph_list;

        if (graphList.selectedIndex != null) {
            graphList.options[graphList.selectedIndex] = null;
        }
    }

    function updateGraph()
    {
        graphForm   = document.forms['graph_control'];
        releases_qs = '';

        if (graphForm.graph_list.options.length) {
            for (i = 0; i < graphForm.graph_list.options.length; i++) {
                if (i != 0) {
                    releases_qs += ',';
                }
                releases_qs += graphForm.graph_list.options[i].value;
            }
            graphForm.update.value = 'Updating...';

            var url = 'package-stats-graph.php?pid=<?php echo (int)$_GET['pid']; ?>';
            url += '<?php echo isset($_GET['type']) && $_GET['type'] == 'line' ? '&amp;type=line' : ''; ?>';
            url += '<?php echo isset($_GET['driver']) && $_GET['driver'] == 'image' ? '&amp;driver=image' : ''?>';
            url += '&releases=' + releases_qs;
            var svg  = document.getElementById('stats_graph_svg');
            if (svg != null) {
                // there is an svg element, so we need to destroy and recreate it
                var svgcont = document.getElementById('svg-container');
                if (svg.data != null) {
                    // Non IE
                    svgcont.innerHTML = '<object data="'+url+'" type="image/svg+xml" id="stats_graph_svg" width="743" height="250">You need a browser capeable of SVG to display this image.</object>';
                } else if (svg.src != null) {
                    // IE
                    svgcong.innerHTML = '<embed src="'+url+'" type="image/svg+xml" id="stats_graph_svg" width="743" height="250" />';
                }
            }
            if (document.images['stats_graph'] != null) {
                document.images['stats_graph'].src = url;
            }
            graphForm.update.value = 'Update graph';
        } else {
            alert('Please select one or more releases to show!');
        }
    }
//-->
</script>

<form name="graph_control" action="#" method="get">
 <input type="hidden" name="pid" value="<?php
    if (array_key_exists('pid', $_GET)) {
        echo filter_var($_GET['pid'], FILTER_SANITIZE_STRING);
    }
?>" />
 <input type="hidden" name="rid" value="<?php
    if (array_key_exists('rid', $_GET)) {
        echo filter_var($_GET['rid'], FILTER_SANITIZE_STRING);
    }
?>" />
 <input type="hidden" name="cid" value="<?php
    if (array_key_exists('cid', $_GET)) {
        echo filter_var($_GET['cid'], FILTER_SANITIZE_STRING);
    }
?>" />
 <table border="0">
  <tr>
   <td colspan="2">
    Show graph of:<br />
    <select style="width: 543px" name="graph_list" size="5">
    </select>
   </td>
  </tr>
  <tr>
   <td style="vertical-align: top">
    Release:
    <select name="releases">
     <option value="">Select...</option>
     <option value="0">All</option>
        <?php foreach($releases as $id => $version):?>
      <option value="<?php echo $id; ?>"><?php echo $version; ?></option>
        <?php endforeach?>
    </select>
   </td>
   <td style="text-align: right">
    <input type="submit" style="width: 100px" name="add" value="Add" onclick="addGraphItem(); return false;" />
    <input type="submit" style="width: 100px" name="remove" value="Remove" onclick="removeGraphItem(); return false" />
   </td>
  </tr>
  <tr>
   <td style="text-align: center" colspan="2">
    <input type="submit" name="update" value="Update graph" onclick="updateGraph(); return false" />
   </td>
  </tr>
 </table>
</form>
<br />
<?php
}

/*
 * Category based statistics
 */
} elseif (!empty($_GET['cid'])) {

    $sql = sprintf("SELECT name FROM categories WHERE id = %d", $_GET['cid']);
    $category_name     = $dbh->getOne($sql);
    $sql = sprintf("SELECT COUNT(DISTINCT pid) FROM package_stats ps LEFT JOIN packages p ON p.id = ps.pid WHERE package_type = '" . SITE . "' AND cid = %d", $_GET['cid']);
    $total_packages    = $dbh->getOne($sql);
    $sql = sprintf("SELECT COUNT(DISTINCT pid) FROM package_stats ps, packages p WHERE package_type = '" . SITE . "' AND p.id = ps.pid AND cid = %d", $_GET['cid']);
    $total_packages    = $dbh->getOne($sql);
    $sql = sprintf("SELECT COUNT(DISTINCT m.handle) FROM maintains m, packages p WHERE package_type = '" . SITE . "' AND m.package = p.id AND p.category = %d", $_GET['cid']);
    $total_maintainers = $dbh->getOne($sql);
    $sql = sprintf("SELECT COUNT(*) FROM package_stats ps, packages p WHERE package_type = '" . SITE . "' AND p.id = ps.pid AND cid = %d", $_GET['cid']);
    $total_releases    = $dbh->getOne($sql);
    $sql = sprintf("SELECT COUNT(*) FROM categories WHERE parent = %d", $_GET['cid']);
    $total_categories  = $dbh->getOne($sql);

    // Query to get package list from package_stats_table
    $query = sprintf(
        "SELECT
            SUM(ps.dl_number) AS dl_number,
            ps.package,
            ps.release,
            ps.pid,
            ps.rid,
            ps.cid
        FROM package_stats ps, packages p
        WHERE p.package_type = '" . SITE . "' AND p.id = ps.pid AND
            p.category = %s GROUP BY ps.pid ORDER BY dl_number DESC",
        filter_var($_GET['cid'], FILTER_SANITIZE_STRING)
    );

    /*
    * Global stats
    */
} else {

    $total_packages    = number_format($dbh->getOne('SELECT COUNT(id) FROM packages WHERE package_type = "' . SITE . '" and approved=1'), 0, '.', ',');
    $total_maintainers = number_format($dbh->getOne('SELECT COUNT(DISTINCT handle) FROM maintains m, packages p WHERE package_type = "' . SITE . '" AND m.package = p.id'), 0, '.', ',');
    $total_releases    = number_format($dbh->getOne('SELECT COUNT(*) FROM releases r, packages p WHERE r.package = p.id AND p.package_type = "' . SITE . '"'), 0, '.', ',');
    $total_categories  = number_format($dbh->getOne('SELECT COUNT(*) FROM categories'), 0, '.', ',');
    $total_downloads   = number_format($dbh->getOne('SELECT SUM(dl_number) FROM package_stats, packages p WHERE package_stats.pid = p.id AND p.package_type = "' . SITE . '"'), 0, '.', ',');
    $query = "SELECT SUM(ps.dl_number) AS dl_number, ps.package, ps.pid, ps.rid, ps.cid
        FROM package_stats ps, packages p
        WHERE p.id = ps.pid AND p.package_type = '" . SITE . "'
        GROUP BY ps.pid ORDER BY dl_number DESC";

}

/*
 * Display this for Global and Category stats pages only
 */
if (@!$_GET['pid']) {
    echo '<br />';
    if (empty($_GET['cid'])) {
        $header = 'Global Statistics';
    } else {
        $header  = 'Category Statistics for: ';
        $header .= '<i><a href="packages.php?catpid='.(int)$_GET['cid'];
        $header .= '&amp;catname='.str_replace(' ', '+', $category_name).'">' . $category_name . '</a></i>';
    }
?>
<table border="0" width="90%">
 <tr>
  <th colspan="4" style="background-color: #CCCCCC;"><?php echo $header?></th>
 </tr>
 <tr>
  <td style="width: 25%;">Total&nbsp;Packages:</td>
  <td style="width: 25%; background-color: #CCCCCC; text-align: center;"><?php echo $total_packages; ?></td>
  <td style="width: 25%;">Total&nbsp;Releases:</td>
  <td style="width: 25%; background-color: #CCCCCC; text-align: center;"><?php echo $total_releases; ?></td>
 </tr>
 <tr>
  <td style="width: 25%;">Total&nbsp;Maintainers:</td>
  <td style="width: 25%; background-color: #CCCCCC; text-align: center;"><?php echo $total_maintainers; ?></td>
  <td style="width: 25%;">Total&nbsp;Categories:</td>
  <td style="width: 25%; background-color: #CCCCCC; text-align: center;"><?php echo $total_categories; ?></td>
 </tr>
    <?php
    if (empty($_GET['cid'])) {
         echo " <tr>\n  <td width=\"25%\">Total&nbsp;Downloads:</td>\n";
         echo "  <td style=\"width:25%; text-align:center; background-color: #cccccc\">$total_downloads</td>\n </tr>\n";
    }
    ?>
</table>
<?php
echo '<br />';

$sth  = $dbh->query($query); //$query defined above
$rows = $sth->numRows();

if (PEAR::isError($sth)) {
    PEAR::raiseError('unable to generate stats');
}

?>
<div style="height: 300px; width: 90%; overflow: auto">
    <table style="border: 0; width: 100%" cellpadding="2" cellspacing="2">
        <tr>
            <th colspan="3" style="background-color: #CCCCCC;">Package Statistics</th>
        </tr>
        <tr style="text-align:left; background-color: #cccccc\">
            <th>Package Name</th>
            <th><span class="accesskey"># of downloads</span></th>
            <th>&nbsp;</th>
        </tr>
<?php

$lastPackage = "";

while ($row = $sth->fetchRow(DB_FETCHMODE_ASSOC)) {
    if ($row['package'] == $lastPackage) {
        $row['package'] = '';
    } else {
        $lastPackage = $row['package'];
        $row['package'] = '<a href="/package/' .
            $row['package'] . '">' .
            $row['package'] . "</a>";
    }

    echo "  <tr style=\"background-color: #eeeeee\">\n";
    echo "   <td>" . $row['package'] .  "</td>\n";
    echo "   <td>" . number_format($row['dl_number'], 0, '.', ',') . "</td>\n";
    echo "   <td>[". make_link("/package-stats.php?cid=" . $row['cid'] . "&amp;pid=" . $row['pid'], 'Details') . "]</td>\n";
    echo "  </tr>\n";
}
echo " </table>\n";

echo '</div>';
}

response_footer();
