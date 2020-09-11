<?php
session_start();
/**
 * User interface for viewing and editing bug details
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

/*
 * NOTE: another require exists in the code below, so if changing
 * the include path, make sure to change it too.
 */

 // Obtain common includes
require_once './include/prepend.inc';

// Get user's CVS password
require_once './include/cvs-auth.inc';

require_once 'bugs/pear-bugs-utils.php';
require_once 'bugs/patchtracker.php';
$pbu = new PEAR_Bugs_Utils();

// Numeral Captcha Class
require_once 'Text/CAPTCHA/Numeral.php';
$numeralCaptcha = new Text_CAPTCHA_Numeral();

Bug_DataObject::init();

if (empty($_REQUEST['id']) || !(int)$_REQUEST['id']) {
    localRedirect('search.php');
    exit;
}

$id   = (int)$_REQUEST['id'];
/**
 * Edit mode
 *  0 - View bug
 *  1 - Edit bug (user authenticated)
 *  2 - Add comment (old code)
 *  3 - Add comment (anonymously)
 * 11 - List patches
 * 12 - Patch details
 * 13 - Add patch
 *
 * @var integer
 */
$edit = (empty($_REQUEST['edit']) || !(int)$_REQUEST['edit']) ?  0 : (int)$_REQUEST['edit'];

if (isset($_GET['unsubscribe'])) {
    $unsubcribe = (int)$_GET['unsubscribe'];

    $hash = isset($_GET['t']) ? $_GET['t'] : false;
    SITE == 'pear' ? $redirect = 'pecl' : $redirect = 'pear';

    if (!$hash) {
        localRedirect('bug.php?id='.$id);
    }

    unsubscribe($id, $hash);
    $_GET['thanks'] = 9;
}

// captcha is not necessary if the user is logged in
if (isset($auth_user) && $auth_user && $auth_user->registered && isset($_SESSION['answer'])) {
    unset($_SESSION['answer']);
}

$trytoforce = isset($_POST['trytoforce']) ? (int)$_POST['trytoforce'] : false;

// fetch info about the bug into $bug
if ($dbh->getOne('SELECT handle FROM bugdb WHERE id=?', array($id))) {
    $query = 'SELECT b.id, b.package_name, b.bug_type, b.email, b.handle as bughandle, b.reporter_name,
        b.passwd, b.sdesc, b.ldesc, b.php_version, b.package_version, b.php_os,
        b.status, b.ts1, b.ts2, b.assign, UNIX_TIMESTAMP(b.ts1) AS submitted,
        users.registered,
        UNIX_TIMESTAMP(b.ts2) AS modified,
        COUNT(bug=b.id) AS votes,
        SUM(reproduced) AS reproduced,SUM(tried) AS tried,
        SUM(sameos) AS sameos, SUM(samever) AS samever,
        AVG(score)+3 AS average,STD(score) AS deviation,
        users.showemail, users.handle, p.package_type, p.id as package_id
        FROM bugdb b
        LEFT JOIN bugdb_votes ON b.id = bug
        LEFT JOIN users ON users.handle = b.handle
        LEFT JOIN packages p ON b.package_name = p.name
        WHERE b.id = ?
        GROUP BY bug';
} else {
    $query = 'SELECT b.id, b.package_name, b.bug_type, b.email, b.handle as bughandle, b.reporter_name,
        b.passwd, b.sdesc, b.ldesc, b.php_version, b.package_version, b.php_os,
        b.status, b.ts1, b.ts2, b.assign, UNIX_TIMESTAMP(b.ts1) AS submitted,
        UNIX_TIMESTAMP(b.ts2) AS modified,
        COUNT(bug=b.id) AS votes,
        SUM(reproduced) AS reproduced,SUM(tried) AS tried,
        SUM(sameos) AS sameos, SUM(samever) AS samever,
        AVG(score)+3 AS average,STD(score) AS deviation,
        users.showemail, users.handle, p.package_type, p.id as package_id,
        1 as registered
        FROM bugdb b
        LEFT JOIN bugdb_votes ON b.id = bug
        LEFT JOIN users ON users.email = b.email
        LEFT JOIN packages p ON b.package_name = p.name
        WHERE b.id = ?
        GROUP BY bug';
}

$bug = $dbh->getRow($query, array($id), DB_FETCHMODE_ASSOC);

if (!$bug) {
    response_header('No Such Bug');
    report_error('No such bug #' . $id);
    response_footer();
    exit;
}

if ($edit == 1) {
    if (isset($auth_user) && $auth_user) {
        if (auth_check('pear.bug') && !auth_check('pear.dev') 
            && $bug['bughandle'] != $auth_user->handle
        ) {
            $edit = 3; // can't edit a bug you didn't create
        }
    } else {
        if (empty($bug['bughandle'])) {
            $edit = 2; // old bug, may be original author, try the old way
        }
    }
}

// 2 is not possible for newer bugs, problem encountered by spin
if ($edit == 2) {
    if (!empty($bug['bughandle'])) {
        $edit = 1;
    }
}

if ($edit == 1) {
    auth_require('pear.bug', 'pear.dev');
}

if ($edit == 3 && auth_check('pear.dev')) {
    $edit = 1;
}

if (!empty($_POST['pw'])) {
    $user = !empty($_POST['user']) ? htmlspecialchars($_POST['user']) : '';
    $pw = $_POST['pw'];
} elseif (isset($auth_user) && $auth_user && $auth_user->handle && $edit == 1) {
    $user = $auth_user->handle;
    $pw   = $auth_user->password;
} elseif (isset($_COOKIE['MAGIC_COOKIE'])) {
    @list($user, $pw) = explode(':', base64_decode($_COOKIE['MAGIC_COOKIE']));
    $user = filter_var($user, FILTER_SANITIZE_STRING);
    if ($pw === null) {
        $pw = '';
    }
} else {
    $user = '';
    $pw   = '';
}

// Unsubscribe and Subscribe
if (isset($_POST['unsubscribe_to_bug']) || isset($_POST['subscribe_to_bug'])) {
    if (isset($auth_user) && $auth_user && $auth_user->registered) {
        $email = $auth_user->email;
    } else {
        $email = $_POST['subscribe_email'];
    }

    if (!preg_match("/[.\\w+-]+@[.\\w-]+\\.\\w{2,}/i", $email)) {
        $errors[] = "You must provide a valid email address.";
    } else {
        if (isset($_POST['subscribe_to_bug'])) {
            $query = 'REPLACE INTO bugdb_subscribe SET bug_id = ?, email = ?';
            $dbh->query($query, array($id, $email));
            $thanks = 7;
        } elseif (isset($_POST['unsubscribe_to_bug'])) {
            /* Generate the hash */
            unsubscribe_hash($id, $email, $bug);
            $thanks = 8;
        }
        localRedirect('bug.php?id='.$id . '&thanks=' . $thanks);
        exit;
    }
}

// Redirect to PECL if it's a PECL bug
if (!empty($bug['package_type']) && $bug['package_type'] != SITE) {
    SITE == 'pear' ? $redirect = 'pecl' : $redirect = 'pear';
    localRedirect('http://' . $redirect . '.php.net/bugs/bug.php?id='.$id);
    exit;
}

// if the user is not registered, this might be spam, don't display
if (!$bug['registered'] && !auth_check('pear.dev')) {
    response_header('User has not confirmed identity');
    report_error(
        'The user who submitted this bug has not yet confirmed ' .
        'their email address.  '
    );
    echo '<p>If you submitted this bug, please check your email.</p>' .
        '<p><strong>If you do not have a confirmation message</strong>, <a href="resend-request-email.php?' .
        'handle=' . urlencode($bug['bughandle']) . '">click here to re-send</a>.  MANUAL CONFIRMATION IS NOT POSSIBLE.  Write a message to <a href="mailto:' . PEAR_DEV_EMAIL . '">' . PEAR_DEV_EMAIL . '</a> to request the confirmation link.  All bugs/comments/patches associated with this
        email address will be deleted
                within 48 hours if the account request is not confirmed!</p>';
    response_footer();
    exit;
}
$previous = $current = array();

// Delete comment
if ($edit === 1 && isset($auth_user) && $auth_user && $auth_user->registered) {
    $addon = '';
    if (isset($_GET['hide_comment']) && auth_check('pear.dev')) {
        hide_comment($id, (int)$_GET['hide_comment']);
        $addon = '&thanks=1';
    }

    if (isset($_GET['show_comment']) && auth_check('pear.bug.admin')) {
        show_comment($id, (int)$_GET['show_comment']);
        $addon = '&thanks=1';
    }

    if (isset($_GET['delete_comment']) && auth_check('pear.bug.admin')) {
        delete_comment($id, (int)$_GET['delete_comment']);
        $addon = '&thanks=1';
    }

    if ($addon !== '') {
        localRedirect("bug.php?id=$id&edit=1$addon");
        exit;
    }
}

// handle any updates, displaying errors if there were any
$errors = array();

if (isset($_POST['addpatch'])) {
    //handle patch upload
    include 'handle-patch.php';
} else if (isset($_POST['ncomment']) && !isset($_POST['preview'])
    && $edit == 3
) {
    // Submission of additional comment by others

    /* Check if session answer is set, then compare
     * it with the post captcha value. If it's not
     * the same, then it's an incorrect password.
     */
    if (isset($_SESSION['answer']) && strlen(trim($_SESSION['answer'])) > 0) {
        if ($_POST['captcha'] != $_SESSION['answer']) {
            $errors[] = 'Incorrect Captcha';
        }
    }

    // try to verify the user
    if (isset($auth_user) && $auth_user) {
        $_POST['in']['handle'] = $auth_user->handle;
    }

    $ncomment = trim($_POST['ncomment']);
    if (!$ncomment) {
        $errors[] = "You must provide a comment.";
    }

    if (!$errors) {
        do {
            if (!isset($auth_user) || !$auth_user) {
                // user doesn't exist yet
                include 'bugs/pear-bug-accountrequest.php';
                $buggie = new PEAR_Bug_Accountrequest;
                if (!preg_match("/[.\\w+-]+@[.\\w-]+\\.\\w{2,}/i", $_POST['in']['commentemail'])) {
                    $errors[] = "You must provide a valid email address.";
                    response_header('Add Comment - Problems');
                    break; // skip bug comment addition
                }
                $salt = $buggie->addRequest($_POST['in']['commentemail']);
                if (is_array($salt)) {
                    $errors = $salt;
                    response_header('Add Comment - Problems');
                    break; // skip bug comment addition
                }
                if (PEAR::isError($salt)) {
                    $errors[] = $salt;
                    response_header('Add Comment - Problems');
                    break;
                }
                if ($salt === false) {
                    $errors[] = 'Your account cannot be added to the queue.'
                         . ' Please write a mail message to the '
                         . ' <i>pear-dev</i> mailing list.';
                    response_header('Report - Problems');
                    break;
                }

                try {
                    $buggie->sendEmail();
                } catch (Exception $e) {
                    $errors[] = 'Critical internal error: could not send' .
                        ' email to your address ' .
                        filter_var($_POST['in']['email'], FILTER_SANITIZE_STRING) .
                        ', please write a mail message to the <i>pear-dev</i>' .
                        'mailing list and report this problem with details.' .
                        '  We apologize for the problem, your report will help' .
                        ' us to fix it for future users: ' . $e->getMessage();
                    response_header('Add Comment - Problems');
                    break;
                }
                $_POST['in']['handle'] = $_POST['in']['name'] = $buggie->handle;
            } else {
                $_POST['in']['commentemail'] = $auth_user->email;
                $_POST['in']['handle'] = $auth_user->handle;
                $_POST['in']['name'] = $auth_user->name;
            }

            $query = 'INSERT INTO bugdb_comments' .
                     ' (bug, email, handle, ts, comment, reporter_name, active) VALUES (' .
                     '?,?,?,NOW(),?,?, 1)';

            $dbh->query(
                $query, array($id, $_POST['in']['commentemail'], $_POST['in']['handle'],
                $ncomment, $_POST['in']['name'])
            );
        } while (false);
        if (isset($auth_user) && $auth_user) {
            $from = $auth_user->email;
        } else {
            $from = '';
        }
    } else {
        $from = '';
    }
} elseif (isset($_POST['ncomment']) && isset($_POST['preview']) && $edit == 3) {
    $ncomment = trim($_POST['ncomment']);
    $from     = trim($_POST['in']['commentemail']);
} elseif (isset($_POST['in']) && !isset($_POST['preview']) && $edit == 2) {
    // Edits submitted by original reporter for old bugs

    if (!$bug['passwd'] || $bug['passwd'] != $pw) {
        $errors[] = 'The password you supplied was incorrect.';
    }

    $ncomment = trim($_POST['ncomment']);
    if (!$ncomment) {
        $errors[] = 'You must provide a comment.';
    }

    /* check that they aren't being bad and setting a status they
       aren't allowed to (oh, the horrors.) */
    if ($_POST['in']['status'] != $bug['status'] && $state_types[$_POST['in']['status']] != 2) {
        $errors[] = 'You aren\'t allowed to change a bug to that state.';
    }

    /* check that they aren't changing the mail to a php.net address
       (gosh, somebody might be fooled!) */
    if (preg_match('/^(.+)@php\.net/i', $_POST['in']['email'], $m)) {
        if ($user != $m[1] || !verify_password($user, $pass)) {
            $errors[] = 'You have to be logged in as a developer to use your php.net email address.';
            $errors[] = 'Tip: log in via another browser window then resubmit the form in this window.';
        }
    }

    if (!empty($_POST['in']['email']) && $bug['email'] != $_POST['in']['email']) {
        $from = $_POST['in']['email'];
    } else {
        $from = $bug['email'];
    }

    if (!empty($_POST['in']['package_name']) 
        && $bug['package_name'] != $_POST['in']['package_name']
    ) {
        // reset package version if we change package name
        $_POST['in']['package_version'] = '';
    }
    $time = time();
    if (!$errors && !($errors = incoming_details_are_valid($_POST['in'], false, false))) {
        $query = 'UPDATE bugdb SET' .
                 " sdesc='" . $dbh->escapeSimple($_POST['in']['sdesc']) . "'," .
                 " status='" . $dbh->escapeSimple($_POST['in']['status']) . "'," .
                 " package_name='" . $dbh->escapeSimple($_POST['in']['package_name']) . "'," .
                 " bug_type='" . $dbh->escapeSimple($_POST['in']['bug_type']) . "'," .
                 " package_version='" . $dbh->escapeSimple($_POST['in']['package_version']) . "'," .
                 " php_version='" . $dbh->escapeSimple($_POST['in']['php_version']) . "'," .
                 " php_os='" . $dbh->escapeSimple($_POST['in']['php_os']) . "'," .
                 " ts2=FROM_UNIXTIME(" . $time . "), " .
                 " email='" . $dbh->escapeSimple($from) . "' WHERE id=$id";
        $dbh->query($query);

        if (!empty($ncomment)) {
            $query = 'INSERT INTO bugdb_comments' .
                     ' (bug, email, comment, ts, active) VALUES (?, ?, ?. NOW(), 1)';
            $dbh->query($query, array($id, $from, $ncomment));
        }
    }

} elseif (isset($_POST['in']) && isset($_POST['preview']) && $edit == 2) {
    $ncomment = trim($_POST['ncomment']);
    $from     = $_POST['in']['commentemail'];
} elseif (isset($_POST['in'])  && !isset($_POST['preview']) && $edit == 1) {
    // Edits submitted by developer

    if (!verify_password($user, $pw)) {
        $errors[] = "You have to login first in order to edit the bug report.";
        $errors[] = 'Tip: log in via another browser window then resubmit the form in this window.';
    }
    $comment_name = $auth_user->name;
    $ncomment = !empty($_POST['ncomment']) ? trim($_POST['ncomment']) : '';

    if (isset($_POST['in']) && is_array($_POST['in']) 
        && (($_POST['in']['status'] == 'Bogus' && $bug['status'] != 'Bogus') 
        || (isset($_POST['in']['resolve']) && isset($RESOLVE_REASONS[$_POST['in']['resolve']]) 
        && $RESOLVE_REASONS[$_POST['in']['resolve']]['status'] == 'Bogus')) 
        && strlen($ncomment) === 0
    ) {
        $errors[] = "You must provide a comment when marking a bug 'Bogus'";
    } elseif (isset($_POST['in']) && is_array($_POST['in']) 
        && !empty($_POST['in']['resolve'])
    ) {
        if (!$trytoforce && isset($RESOLVE_REASONS[$_POST['in']['resolve']]) 
            && $RESOLVE_REASONS[$_POST['in']['resolve']]['status'] == $bug['status']
        ) {
            $errors[] = 'The bug is already marked "'.$bug['status'].'". (Submit again to ignore this.)';
        } elseif (!$errors) {
            $_POST['in']['status'] = $RESOLVE_REASONS[$_POST['in']['resolve']]['status'];
            include './include/resolve.inc';
            $reason = isset($RESOLVE_REASONS[$_POST['in']['resolve']]) ?
                $RESOLVE_REASONS[$_POST['in']['resolve']]['message'] :
                '';
            // do a replacement on @cvs@ to the likely location of CVS for this package
            if ($_POST['in']['resolve'] == 'trycvs') {
                switch ($bug['package_name']) {
                case 'Documentation' :
                case 'Web Site' :
                case 'Bug System' :
                case 'PEPr' :
                    $errors[] = 'Cannot use "try svn" with ' . $bug['package_name'];
                    break;
                case 'PEAR' :
                    $reason = str_replace('@cvs@', 'pear/pear-core/trunk', $reason);
                    $ncomment = "$reason\n\n$ncomment";
                    break;
                default :
                    $reason = str_replace('@cvs@', 'pear/packages/' . $bug['package_name'] . '/trunk', $reason);
                    $ncomment = "$reason\n\n$ncomment";
                    break;
                }
            } else {
                $ncomment = "$reason\n\n$ncomment";
            }
        }
    }

    $from = $auth_user->email;

    if (!$errors && !($errors = incoming_details_are_valid($_POST['in']))) {
        $query = 'UPDATE bugdb SET';

        if ($bug['email'] != $_POST['in']['email'] 
            && !empty($_POST['in']['email'])
        ) {
            $query .= " email='{$_POST['in']['email']}',";
        }

        if (!auth_check('pear.dev')) {
            // don't reset assigned status
            $_POST['in']['assign'] = $bug['assign'];
        }
        if (!empty($_POST['in']['assign']) && $_POST['in']['status'] == 'Open') {
            $status = 'Assigned';
        } elseif (empty($_POST['in']['assign']) && $_POST['in']['status'] == 'Assigned') {
            $status = 'Open';
        } else {
            $status = $_POST['in']['status'];
        }

        if ($status == 'Closed' && $_POST['in']['assign'] == '') {
            $_POST['in']['assign'] = $auth_user->handle;
        }

        if (!empty($_POST['in']['package_name']) 
            && $bug['package_name'] != $_POST['in']['package_name']
        ) {
            // reset package version if we change package name
            $_POST['in']['package_version'] = '';
        }
        $time = time();
        if (!empty($_POST['in']['ts2'])) {
            $date = new DateTime($_POST['in']['ts2']);

            $time = $date->format("U");
        }

        $query .= " sdesc='" . $dbh->escapeSimple($_POST['in']['sdesc']) . "'," .
                  " status='" . $dbh->escapeSimple($status) . "'," .
                  " package_name='" . $dbh->escapeSimple($_POST['in']['package_name']) . "'," .
                  " bug_type='" . $dbh->escapeSimple($_POST['in']['bug_type']) . "'," .
                  " assign='" . $dbh->escapeSimple($_POST['in']['assign']) . "'," .
                  " package_version='" . $dbh->escapeSimple($_POST['in']['package_version']) . "'," .
                  " php_version='" . $dbh->escapeSimple($_POST['in']['php_version']) . "'," .
                  " php_os='" . $dbh->escapeSimple($_POST['in']['php_os']) . "'," .
                  " ts2=FROM_UNIXTIME(" . $time . ") WHERE id=$id";
        $dbh->query($query);

        $previous = $dbh->getAll(
            'SELECT roadmap_version
            FROM bugdb_roadmap_link l, bugdb_roadmap b
            WHERE l.id = ? AND b.id = l.roadmap_id', array($id)
        );
        if (auth_check('pear.dev')) {
            // don't change roadmap assignments for non-devs editing a bug
            $link = Bug_DataObject::bugDB('bugdb_roadmap_link');
            $link->id = $id;
            $link->delete();
            if (isset($_POST['in']['fixed_versions'])
                && !in_array($status, array('Bogus', 'Wont fix', 'No Feedback'))
            ) {
                foreach ($_POST['in']['fixed_versions'] as $rid) {
                    $link->id = $id;
                    $link->roadmap_id = $rid;
                    $link->insert();
                }
            }
            $current = $dbh->getAll(
                'SELECT roadmap_version
                FROM bugdb_roadmap_link l, bugdb_roadmap b
                WHERE l.id = ? AND b.id = l.roadmap_id', array($id)
            );
        } else {
            $current = $previous;
        }

        $changed  = bug_diff($bug, $_POST['in'], $previous, $current);
        if (!empty($changed)) {
            $ncomment = bug_diff_render_html($changed). $ncomment;
        }

        if (!empty($ncomment)) {
            $query = 'INSERT INTO bugdb_comments' .
                     ' (bug, email, ts, comment, reporter_name, handle, active) VALUES (?, ?, NOW(), ?, ?, ?, 1)';
            $dbh->query($query, array($id, $from, $ncomment, $comment_name, $auth_user->handle));
        }

        localRedirect('bug.php?id='.$id);
        exit;
    }
} elseif (isset($_POST['in']) && isset($_POST['preview']) && $edit == 1) {
    $ncomment = trim($_POST['ncomment']);
    $from = $auth_user->email;
} elseif (isset($_POST['in'])) {
    $errors[] = 'Invalid edit mode.';
    $ncomment = '';
} else {
    $ncomment = '';
}

if (isset($_POST['in'])
    && (!isset($_POST['preview']) && $ncomment || $previous != $current)
) {
    if (!$errors) {
        if (!isset($buggie)) {
            mail_bug_updates($bug, $_POST['in'], $from, $ncomment, $edit, $id, $previous, $current);
        }
        localRedirect('bug.php' . "?id=$id&thanks=$edit");
        exit;
    }
}

switch ($bug['bug_type']) {
case 'Bug' : $bug_type = 'Bug'; 
    break;
case 'Feature/Change Request' : $bug_type = 'Request'; 
    break;
case 'Documentation Problem' : $bug_type = 'Doc Bug'; 
    break;
}

$extra = ' <link rel="meta" type="application/rdf+xml" title="Baetle data" href="http://' . PEAR_CHANNELNAME . '/feeds/bug_' . $id . '.rss" />';
$extra .= ' <link rel="alternate" type="application/rss+xml" title="RSS feed of comments" href="http://' . PEAR_CHANNELNAME . '/feeds/bug_' . $id . '.rss" />';

response_header("$bug_type #$id :: " . htmlspecialchars($bug['sdesc']), false, $extra);

show_bugs_menu(clean($bug['package_name']));

// DISPLAY BUG
if (!isset($_GET['thanks'])) {
    $_GET['thanks'] = 0;
}
if ($_GET['thanks'] == 1 || $_GET['thanks'] == 2) {
    report_success('The bug was updated successfully.');

} elseif ($_GET['thanks'] == 3) {
    report_success('Your comment was added to the bug successfully.');

} elseif ($_GET['thanks'] == 4) {
    report_success(
        'Thank you for your help! If the status of the bug'
                        . ' report you submitted changes, you will be'
                        . ' notified. You may return here and check on the'
                        . ' status or update your report at any time. The URL'
                        . ' for your bug report is: <a href="/bugs/'. $id . '">'
        . 'http://'. PEAR_CHANNELNAME .'/bugs/' . $id . '</a>.'
    );

} elseif ($_GET['thanks'] == 6) {
    report_success(
        'Thanks for voting! Your vote should be reflected'
        . ' in the statistics below.'
    );
} elseif ($_GET['thanks'] == 7) {
    report_success('Your subscribe request has been processed');
} elseif ($_GET['thanks'] == 8) {
    report_success('Your unsubscribe request has been processed, please check your email');
} elseif ($_GET['thanks'] == 9) {
    report_success('You have successfully unsubscribed');
} elseif ($_GET['thanks'] == 13) {
    report_success('Patch added');
}

report_error($errors);
?>

<div class="bugheader">
<table class="details">
  <tr id="title">
<?php
       echo '<th id="number">' . $bug_type . '&nbsp;#' . $id . '</th>';
?>
   <td id="summary" colspan="3"><?php echo clean($bug['sdesc']) ?></td>
  </tr>
  <tr id="submission">
   <th>Submitted:</th>
<?php
 echo '   <td colspan="3">' . format_date($bug['submitted']) . '</td>';
?>

  </tr>
  <tr id="submitter">
   <th>From:</th>
   <td>
<?php
if (!$bug['registered']) {
    echo 'Unconfirmed reporter';
} elseif ($bug['bughandle']) {
    echo '<a href="/user/' . $bug['bughandle'] . '">' . $bug['bughandle'] . '</a>';
} elseif ($bug['handle'] && $bug['showemail'] != '0') {
    echo '<a href="/user/' . $bug['handle'] . '">' . $bug['handle'] . '</a>';
} else {
    echo $pbu->spamProtect(htmlspecialchars($bug['email']));
}
?></td>
   <th>Assigned:</th>
   <td><?php
    if (!empty($bug['assign'])) {
        $assigned_user = htmlspecialchars(filter_var($bug['assign'], FILTER_SANITIZE_STRING));
        echo '<a href="/user/' . $assigned_user . '">' . $assigned_user . '</a>';
    }
    ?></td>
  </tr>
  <tr id="categorization">
   <th>Status:</th>
   <td><?php echo htmlspecialchars($bug['status']) ?></td>
   <th>Package:</th>
   <td><?php
    $name = htmlspecialchars($bug['package_name']);
    echo '<a href="/package/' . $name . '">' . $name . '</a>';
    if ($bug['package_version']) : ?> (version <?php echo htmlspecialchars($bug['package_version']);?>)<?php 
    endif;
    ?></td>
  </tr>
  <tr id="situation">
   <th>PHP Version:</th>
   <td><?php echo htmlspecialchars($bug['php_version']) ?></td>
   <th>OS:</th>
   <td><?php echo htmlspecialchars($bug['php_os']) ?></td>
  </tr>
<?php
        $link = Bug_DataObject::bugDB('bugdb_roadmap_link');
        $link->id = $id;
        $link->find(false);
        $links = array();
while ($link->fetch()) {
    $links[$link->roadmap_id] = true;
}
        $db = Bug_DataObject::bugDB('bugdb_roadmap');
        $db->package = $bug['package_name'];
        $db->orderBy('releasedate DESC');
        $assignedRoadmap = array();
if ($db->find(false)) {
    while ($db->fetch()) {
        $released = $dbh->getOne(
            'SELECT releases.id
                 FROM packages, releases, bugdb_roadmap b
                 WHERE
                    b.id=? AND
                    packages.name=b.package AND releases.package=packages.id AND
                    releases.version=b.roadmap_version',
            array($db->id)
        );
        if (isset($links[$db->id])) {
            $assignedRoadmap[] = '<a href="roadmap.php?package=' .
            $db->package . ($released ? '&amp;showold=1' : '') .
            '&amp;roadmapdetail=' . $db->roadmap_version .
            '#a' . $db->roadmap_version . '">' . $db->roadmap_version .
            '</a>';
        }
    }
}
if (!count($assignedRoadmap)) {
    $assignedRoadmap[] = '(Not assigned)';
}
?>
  <tr id="roadmap">
   <th>Roadmaps: </th>

   <td>
    <?php
    echo implode(', ', $assignedRoadmap);
    ?>
   </td>
   <th>&nbsp;</th>
   <td>&nbsp;</td>
  </tr>

<?php if (!isset($auth_user) || !user::maintains($auth_user->handle, $bug['package_id'], array('developer', 'lead'))) { ?>
 <form id="subscribetobug" action="bug.php?id=<?php echo $id; ?>" method="post">
  <tr>
    <th>Subscription</th>
<?php
if (isset($auth_user) && $auth_user && $auth_user->registered) {
    $sql = 'SELECT COUNT(bug_id) FROM bugdb_subscribe WHERE email = ? AND bug_id = ?';
    $res = $dbh->getOne($sql, array($auth_user->email, $id));
    if ($res === '0') {
        echo '<td><input type="submit" name="subscribe_to_bug" value="Subscribe" /></td>';
    } else {
        echo '<td><input type="submit" name="unsubscribe_to_bug" value="Unsubscribe" /></td>';
    }
    echo '<th>&nbsp;</th>';
    echo '<td>&nbsp;</td>';
} else {
?>
    <td><label for="subscribe_email">Your email:</label>&nbsp;<input type="text" id="subscribe_email" name="subscribe_email" value="" /></td>
    <td><input type="submit" name="subscribe_to_bug" value="Subscribe" /></td>
    <td><input type="submit" name="unsubscribe_to_bug" value="Unsubscribe" /></td>
<?php
}
?>
  </tr>
 </form>
<?php } ?>


<?php if ($bug['votes']) {?>
  <tr id="votes">
   <th>Votes:</th><td><?php echo $bug['votes'] ?></td>
   <th>Avg. Score:</th><td><?php printf("%.1f &plusmn; %.1f", $bug['average'], $bug['deviation']) ?></td>
   <th>Reproduced:</th><td><?php printf("%d of %d (%.1f%%)", $bug['reproduced'], $bug['tried'], $bug['tried']?($bug['reproduced']/$bug['tried'])*100:0) ?></td>
  </tr>
<?php if ($bug['reproduced']) {?>
  <tr id="reproduced">
   <td colspan="2"></td>
   <th>Same Version:</th><td><?php printf("%d (%.1f%%)", $bug['samever'], ($bug['samever']/$bug['reproduced'])*100) ?></td>
   <th>Same OS:</th><td><?php printf("%d (%.1f%%)", $bug['sameos'], ($bug['sameos']/$bug['reproduced'])*100) ?></td>
  </tr>
<?php } ?>
<?php } ?>
</table>
</div>


<div id="controls">

<?php

control(0, 'Comments', $id, $edit);

// Display patches
$patches = new Bugs_Patchtracker();
$patchcount = $patches->getPatchCount($id);
if ($patchcount > 0) {
    control(11, 'Patches (' . $patchcount . ')', $id, $edit);
}

if ((!(isset($auth_user) && $auth_user && $auth_user->registered)
    || !auth_check('pear.dev')) && $edit != 2
) {
    control(3, 'Add Comment', $id, $edit);
}

if (auth_check('pear.bug') || auth_check('pear.dev')) {
    control(1, 'Edit', $id, $edit);
}

control(13, 'Add patch', $id, $edit);

//show patch details only when active
if ($edit == 12) {
    control(12, 'Patch details', $id, $edit);
}

?>

</div>
<?php

if (isset($_POST['preview']) && !empty($ncomment)) {
    $preview = '';
    $preview .= '<div class="comment" style="margin-bottom: 1.0em; padding: 0.5em; background-color: rgb(240, 240, 240);">';
    $preview .= "<strong>[" . format_date(time()) . "] ";
    if (isset($auth_user) && $auth_user) {
        $preview .= '<a href="/user/' . $auth_user->handle . '">' .
            $auth_user->handle . '</a>';
    } else {
        $preview .= $pbu->spamProtect(htmlspecialchars($from));
    }
    $preview .= "</strong>\n<br /><span class=\"note\" style=\"white-space: pre-wrap\">";
    $comment = $ncomment;
    $preview .= make_ticket_links(addlinks(clean($comment)));
    $preview .= "</span>\n";
    $preview .= '</div>';
} else {
    $preview = '';
}

if ($edit == 1 || $edit == 2) {
?>

    <form id="update" action="bug.php?id=
        <?php echo $id . '&amp;edit=' . $edit ?>" method="post">

<?php
if ($edit == 2) {
    if (!isset($_POST['in']) && $pw && $bug['passwd'] 
        && $pw == $bug['passwd']
    ) {
?>

        <div class="explain">
         Welcome back! Since you opted to store your bug's password in a
         cookie, you can just go ahead and add more information to this
         bug or edit the other fields.
        </div>

<?php
    } else {
    ?>

    <div class="explain">

    <?php
    if (!isset($_POST['in'])) {
?>

Welcome back! If you're the original bug submitter, here's
where you can edit the bug or add additional notes. If this
is not your bug, you can <a href=
"<?php echo "bug.php?id=$id&amp;edit=3" ?>"
>add a comment by following this link</a>. If this is your
bug, but you forgot your password, <a
href="bug-pwd-finder.php?id=<?php echo $id; ?>">you can retrieve your password
here</a>.

<?php
    }
    ?>

     <table>
      <tr>
       <th class="details">Passw<span class="accesskey">o</span>rd:</th>
       <td>
        <input type="password" id="pw" name="pw"
         value="<?php echo htmlspecialchars($pw) ?>" size="10" maxlength="20"
         accesskey="o" />
       </td>
       <th class="details">
        <label for="save">
         Check to remember your password for next time:
        </label>
       </th>
       <td>
        <input type="checkbox" id="save" name="save"
<?php if (isset($_POST['save'])) {
    echo ' checked="checked"';
}
?> />
       </td>
      </tr>
     </table>

    </div>

<?php
    }
} else {
    if ($user && $pw && verify_password($user, $pw)) {
        ///FIXME make sure this works for the reporter that doesn't have an account
        if ((!isset($_POST['in']) || !is_array($_POST['in'])) && !$auth_user) {
?>

            <div class="explain">
             Welcome back, <?php echo $user?>! (Not <?php echo $user?>?
             <a href="?logout=1&amp;id=<?php echo $id ?>&amp;edit=1">Log out.</a>)
            </div>

<?php
        }
    } else {
?>

    <div class="explain">

<?php
if (!isset($_POST['in']) || !is_array($_POST['in'])) {
?>

Welcome! If you don't have a SVN account, you can't do
anything here. You can <a href=
"<?php echo "bug.php?id=$id&amp;edit=3" ?>"
>add a comment by following this link</a> or if you
reported this bug, you can <a href=
"<?php echo "bug.php?id=$id&amp;edit=2" ?>"
>edit this bug over here</a>.

<?php
}

?>
    </div>

<?php
    }
}

?>

    <table id="bugform">

<?php
if ($edit == 1 && auth_check('pear.dev')) :
    // Developer Edit Form
?>

<tr>
 <th>
  <label for="in" accesskey="c">Qui<span class="accesskey">c</span>k Fix:</label>
 </th>
 <td colspan="3">
  <select name="in[resolve]" id="in">
<?php show_reason_types(
    isset($_POST['in']) && isset($_POST['in']['resolve']) ?
            $_POST['in']['resolve'] : -1, 1
) ?>
          </select>

<?php
if (isset($_POST['in']) && !empty($_POST['in']['resolve'])) {
?>
<input type="hidden" name="trytoforce" value="1" />
<?php
}
?>
  <small>(<a href="quick-fix-desc.php">description</a>)</small>
 </td>
</tr>
<?php
endif; // if ($edit == 1 && auth_check('pear.dev'))
?>

     <tr>
      <th>Status:</th>
      <td <?php echo (($edit != 1) ? 'colspan="3"' : '' ) ?>>
       <select name="in[status]">
        <?php
            $status = isset($_POST['in']) && isset($_POST['in']['status']) ? filter_var($_POST['in']['status'], FILTER_SANITIZE_STRING): '';
            show_state_options($status, $edit, $bug['status']) ?>
       </select><br />
<?php
if ($bug['modified']) {
    if ($edit == 1 && auth_check('pear.dev')) { ?>
           <input type="datetime" id="ts2" name="in[ts2]" value="<?php print date("c", $bug['modified']); ?>" />
           <script type="text/javascript">$("ts2").dtpicker();</script>
    <?php } else {
        echo format_date($bug['modified']);
    }
}
?>

<?php
if ($edit == 1 && auth_check('pear.dev')) {
?>

</td>
<th>Assign to:</th>
<td>
 <input type="text" size="10" maxlength="16" name="in[assign]"
  value="<?php echo field('assign', $bug) ?>" />
<?php
}
?>

       <input type="hidden" name="id" value="<?php echo $id ?>" />
       <input type="hidden" name="edit" value="<?php echo $edit ?>" />
       <input type="submit" value="Submit" />
      </td>
     </tr>
     <tr>
      <th>Package:</th>
      <td colspan="3">
       <select name="in[package_name]">
        <?php show_types(
            isset($_POST['in']) && isset($_POST['in']['package_name']) ?
            $_POST['in']['package_name'] : '', 0, $bug['package_name']
        ) ?>
       </select>
      </td>
     </tr>
     <tr>
      <th>Bug Type:</th>
       <td colspan="3">
        <select name="in[bug_type]">
            <?php show_type_options($bug['bug_type']); ?>
        </select>
      </td>
     </tr>
     <tr>
      <th>Summary:</th>
      <td colspan="3">
       <input type="text" size="60" maxlength="80" name="in[sdesc]"
        value="<?php echo field('sdesc', $bug) ?>" />
      </td>
     </tr>
     <tr>
      <th>From:</th>
      <td colspan="3">
        <?php echo $pbu->spamProtect(field('email', $bug)) ?>
      </td>
     </tr>
     <tr>
      <th>New email:</th>
      <td colspan="3">
       <input type="text" size="40" maxlength="40" name="in[email]"
        value="<?php echo (isset($_POST['in']) && isset($_POST['in']['email']) ?
            filter_var($_POST['in']['email'], FILTER_SANITIZE_STRING) : '') ?>" />
      </td>
     </tr>
     <tr>
      <th>PHP Version:</th>
      <td>
       <input type="text" size="20" maxlength="100" name="in[php_version]"
        value="<?php echo field('php_version', $bug) ?>" />
      </td>
      <th>Package Version:</th>
      <td>
       <input type="text" size="20" maxlength="100" name="in[package_version]"
        value="<?php echo field('package_version', $bug) ?>" />
      </td>
      <th>OS:</th>
      <td>
       <input type="text" size="20" maxlength="32" name="in[php_os]"
        value="<?php echo field('php_os', $bug) ?>" />
      </td>
     </tr>
        <?php if (auth_check('pear.dev')) : ?>
     <tr>
      <th>Roadmap:</th>
      <td id="roadmaps" colspan="5" class="rm-hideold"><?php
        $link = Bug_DataObject::bugDB('bugdb_roadmap_link');
        $link->id = $id;
        $link->find(false);
        $links = array();
        while ($link->fetch()) {
            $links[$link->roadmap_id] = true;
        }
        $db = Bug_DataObject::bugDB('bugdb_roadmap');
        $db->package = $bug['package_name'];
        $db->orderBy('releasedate DESC');
        if ($db->find(false)) {
            while ($db->fetch()) {
                $released = $dbh->getOne(
                    'SELECT releases.id
                 FROM packages, releases, bugdb_roadmap b
                 WHERE
                    b.id=? AND
                    packages.name=b.package AND releases.package=packages.id AND
                    releases.version=b.roadmap_version',
                    array($db->id)
                );
                if ($released) {
                    echo '<span class="headerbottom released'
                    . (isset($links[$db->id]) ? ' active' : '')
                    . '">';
                }
                echo '<input type="checkbox"'
                    . ' name="in[fixed_versions][]" value="' . $db->id . '"'
                    . ' id="a-r-' . $db->id . '"';
                if (isset($links[$db->id])) {
                    echo ' checked="checked"';
                }
                echo '/>';
                echo '<label for="a-r-' . $db->id . '">'
                    . '&nbsp;' . $db->roadmap_version
                    . '</label> ';
                if ($released) {
                    echo '&nbsp;</span>';
                }
            }
            ?>
            &nbsp;<a id="showold" href="#" onclick="javascript:document.getElementById('roadmaps').className='rm';return false;" title="Show already released roadmaps">&gt;&gt;</a>
            &nbsp;<a id="hideold" href="#" onclick="javascript:document.getElementById('roadmaps').className='rm-hideold';return false;" title="Hide already released roadmaps">&lt;&lt;</a>
            <?php
        } else {
            ?>(No roadmap defined)<?php
        }
        ?>
      </td>
     </tr>
        <?php endif; //if (auth_check('pear.dev'))?>
    </table>
<?php
} else if ($edit == 11 || $edit == 12) {
    //list patches
    echo '<br/><br/><br/>';
    include 'patch-display.php';
    response_footer();
    exit();
} else if ($edit == 13) {
    //add patch
    echo '<br/><br/><br/>';
    include 'patch-add.php';
    response_footer();
    exit();
} else {
    echo '<br /><br />';
}

if ($edit == 1 || $edit == 2) {
?>
    <p style="margin-bottom: 0em">
    <label for="ncomment" accesskey="m"><strong>New
<?php
if ($edit==1) {
        echo "/Additional";
}
?> Co<span class="accesskey">m</span>ment:</strong></label>
    </p>

    <?php echo $preview; ?>

    <textarea cols="80" rows="10" id="ncomment" name="ncomment"
     wrap="physical"><?php echo clean($ncomment) ?></textarea>

    <p style="margin-top: 0em;">
        <input type="submit" name="preview" value="Preview" />&nbsp;<input type="submit" value="Submit" />
    </p>

    </form>

<?php
}

if ($edit == 3) {
?>
    <form id="comment" action="bug.php" method="post">
<?php if (isset($auth_user) && $auth_user) : ?>
    <div class="explain">
     <h1><a href="patch-add.php?bug_id=<?php echo $id ?>">Click Here to Submit a Patch</a></h1>
    </div>

<?php endif;?>

    <?php
    if (!isset($_POST['in'])) {
        ?>

        <div class="explain">
         Anyone can comment on a bug. Have a simpler test case? Does it
         work for you on a different platform? Let us know! Just going to
         say 'Me too!'? Don't clutter the database with that please

            <?php
            if (canvote()) {
                echo ' &mdash; but make sure to <a href="';
                echo 'bug.php?id=' . $id . '">vote on the bug</a>';
            }
            ?>!

        </div>

        <?php
    }
    echo $preview;
    ?>


    <table>
        <?php if (!isset($auth_user) || !$auth_user) : ?>
     <tr>
      <th class="details">Y<span class="accesskey">o</span>ur email address:<br />
      <strong>MUST BE VALID</strong></th>
      <td class="form-input">
       <input type="text" size="40" maxlength="40" name="in[commentemail]" id="in-commentemail-"
        value="<?php
        echo clean(
            isset($_POST['in']) && isset($_POST['in']['commentemail']) ?
            filter_var($_POST['in']['commentemail'], FILTER_SANITIZE_STRING) : ''
               );
        ?>"
        accesskey="o" />
      </td>
     </tr>
     <tr>
      <th>Solve the problem : <?php print $numeralCaptcha->getOperation(); ?> = ?</th>
      <td class="form-input"><input type="text" name="captcha" id="captcha" /></td>
     </tr>
        <?php $_SESSION['answer'] = $numeralCaptcha->getAnswer(); ?>
        <?php endif; // if (!$auth_user): ?>
    </table>

    <div>
     <input type="hidden" name="id" id="id" value="<?php echo $id ?>" />
     <input type="hidden" name="edit" id="edit" value="<?php echo $edit ?>" />
     <textarea cols="60" rows="10" name="ncomment" id="ncomment"><?php echo clean($ncomment) ?></textarea>
     <br /><input type="submit" name="preview" value="Preview" />&nbsp;<input type="submit" value="Submit" />
    </div>

    </form>

    <?php
} // if ($edit == 3)


if (!$edit && canvote()) {
    ?>

  <form id="vote" method="post" action="vote.php">
  <div class="sect">
   <fieldset>
    <legend>Have you experienced this issue?</legend>
    <div>
     <input type="radio" id="rep-y" name="reproduced" value="1" onchange="show('canreproduce')" /> <label for="rep-y">yes</label>
     <input type="radio" id="rep-n" name="reproduced" value="0" onchange="hide('canreproduce')" /> <label for="rep-n">no</label>
     <input type="radio" id="rep-d" name="reproduced" value="2" onchange="hide('canreproduce')" checked="checked" /> <label for="rep-d">don't know</label>
    </div>
   </fieldset>
   <fieldset>
    <legend>Rate the importance of this bug to you:</legend>
    <div>
     <label for="score-5">high</label>
     <input type="radio" id="score-5" name="score" value="2" />
     <input type="radio" id="score-4" name="score" value="1" />
     <input type="radio" id="score-3" name="score" value="0" checked="checked" />
     <input type="radio" id="score-2" name="score" value="-1" />
     <input type="radio" id="score-1" name="score" value="-2" />
     <label for="score-1">low</label>
    </div>
   </fieldset>
  </div>
  <div id="canreproduce" class="sect" style="display: none">
   <fieldset>
    <legend>Are you using the same PHP version?</legend>
    <div>
     <input type="radio" id="ver-y" name="samever" value="1" /> <label for="ver-y">yes</label>
     <input type="radio" id="ver-n" name="samever" value="0" checked="checked" /> <label for="ver-n">no</label>
    </div>
   </fieldset>
   <fieldset>
    <legend>Are you using the same Package version?</legend>
    <div>
     <input type="radio" id="ver-y" name="samever" value="1" /> <label for="ver-y">yes</label>
     <input type="radio" id="ver-n" name="samever" value="0" checked="checked" /> <label for="ver-n">no</label>
    </div>
   </fieldset>
   <fieldset>
    <legend>Are you using the same operating system?</legend>
    <div>
     <input type="radio" id="os-y" name="sameos" value="1" /> <label for="os-y">yes</label>
     <input type="radio" id="os-n" name="sameos" value="0" checked="checked" /> <label for="os-n">no</label>
    </div>
   </fieldset>
  </div>
  <div id="submit" class="sect">
   <input type="hidden" name="id" value="<?php echo $id?>" />
   <input type="submit" value="Vote" />
  </div>
  </form>
  <br clear="all" />

<?php
}


// Display original report
if ($bug['ldesc']) {
    output_note(0, $bug['submitted'], $bug['email'], $bug['ldesc'], $bug['showemail'], $bug['bughandle'], $bug['reporter_name'], $bug['registered']);
}

// Display comments
$query = 'SELECT c.id,c.email,c.comment,UNIX_TIMESTAMP(c.ts) AS added, c.reporter_name as comment_name, IF(c.handle <> "",u.registered,1) as registered,
    u.showemail, u.handle,c.handle as bughandle
    FROM bugdb_comments c
    LEFT JOIN users u ON u.handle = c.handle
    WHERE c.bug = ? AND c.active = 1
    GROUP BY c.id ORDER BY c.ts';
$res = $dbh->query($query, array($id));
if ($res) {
    ?><h2>Comments</h2>
<?php
while ($row = $res->fetchRow(DB_FETCHMODE_ASSOC)) {
        output_note(
            $row['id'],
            $row['added'],
            $row['email'],
            $row['comment'],
            $row['showemail'],
            $row['bughandle'] ? $row['bughandle'] : $row['handle'],
            $row['comment_name'],
            $row['registered']
        );
    }
}

response_footer();


function output_note($com_id, $ts, $email, $comment, $showemail = 1, $handle = null, $comment_name = null, $registered)
{
    global $edit, $id, $user, $dbh;

    echo '<div class="comment">';
    echo '<a name="' . urlencode($ts) . '">&nbsp;</a>';
    echo "<strong>[",format_date($ts),"] ";
    if (!$registered) {
        echo 'User who submitted this comment has not confirmed identity</strong>';
        if (!auth_check('pear.dev')) {
            echo '<pre class="note">If you submitted this note, check your email.';
            echo 'If you do not have a message, <a href="resend-request-email.php?' .
            'handle=' . urlencode($handle) . "\">click here to re-send</a>\n",
            'MANUAL CONFIRMATION IS NOT POSSIBLE.  Write a message to <a href="mailto:' . PEAR_DEV_EMAIL . '">' . PEAR_DEV_EMAIL . '</a>' . "\n",
            "to request the confirmation link.  All bugs/comments/patches associated with this
\nemail address will be deleted within 48 hours if the account request is not confirmed!";
            echo "</pre>\n</div>";
            return;
        }
    } else {
        if ($handle) {
            echo '<a href="/user/' . $handle . '">' . $handle . "</a></strong>\n";
        } else {
            include_once 'bugs/pear-bugs-utils.php';
            $pbu = new PEAR_Bugs_Utils;
            echo $pbu->spamProtect(htmlspecialchars($email))."</strong>\n";
        }
    }
    if ($comment_name && $registered) {
        echo '(' . htmlspecialchars($comment_name) . ')';
    }
    if ($edit === 1 && $com_id !== 0 && auth_check('pear.dev')) {
        echo "&nbsp<a href=\"bug.php?id=$id&amp;edit=1&amp;hide_comment=$com_id\">[delete]</a>\n";
    }
    echo '<div class="note" style="white-space: pre-wrap; width: 60em; overflow: auto; max-height: 20em; padding: 1.0em; margin: 1.0em; background-color: rgb(240, 240, 240)">';

    // This has to be done so we don't wordwrap the changeset part again
    $fix     = $comment;
    $status  = "";

    $search  = "</div>";
    $needle  = strrpos($comment, $search);
    if ($needle !== false) {
        $fix     = substr($comment, $needle + strlen($search)); // Get from last div until end of string
        $status  = substr($comment, 0, $needle) . $search;
    }

    $comment = make_ticket_links(addlinks(clean($fix)));
    $comment = $status . $comment;


    echo $comment;
    echo "</div>\n";
    echo '</div>' . "\n";
}

/**
 * This function only hides the comment so pear.bug.admin people can review it
 */
function hide_comment($id, $com_id)
{
    global $dbh;
    $query = 'UPDATE bugdb_comments SET active = 0 WHERE bug = ' . (int)$id .
        ' AND id = '.(int)$com_id;
    $res = $dbh->query($query);
}

function show_comment($id, $com_id)
{
    global $dbh;
    $query = 'UPDATE bugdb_comments SET active = 1 WHERE bug = ' . (int) $id .
        ' AND id = ' . (int)$com_id;
    $res = $dbh->query($query);
}

function delete_comment($id, $com_id)
{
    global $dbh;
    $query = 'DELETE FROM bugdb_comments WHERE active = 0 bug = ' . (int) $id .
        ' AND id = '.(int)$com_id;
    $res = $dbh->query($query);
}

/**
 * Display a bug control tab (View, Add comment, edit etc.)
 *
 * @param mixed   $num  Current tab number
 * @param string  $desc Tab label
 * @param integer $id   Bug number
 * @param mixed   $edit Current $num
 *
 * @return void
 */
function control($num, $desc, $id, $edit)
{
    echo '<span id="control_' . $num . '" class="control';
    if ($edit === $num) {
        echo ' active">';
        echo $desc;
    } else {
        echo '">';
        $add = $num ? "&amp;edit=$num" : '';
        $url = 'bug.php?id=' . $id . $add;

        echo '<a href="' . $url . '">' . $desc . '</a>';
    }
    echo "</span>\n";
}

function canvote()
{
    return false;
    global $bug;
    return (
        $_GET['thanks'] != 4
        && $_GET['thanks'] != 6
        && $bug['status'] != 'Closed'
        && $bug['status'] != 'Bogus'
        && $bug['status'] != 'Duplicate'
    );
}
