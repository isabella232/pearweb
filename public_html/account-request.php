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
   | Authors:                                                             |
   +----------------------------------------------------------------------+
   $Id$
*/

define('HTML_FORM_TH_ATTR', 'class="form-label_left"');
define('HTML_FORM_TD_ATTR', 'class="form-input"');
require_once 'HTML/Form.php';
require_once 'Damblan/Mailer.php';

$display_form = true;
$width        = 60;
$errors       = array();
$jumpto       = 'handle';

$stripped = array_map('strip_tags', $_POST);

// CAPTCHA needs it and we cannot start it in the
// CAPTCHA function, too much mess around here.
session_start();

response_header('Request Account');

print '<h1>Request Account</h1>';
$mailto = make_mailto_link('pear-dev@lists.php.net', 'PEAR developers mailing list');
    print <<<MSG
<h1>PLEASE READ THIS CAREFULLY!</h1>
<h3>
 You only need to request an account if you:
</h3>

<ul>
 <li>
  <a href="/account-request-newpackage.php">Want to propose a new (and <strong>complete</strong>) package for inclusion in PEAR.</a>
 </li>
 <li>
  <a href="/account-request-existingpackage.php">Will be helping develop an existing package.</a>  Seek approval first for this by mailing
  the $mailto and developers of the package.
 </li>
 <li>
  <a href="/account-request-vote.php">Want to vote in a general PEAR election.</a>
 </li>
 <li>
  <a href="/bugs/report.php">Want to report a bug, or comment on an existing bug.</a>
  (You can create an account automatically by choosing a username/password on the bug
  report or edit page)
 </li>
</ul>

<p>
 If the reason for your request does not fall under one of the
 reasons above, please contact the $mailto;
</p>

<h3>
 You do <strong>not</strong> need an account to:
</h3>

<ul>
 <li>
  Use PEAR packages.
 </li>
 <li>
  Browse the PEAR website.
 </li>
 <li>
  Download PEAR packages.
 </li>
 <li>
  Express an idea for a PEAR package.  Only completed code can be proposed.
 </li>
</ul>
MSG;
response_footer();

?>
