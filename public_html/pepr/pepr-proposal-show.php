<?php

/**
 * Display details of a particular proposal.
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
 * @package   PEPr
 * @author    Tobias Schlitt <toby@php.net>
 * @author    Daniel Convissor <danielc@php.net>
 * @copyright Copyright (c) 1997-2005 The PHP Group
 * @license   http://www.php.net/license/3_0.txt  PHP License
 * @version   $Id$
 */

/**
 * Obtain the common functions and classes.
 */
require_once 'pepr/pepr.php';

$proposal_id = isset($_GET['id']) ? (int) $_GET['id'] : false;

if (!$proposal_id || !($proposal =& proposal::get($dbh, $proposal_id))) {
    response_header('PEPr :: Details :: Invalid Request');
    echo "<h1>Proposal for</h1>\n";
    report_error('The requested proposal does not exist.');
    response_footer();
    exit;
}

response_header('PEPr :: Details :: ' . htmlspecialchars($proposal->pkg_name));
echo '<h1>Proposal for &quot;' . htmlspecialchars($proposal->pkg_name) . "&quot;</h1>\n";

display_pepr_nav($proposal);

$description = $proposal->getParsedDescription();

?>

<table border="0" cellspacing="0" cellpadding="2" style="width: 100%">

 <tr>
  <th class="headrow" style="width: 50%">&raquo; Metadata</th>
  <th class="headrow" style="width: 50%">&raquo; Status</th>
 </tr>
 <tr>
  <td class="ulcell" valign="top">
   <ul>
    <li>
     Category: <?php echo htmlspecialchars($proposal->pkg_category) ?>
    </li>
    <li>
     Proposer: <?php echo user_link($proposal->user_handle, true) ?>
    </li>
    <li>
     License: <?php echo htmlspecialchars($proposal->pkg_license) ?>
    </li>
   </ul>
  </td>

  <td class="ulcell" valign="top">
   <ul>
    <li>
     Status: <?php echo $proposal->getStatus(true) ?>
    </li>

<?php

if ($proposal->status == 'finished') {
    $proposalVotesSum = ppVote::getSum($dbh, $proposal->id);

    echo '    <li>Result: ';
    if ($proposalVotesSum['all'] >= 5) {
        echo 'Accepted';
    } else {
        echo 'Rejected';
    }
    echo "</li>\n";

    echo '    <li>Sum of Votes: ';
    echo $proposalVotesSum['all'];
    echo ' (' . $proposalVotesSum['conditional'] . ' conditional)';
    echo "</li>\n";
    echo '<li><a href="/search.php?q='.
        urlencode(str_replace('_', ' ', strtolower($proposal->pkg_name))).
        '">Search registered package</a>';
} elseif ($proposal->status == 'vote') {
    // Cron job runs at 4 am
    $pepr_end = mktime(4, 0, 0, date('m', $proposal->vote_date),
                       date('d', $proposal->vote_date),
                       date('Y', $proposal->vote_date));

    if (date('H', $proposal->vote_date) > '03') {
        // add a day
        $pepr_end += 86400;
    }

    if ($proposal->longened_date) {
        $pepr_end += PROPOSAL_STATUS_VOTE_TIMELINE * 2;
    } else {
        $pepr_end += PROPOSAL_STATUS_VOTE_TIMELINE;
    }
    echo '    <li>Voting Will End: ';
    echo format_date($pepr_end);
    echo "</li>\n";
}

?>

   </ul>
  </td>
 </tr>

 <tr>
  <th class="headrow" colspan="2">&raquo; Description</th>
 </tr>
 <tr>
  <td class="textcell" valign="top" colspan="2">
   <?php echo $description; ?>
  </td>
 </tr>

 <tr>
  <th class="headrow" style="width: 50%">&raquo; Dependencies</th>
  <th class="headrow" style="width: 50%">&raquo; Links</th>
 </tr>
 <tr>
  <td class="ulcell" valign="top">
   <ul>

<?php

if (!empty($proposal->pkg_deps)) {
    $list = explode("\n", htmlspecialchars($proposal->pkg_deps));
    echo '<li>';
    echo implode("</li>\n<li>", $list);
    echo "</li>\n";
}

?>

   </ul>
  </td>
  <td class="ulcell" valign="top">
   <ul>

<?php

$proposal->getLinks($dbh);
foreach ($proposal->links as $link) {
    echo '    <li>';
    echo make_link(htmlspecialchars($link->url), $link->getType(true));
    echo "</li>\n";
}

?>

   </ul>
  </td>
 </tr>

 <tr>
  <th class="headrow" style="width: 50%">&raquo; Timeline</th>
  <th class="headrow" style="width: 50%">&raquo; Changelog</th>
 </tr>
 <tr>
  <td class="ulcell" valign="top">
   <ul>
    <li>
     First Draft: <?php echo format_date($proposal->draft_date, 'Y-m-d') ?>
    </li>

<?php

if ($proposal->proposal_date) {
    echo '    <li>';
    echo 'Proposal: ' . format_date($proposal->proposal_date, 'Y-m-d');
    echo "</li>\n";
}

if ($proposal->vote_date) {
    echo '    <li>';
    echo 'Call for Votes: ' . format_date($proposal->vote_date, 'Y-m-d');
    echo "</li>\n";
}

if ($proposal->longened_date) {
    echo '    <li>';
    echo 'Voting Extended: ' . format_date($proposal->longened_date, 'Y-m-d');
    echo "</li>\n";
}

?>

   </ul>
  </td>
  <td class="ulcell" valign="top">

<?php

if ($changelog = @ppComment::getAll($proposal->id, 'package_proposal_changelog')) {
    echo "<ul>\n";
    include_once 'pear-database-user.php';
    foreach ($changelog as $comment) {
        if (!isset($userinfos[$comment->user_handle])) {
            $userinfo[$comment->user_handle] = user::info($comment->user_handle);
        }
        echo '<li><p style="margin: 0em 0em 0.3em 0em; font-size: 90%;">';
        echo htmlspecialchars($userinfo[$comment->user_handle]['name']);
        echo '<br />[' . format_date($comment->timestamp) . ']</p>';

        switch ($proposal->markup) {
            case 'wiki':
                require_once 'Text/Wiki.php';
                $wiki = new Text_Wiki();
                $wiki->disableRule('wikilink');
                echo $wiki->transform($comment->comment);
                break;
            case 'bbcode':
            default:
                require_once 'HTML/BBCodeParser.php';
                $bbparser = new HTML_BBCodeParser(array('filters' => 'Basic,Images,Links,Lists,Extended'));
                echo nl2br($bbparser->qparse($comment->comment));
                break;
        }
        echo "</li>\n";
    }
    echo "</ul>\n";
}

echo "  </td>\n </tr>\n";
echo "</table>\n";

response_footer();
