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
   +----------------------------------------------------------------------+
   $Id$
*/

require_once "HTML/Form.php";

auth_require();

response_header("PEAR Administration - Package maintainers");

if (isset($_GET['pid'])) {
    $id = (int)$_GET['pid'];
} else {
    $id = 0;
}

// Select package first
if (empty($id)) {
    $packages = package::listAll(true, false, true);
    $values   = array();

    foreach ($packages as $name => $package) {
        $values[$package['packageid']] = $name;
    }

    $bb = new BorderBox("Select package");

    $form = new HTML_Form($_SERVER['PHP_SELF']);
    $form->addSelect("pid", "Package:", $values);
    $form->addSubmit();
    $form->display();

    $bb->end();

} else if (!empty($_GET['update'])) {
    if (!maintainer::mayUpdate($id)) {
        PEAR::raiseError("Only the lead maintainer of the package or PEAR
                          administrators can edit the maintainers.");
        response_footer();
        exit();
    }

    $new_list = array();
    foreach ((array)$_GET['maintainers'] as $maintainer) {
        list($handle, $role) = explode("||", $maintainer);
        $new_list[$handle] = array("role" => $role,
                                   "active" => 1);
    }
    $res = maintainer::updateAll($id, $new_list);
    $package = $dbh->getOne('SELECT name FROM packages WHERE id=?', array($id));
    $pear_rest->savePackageMaintainerREST($package);

    $url = $_SERVER['PHP_SELF'];
    if (!empty($_GET['pid'])) {
        $url .= "?pid=" . $_GET['pid'];
    }
    echo '<br /><b>Done</b><br />';
    echo '<a href="' . $url . '">Back</a>';
} else {
    if (!maintainer::mayUpdate($id)) {
        PEAR::raiseError("Only the lead maintainer of the package or PEAR
                          administrators can edit the maintainers.");
        response_footer();
        exit();
    }

    $bb = new BorderBox("Manage maintainers", "100%");

    echo '<script src="/javascript/package-maintainers.js" type="text/javascript"></script>';
    echo '<form onSubmit="beforeSubmit()" name="form" method="get" action="' . $_SERVER['PHP_SELF'] . '">';
    echo '<input type="hidden" name="update" value="yes" />';
    echo '<input type="hidden" name="pid" value="' . $id . '" />';
    echo '<table border="0" cellpadding="0" cellspacing="4" border="0" width="100%">';
    echo '<tr>';
    echo '  <th>All users:</th>';
    echo '  <th></th>';
    echo '  <th>Package maintainers:</th>';
    echo '</tr>';

    echo '<tr>';
    echo '  <td>';
    echo '  <select onChange="activateAdd();" name="accounts" size="10">';

    $users = user::listAll();
    foreach ($users as $user) {
        if (empty($user['handle'])) {
            continue;
        }
        printf('<option value="%s">%s (%s)</option>',
               $user['handle'],
               $user['name'],
               $user['handle']
               );
    }
    echo '  </select>';
    echo '  </td>';

    echo '  <td>';
    echo '  <input type="submit" onClick="addMaintainer(); return false" name="add" value="Add as" />';
    echo '  <select name="role" size="1">';
    echo '    <option value="lead">lead</option>';
    echo '    <option value="developer">developer</option>';
    echo '    <option value="helper">helper</option>';
    echo '  </select><br /><br />';
    echo '  <input type="submit" onClick="removeMaintainer(); return false" name="remove" value="Remove" />';
    echo '  </td>';

    echo '  <td>';
    echo '  <select multiple="yes" name="maintainers[]" onChange="activateRemove();" size="10">';

    $maintainers = maintainer::get($id);
    foreach ($maintainers as $handle => $row) {
        $info = user::info($handle, "name");   // XXX: This sucks
        printf('<option value="%s||%s">%s (%s, %s)</option>',
               $handle,
               $row['role'],
               $info['name'],
               $handle,
               $row['role']
               );
    }
    echo '  </select>';
    echo '  </td>';
    echo '</tr>';
    echo '<tr>';
    echo '  <td colspan="3"><input type="submit" /></td>';
    echo '</tr>';
    echo '</table>';
    echo '</form>';

    echo '<script language="JavaScript" type="text/javascript">';
    echo 'document.form.remove.disabled = true;';
    echo 'document.form.add.disabled = true;';
    echo 'document.form.role.disabled = true;';
    echo '</script>';

    $bb->end();
}

response_footer();
?>
