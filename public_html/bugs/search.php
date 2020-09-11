<?php /* vim: set noet ts=4 sw=4: : */

/**
 * Search for bugs
 *
 * This source file is subject to version 3.0 of the PHP license,
 * that is bundled with this package in the file LICENSE, and is
 * available through the world-wide-web at the following URI:
 * http://www.php.net/license/3_0.txt.
 * If you did not receive a copy of the PHP license and are unable to
 * obtain it through the world-wide-web, please send a note to
 * license@php.net so we can mail you a copy immediately.
 *
 * @category  pearweb
 * @package   Bugs
 * @copyright Copyright (c) 1997-2005 The PHP Group
 * @license   http://www.php.net/license/3_0.txt  PHP License
 * @version   $Id$
 */

// Obtain common includes
require_once './include/prepend.inc';

error_reporting(E_ALL ^ E_NOTICE);

if (!empty($_GET['search_for'])
    && !preg_match('/\\D/', trim($_GET['search_for']))
) {
    if ($auth_user) {
        $x = '&edit=1';
    } else {
        $x = isset($_COOKIE['MAGIC_COOKIE']) ? '&edit=2' : '';
    }
    localRedirect('bug.php?id=' . htmlspecialchars($_GET['search_for']) . $x);
    exit;
}

$newrequest = $_REQUEST;
if (isset($newrequest['PEAR_USER'])) {
    unset($newrequest['PEAR_USER']);
}
if (isset($newrequest['PEAR_PW'])) {
    unset($newrequest['PEAR_PW']);
}
if (isset($newrequest['PHPSESSID'])) {
    unset($newrequest['PHPSESSID']);
}
response_header(
    'Bugs :: Search',
    false,
    '<link rel="alternate" type="application/rdf+xml" title="RSS feed" href="http://'
    . PEAR_CHANNELNAME . '/bugs/rss/search.php?'
    . htmlspecialchars(http_build_query($_REQUEST)) . '" />'
);

$warnings = $errors = array();
$order_options = array(
    ''             => 'relevance',
    'id'           => 'ID',
    'ts1'          => 'date',
    'package_name' => 'package',
    'bug_type'     => 'bug_type',
    'status'       => 'status',
    'package_version'  => 'package_version',
    'php_version'  => 'php_version',
    'php_os'       => 'os',
    'sdesc'        => 'summary',
    'assign'       => 'assignment',
);

$status   = !empty($_GET['status']) ? filter_var($_GET['status'], FILTER_SANITIZE_STRING) : 'Open';
$handle   = !empty($_GET['handle']) ? $_GET['handle'] : '';
$maintain = !empty($_GET['maintain']) ? $_GET['maintain'] : '';
$bug_type = (!empty($_GET['bug_type']) && $_GET['bug_type'] != 'All') ? filter_var($_GET['bug_type'], FILTER_SANITIZE_STRING) : '';
$boolean_search = isset($_GET['boolean']) ? (int)$_GET['boolean'] : 0;
define('BOOLEAN_SEARCH', $boolean_search);
$package_name   = (isset($_GET['package_name'])  && is_array($_GET['package_name']))  ? $_GET['package_name']  : array();
$package_nname  = (isset($_GET['package_nname']) && is_array($_GET['package_nname'])) ? $_GET['package_nname'] : array();

if (isset($_GET['cmd']) && $_GET['cmd'] == 'display') {
    $query = 'SELECT SQL_CALC_FOUND_ROWS bugdb.*, ' .
             ' TO_DAYS(NOW())-TO_DAYS(bugdb.ts2) AS unchanged FROM bugdb' .
             ' LEFT JOIN packages ON packages.name = bugdb.package_name';

    if ($maintain != '' || $handle != '') {
        $query .= ' LEFT JOIN maintains ON packages.id = maintains.package';
        $query .= ' AND maintains.handle = ';
        $query .= $maintain != '' ? $dbh->quoteSmart($maintain) : $dbh->quoteSmart($handle);
        $query .= ' AND maintains.active = 1';
    }

    $where_clause = ' WHERE bugdb.registered IN(';
    $where_clause.= !auth_check('pear.dev') ? '1)' : '1,0)';

    if (!empty($package_name)) {
        $where_clause .= ' AND bugdb.package_name';
        if (count($package_name) > 1) {
            $where_clause .= " IN ('"
                           . join("', '", escapeSQL($package_name))
                           . "')";
        } else {
            $where_clause .= ' = ' . $dbh->quoteSmart($package_name[0]);
        }
    }

    if (!empty($package_nname)) {
        $where_clause .= ' AND bugdb.package_name';
        if (count($package_nname) > 1) {
            $where_clause .= " NOT IN ('"
                           . join("', '", escapeSQL($package_nname))
                           . "')";
        } else {
            $where_clause .= ' <> ' . $dbh->quoteSmart($package_nname[0]);
        }
    }

    /*
     * Ensure status is valid and tweak search clause
     * to treat assigned, analyzed, critical and verified bugs as open
     */
    switch ($status) {
    case 'All':
        break;
    case 'Closed':
    case 'Duplicate':
    case 'Critical':
    case 'Assigned':
    case 'Analyzed':
    case 'Verified':
    case 'Suspended':
    case 'Wont fix':
    case 'No Feedback':
    case 'Feedback':
    case 'Bogus':
        $where_clause .= " AND bugdb.status='$status'";
        break;
    case 'Old Feedback':
        $where_clause .= " AND bugdb.status = 'Feedback'" .
                     ' AND TO_DAYS(NOW()) - TO_DAYS(bugdb.ts2) > 60';
        break;
    case 'Fresh':
        $where_clause .= ' AND bugdb.status NOT IN' .
                     " ('Closed', 'Duplicate', 'Bogus')" .
                     ' AND TO_DAYS(NOW())-TO_DAYS(bugdb.ts2) < 30';
        break;
    case 'Stale':
        $where_clause .= ' AND bugdb.status NOT IN' .
                     " ('Closed', 'Duplicate', 'Bogus')" .
                     ' AND TO_DAYS(NOW()) - TO_DAYS(bugdb.ts2) > 30';
        break;
    case 'Not Assigned':
        $where_clause .= ' AND bugdb.status NOT IN' .
                     " ('Closed', 'Duplicate', 'Bogus', 'Assigned'," .
                     " 'Wont Fix', 'Suspended')";
        break;
        // Closed Reports Since Last Release
    case 'CRSLR':
        if (empty($package_name) || count($package_name) > 1) {
            // Act as ALL
            break;
        }

        // Fetch the last release date
        include_once 'pear-database-package.php';
        $releaseDate = package::getRecent(1, $package_name[0]);
        if (PEAR::isError($releaseDate)) {
            break;
        }

        $where_clause .= ' AND bugdb.status IN' .
                     " ('Closed', 'Duplicate', 'Bogus', 'Wont Fix', 'Suspended')
                               AND (UNIX_TIMESTAMP('" . $releaseDate[0]['releasedate'] . "') < UNIX_TIMESTAMP(bugdb.ts2))
                             ";
        break;
    case 'Open':
        $where_clause .= " AND bugdb.status IN ('Open', 'Assigned'," .
                     " 'Analyzed', 'Critical', 'Verified')";
        break;
    case 'OpenFeedback':
    default:
        $where_clause .= " AND bugdb.status IN ('Open', 'Assigned'," .
                     " 'Analyzed', 'Critical', 'Verified', 'Feedback')";
    }

    if (empty($_GET['search_for'])) {
        $search_for = '';
    } else {
        $search_for = htmlspecialchars(filter_var($_GET['search_for'], FILTER_SANITIZE_STRING));
        list($sql_search, $ignored) = format_search_string($search_for);
        $where_clause .= $sql_search;
        if (count($ignored) > 0 ) {
            $warnings[] = 'The following words were ignored: ' .
                    implode(', ', array_unique($ignored));
        }
    }

    if ($bug_type != '') {
        if ($bug_type == 'Bugs') {
            $where_clause .= ' AND (bugdb.bug_type = "Bug" OR bugdb.bug_type="Documentation Problem")';
        } else {
            $where_clause .= ' AND bugdb.bug_type = ' . $dbh->quoteSmart($bug_type);
        }
    }

    if (empty($_GET['bug_age']) || !(int)$_GET['bug_age']) {
        $bug_age = 0;
    } else {
        $bug_age = (int)$_GET['bug_age'];
        $where_clause .= ' AND bugdb.ts1 >= '
                       . " DATE_SUB(NOW(), INTERVAL $bug_age DAY)";
    }

    if (empty($_GET['bug_updated']) || !(int)$_GET['bug_updated']) {
        $bug_updated = 0;
    } else {
        $bug_updated = (int)$_GET['bug_updated'];
        $where_clause .= ' AND bugdb.ts2 >= '
                       . " DATE_SUB(NOW(), INTERVAL $bug_updated DAY)";
    }

    if (empty($_GET['php_os'])) {
        $php_os = '';
    } else {
        $php_os = $_GET['php_os'];
        $where_clause .= " AND bugdb.php_os LIKE '%"
                       . $dbh->escapeSimple($php_os) . "%'";
    }

    if (empty($_GET['phpver'])) {
        $phpver = '';
    } else {
        $phpver = $_GET['phpver'];
        $where_clause .= " AND bugdb.php_version LIKE '"
                       . $dbh->escapeSimple($phpver) . "%'";
    }

    if (empty($_GET['packagever'])) {
        $packagever = '';
    } else {
        $packagever = $_GET['packagever'];
        $where_clause .= " AND bugdb.package_version LIKE '"
                       . $dbh->escapeSimple($packagever) . "%'";
    }

    if (empty($_GET['handle'])) {
        $handle = '';
        if (empty($_GET['assign'])) {
            $assign = '';
        } else {
            $assign = $_GET['assign'];
            $where_clause .= ' AND bugdb.assign = '
                           . $dbh->quoteSmart($assign);
        }
        if (empty($_GET['maintain'])) {
            $maintain = '';
        } else {
            $maintain = $_GET['maintain'];
            $where_clause .= ' AND maintains.handle = '
                           . $dbh->quoteSmart($maintain);
        }
    } else {
        $handle = $_GET['handle'];
        $where_clause .= ' AND (maintains.handle = '
                       . $dbh->quoteSmart($handle)
                       . ' OR bugdb.assign = '
                       . $dbh->quoteSmart($handle). ')';
    }

    if (empty($_GET['author_email'])) {
        $author_email = '';
    } else {
        $author_email = $_GET['author_email'];
        $qae = $dbh->quoteSmart($author_email);
        $where_clause .= ' AND (bugdb.email = '
                       . $qae . ' OR bugdb.handle=' . $qae . ')';
    }

    $where_clause .= ' AND (packages.package_type = '
                   . $dbh->quoteSmart(SITE);

    if ($pseudo = array_intersect($pseudo_pkgs, $package_name)) {
        $where_clause .= " OR bugdb.package_name";
        if (count($pseudo) > 1) {
            $where_clause .= " IN ('"
                           . join("', '", escapeSQL($pseudo)) . "')";
        } else {
            $where_clause .= " = '" . implode('', escapeSQL($pseudo)) . "'";
        }
    } else {
        $where_clause .= " OR bugdb.package_name IN ('"
                       . join("', '", escapeSQL($pseudo_pkgs)) . "')";
    }

    $where_clause .= ')';

    $query .= $where_clause;

    $direction = $_GET['direction'] != 'DESC' ? 'ASC' : 'DESC';

    if (empty($_GET['order_by'])
        || !array_key_exists($_GET['order_by'], $order_options)
    ) {
        $order_by = 'id';
    } else {
        $order_by = filter_var($_GET['order_by'], FILTER_SANITIZE_STRING);
    }

    if (empty($_GET['reorder_by'])
        || !array_key_exists($_GET['reorder_by'], $order_options)
    ) {
        $reorder_by = '';
    } else {
        $reorder_by = filter_var($_GET['reorder_by'], FILTER_SANITIZE_STRING);
        if ($order_by == $reorder_by) {
            $direction = $direction == 'ASC' ? 'DESC' : 'ASC';
        } else {
            $direction = 'ASC';
            $order_by = $reorder_by;
        }
    }

    $query .= ' ORDER BY ' . $order_by . ' ' . $direction;

    // if status Feedback then sort also after last updated time.
    if ($status == 'Feedback') {
        $query .= ', bugdb.ts2 ' . $direction;
    }

    if (empty($_GET['begin']) || !(int)$_GET['begin']) {
        $begin = 0;
    } else {
        $begin = (int)$_GET['begin'];
    }

    if (empty($_GET['limit']) || !(int)$_GET['limit']) {
        if ($_GET['limit'] == 'All') {
            $limit = 'All';
        } else {
            $limit = 30;
            $query .= " LIMIT $begin, $limit";
        }
    } else {
        $limit  = (int)$_GET['limit'];
        $query .= " LIMIT $begin, $limit";
    }

    if (stristr($query, ';')) {
        $errors[] = 'BAD HACKER!! No database cracking for you today!';
    } else {
        $res  =& $dbh->query($query);
        $rows =  $res->numRows();
        $total_rows =& $dbh->getOne('SELECT FOUND_ROWS()');

        $package_name_string = '';
        if (count($package_name) > 0) {
            foreach ($package_name as $type_str) {
                $package_name_string.= '&amp;package_name[]=' . urlencode($type_str);
            }
        }

        $package_nname_string = '';
        if (count($package_nname) > 0) {
            foreach ($package_nname as $type_str) {
                $package_nname_string.= '&amp;package_nname[]=' . urlencode($type_str);
            }
        }

        $link = 'search.php?cmd=display' .
                $package_name_string  .
                $package_nname_string .
                '&amp;search_for='  . urlencode($search_for) .
                '&amp;php_os='      . urlencode($php_os) .
                '&amp;boolean='     . $boolean_search .
                '&amp;author_email='. urlencode($author_email) .
                '&amp;bug_type='    . $bug_type .
                '&amp;bug_age='     . $bug_age .
                '&amp;bug_updated=' . $bug_updated .
                '&amp;order_by='    . $order_by .
                '&amp;direction='   . $direction .
                '&amp;packagever='  . urlencode($packagever) .
                '&amp;phpver='      . urlencode($phpver) .
                '&amp;limit='       . $limit .
                '&amp;handle='      . urlencode($handle) .
                '&amp;assign='      . urlencode($assign) .
                '&amp;maintain='    . urlencode($maintain);

        if (isset($_GET['showmenu'])) {
            $link .= '&amp;showmenu=1';
        }

        if (!$rows) {
            if (isset($_GET['showmenu'])) {
                show_bugs_menu($package_name, $status, $link . '&amp;showmenu=1');
            } else {
                show_bugs_menu($package_name, $status);
            }
            $errors[] = 'No bugs were found.';
            report_error($errors, 'warnings', '');
        } else {
            report_error($warnings, 'warnings', 'WARNING:');
            if (isset($_GET['showmenu'])) {
                show_bugs_menu($package_name, $status, $link . '&amp;showmenu=1');
            } else {
                show_bugs_menu($package_name, $status);
            }

            $link .= '&amp;status=' . urlencode($status);

            $package_count = count($package_name);
?>

<table class="bug-results">
<?php show_prev_next($begin, $rows, $total_rows, $link, $limit);?>
<?php if ($package_count === 1) { ?>
 <tr>
  <td class="search-prev_next" style="text-align: center;" colspan="9">
<?php
   $pck = htmlspecialchars($package_name[0]);
   echo '  Bugs for <a href="/package/'.$pck.'">'.$pck.'</a>' . "\n";
?>
  </td>
 </tr>
<?php } ?>
 <tr>
  <th class="results"><a href="<?php echo $link;?>&amp;reorder_by=id">ID#</a></th>
  <th class="results"><a href="<?php echo $link;?>&amp;reorder_by=ts1">Date</a></th>
<?php if ($package_count !== 1) { ?>
  <th class="results"><a href="<?php echo $link;?>&amp;reorder_by=package_name">Package</a></th>
<?php } ?>
  <th class="results"><a href="<?php echo $link;?>&amp;reorder_by=bug_type">Type</a></th>
  <th class="results"><a href="<?php echo $link;?>&amp;reorder_by=status">Status</a></th>
  <th class="results"><a href="<?php echo $link;?>&amp;reorder_by=package_version">Package Version</a></th>
  <th class="results"><a href="<?php echo $link;?>&amp;reorder_by=php_version">PHP Version</a></th>
  <th class="results"><a href="<?php echo $link;?>&amp;reorder_by=php_os">OS</a></th>
  <th class="results"><a href="<?php echo $link;?>&amp;reorder_by=sdesc">Summary</a></th>
  <th class="results"><a href="<?php echo $link;?>&amp;reorder_by=assign">Assigned</a></th>
 </tr>
            <?php

            while ($row =& $res->fetchRow(DB_FETCHMODE_ASSOC)) {
                echo ' <tr class="bug-result ' . $tla[$row['status']] . '">' . "\n";

                /* Bug ID */
                echo '  <td class="bug-id"><a href="/bugs/'.$row['id'].'">'.$row['id'].'</a>';
                echo '<br /><a href="bug.php?id='.$row['id'].'&amp;edit=1" class="edit">(edit)</a></td>' . "\n";

                /* Date */
                echo '  <td class="bug-date">'.format_date(strtotime($row['ts1']), 'Y-m-d').'</td>' . "\n";
                if ($package_count !== 1) {
                    $pck = htmlspecialchars($row['package_name']);
                    echo '  <td><a href="/package/'.$pck.'">'.$pck.'</a></td>' . "\n";
                }
                echo '  <td>', htmlspecialchars(@$types[$row['bug_type']]), '</td>' . "\n";
                echo '  <td>', htmlspecialchars($row['status']);
                if ($row['status'] == 'Feedback' && $row['unchanged'] > 0) {
                    printf("<br />%d day%s", $row['unchanged'], $row['unchanged'] > 1 ? 's' : '');
                }
                echo '</td>' . "\n";
                echo '  <td>', htmlspecialchars($row['package_version']), '</td>';
                echo '  <td>', htmlspecialchars($row['php_version']), '</td>';
                echo '  <td>', $row['php_os'] ? htmlspecialchars($row['php_os']) : '&nbsp;', '</td>' . "\n";
                echo '  <td>', $row['sdesc']  ? clean($row['sdesc'])             : '&nbsp;', '</td>' . "\n";
                echo '  <td>', $row['assign'] ? htmlspecialchars($row['assign']) : '&nbsp;', '</td>' . "\n";
                echo " </tr>\n";
            }

            show_prev_next($begin, $rows, $total_rows, $link, $limit);

            echo "</table>\n\n";
        }

        response_footer();
        exit;
    }
}

report_error($errors);
report_error($warnings, 'warnings', 'WARNING:');

?>
<form id="asearch" method="get" action="search.php">
<table id="primary">
<tr valign="top">
  <th>Find bugs</th>
  <td style="white-space: nowrap">with all or any of the w<span class="accesskey">o</span>rds</td>
  <td style="white-space: nowrap"><input type="text" name="search_for" value="<?php echo clean($search_for);?>" size="20" maxlength="255" accesskey="o" />
      <br /><small><?php show_boolean_options($boolean_search) ?>
      (<?php echo make_link('http://bugs.php.net/search-howto.php', '?', '_blank');?>)</small>
  </td>
  <td rowspan="3">
   <select name="limit"><?php show_limit_options($limit);?></select>
   &nbsp;
   <select name="order_by"><?php show_order_options($limit);?></select>
   <br />
   <small>
<input type="radio" name="direction" value="ASC" <?php if ($direction != "DESC") {
        echo 'checked="checked"';
}?>/>Ascending
    &nbsp;
<input type="radio" name="direction" value="DESC" <?php if ($direction == "DESC") {
    echo 'checked="checked"';
}?>/>Descending
   </small>
   <br /><br />
   <input type="hidden" name="cmd" value="display" />
   <label for="submit" accesskey="r">Sea<span class="accesskey">r</span>ch:</label>
   <input id="submit" type="submit" value="Search" />
  </td>
</tr>
<tr valign="top">
  <th>Status</th>
  <td style="white-space: nowrap">
   <label for="status" accesskey="n">Retur<span class="accesskey">n</span> bugs
   with <b>status</b></label>
  </td>
  <td><select id="status" name="status"><?php show_state_options($status);?></select></td>
</tr>
<tr valign="top">
  <th>Type</th>
  <td style="white-space: nowrap">
   <label for="bug_type">Return bugs with <b>type</b></label>
  </td>
  <td><select id="bug_type" name="bug_type"><?php show_type_options($bug_type, true);?></select></td>
</tr>
</table>

<table id="secondary">
<tr valign="top">
  <th><label for="category" accesskey="c">Pa<span class="accesskey">c</span>kage</label></th>
  <td style="white-space: nowrap">Return bugs for these <b>packages</b></td>
  <td><select id="category" name="package_name[]" multiple="multiple" size="6"><?php show_types($package_name, 2);?></select></td>
</tr>
<tr valign="top">
  <th>&nbsp;</th>
  <td style="white-space: nowrap">Return bugs <b>NOT</b> for these <b>packages</b></td>
  <td><select name="package_nname[]" multiple="multiple" size="6"><?php show_types($package_nname, 2);?></select></td>
</tr>
<tr valign="top">
  <th>OS</th>
  <td style="white-space: nowrap">Return bugs with <b>operating system</b></td>
  <td><input type="text" name="php_os" value="<?php echo clean($php_os);?>" /></td>
</tr>
<tr valign="top">
  <th>Version</th>
  <td style="white-space: nowrap">Return bugs reported with <b>Package version</b></td>
  <td><input type="text" name="packagever" value="<?php echo clean($packagever);?>" /></td>
</tr>
<tr valign="top">
  <th>PHP Version</th>
  <td style="white-space: nowrap">Return bugs reported with <b>PHP version</b></td>
  <td><input type="text" name="phpver" value="<?php echo clean($phpver);?>" /></td>
</tr>
<tr valign="top">
  <th>Assigned</th>
  <td style="white-space: nowrap">Return bugs <b>assigned</b> to</td>
  <td><input type="text" name="assign" value="<?php echo clean($assign);?>" />
<?php
if ($auth_user) {
    $u = htmlspecialchars($_REQUEST['PEAR_USER']);
    print "<input type=\"button\" value=\"set to $u\" onclick=\"form.assign.value='$u'\" />";
}
?>
  </td>
</tr>
  <tr valign="top">
  <th>Maintainer</th>
  <td nowrap="nowrap">Return only bugs in packages <b>maintained</b> by</td>
  <td><input type="text" name="maintain" value="<?php echo clean($maintain);?>" />
<?php
if ($auth_user) {
    $u = htmlspecialchars(stripslashes($_REQUEST['PEAR_USER']));
    print "<input type=\"button\" value=\"set to $u\" onclick=\"form.maintain.value='$u'\" />";
}
?>
  </td>
 </tr>
<tr valign="top">
  <th>Author e<span class="accesskey">m</span>ail</th>
  <td style="white-space: nowrap">Return bugs with author email/handle</td>
  <td><input accesskey="m" type="text" name="author_email" value="<?php echo clean($author_email); ?>" />
<?php
if ($auth_user) {
    $u = htmlspecialchars($_REQUEST['PEAR_USER']);
    print "<input type=\"button\" value=\"set to $u\" onclick=\"form.author_email.value='$u'\" />";
}
?>
  </td>
</tr>
<tr valign="top">
  <th>Date</th>
  <td style="white-space: nowrap">Return bugs submitted</td>
  <td><select name="bug_age"><?php show_byage_options($bug_age);?></select></td>
 </tr>
 <tr>
  <td>&nbsp;</td><td style="white-space: nowrap">Return bugs updated</td>
  <td><select name="bug_updated"><?php show_byage_options($bug_updated);?></select></td>
</tr>
</table>
</form>

<?php
response_footer();

function show_prev_next($begin, $rows, $total_rows, $link, $limit)
{
    echo "<!-- BEGIN PREV/NEXT -->\n";
    echo " <tr>\n";
    echo '  <td class="search-prev_next" colspan="10">' . "\n";

    if ($limit=='All') {
        echo "$total_rows Bugs</td></tr>\n";
        return;
    }

    echo '   <table border="0" cellspacing="0" cellpadding="0" width="100%">' . "\n";
    echo "    <tr>\n";
    echo '     <td class="class-prev">';
    if ($begin > 0) {
        echo '<a href="' . $link . '&amp;begin=';
        echo max(0, $begin - $limit);
        echo '">&laquo; Show Previous ' . $limit . ' Entries</a>';
    } else {
        echo '&nbsp;';
    }
    echo "</td>\n";

    echo '     <td class="search-showing">Showing ' . ($begin+1);
    echo '-' . ($begin+$rows) . ' of ' . $total_rows . "</td>\n";

    echo '     <td class="search-next">';
    if ($begin+$rows < $total_rows) {
        echo '<a href="' . $link . '&amp;begin=' . ($begin+$limit);
        echo '">Show Next ' . $limit . ' Entries &raquo;</a>';
    } else {
        echo '&nbsp;';
    }
    echo "</td>\n    </tr>\n   </table>\n  </td>\n </tr>\n";
    echo "<!-- END PREV/NEXT -->\n";
}

function show_order_options($current)
{
    global $order_options;
    foreach ($order_options as $k => $v) {
        echo '<option value="', $k, '"',
             ($v == $current ? ' selected="selected"' : ''),
             '>Sort by ', $v, "</option>\n";
    }
}
