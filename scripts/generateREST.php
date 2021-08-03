<?php
/**
 * Generate static REST files for pear.php.net from existing data
 * @author Greg Beaver <cellog@php.net>
 * @version $Id$
 */
/**
 * Useful files to have
 */
set_include_path(dirname(dirname(__FILE__)) . '/include' . PATH_SEPARATOR . get_include_path());
ob_start();
require_once 'pear-config.php';
require_once 'PEAR.php';
include_once 'pear-rest.php';

if (!isset($pear_rest)) {
    if (isset($_SERVER['argv']) && $_SERVER['argv'][1] == 'pear') {
        $rest_path = '/var/lib/pearweb/rest';
    } else {
        $rest_path = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR . 'rest';
    }
    
    include_once 'DB.php';
    
    if (empty($dbh)) {
        $options = array(
            'persistent' => false,
            'portability' => DB_PORTABILITY_ALL,
        );
        $dbh =& DB::connect(PEAR_DATABASE_DSN, $options);
    }
    $pear_rest = new pearweb_Channel_REST_Generator($rest_path, $dbh);
}
ob_end_clean();
PEAR::setErrorHandling(PEAR_ERROR_DIE);
require_once 'System.php';
System::rm(array('-r', $rest_path));
System::mkdir(array('-p', $rest_path));
chmod($rest_path, 0777);
echo "Generating Category REST...\n";

include_once 'pear-database-category.php';
foreach (category::listAll() as $category) {
    echo "  $category[name]...";
    $pear_rest->saveCategoryREST($category['name']);
    echo "done\n";
}
$pear_rest->saveAllCategoriesREST();
echo "Generating Maintainer REST...\n";
$maintainers = $dbh->getAll('SELECT users.* FROM users, karma WHERE users.handle = karma.user
    AND (karma.level = "pear.dev" OR karma.level = "pear.admin")', array(), DB_FETCHMODE_ASSOC);
foreach ($maintainers as $maintainer) {
    echo "  $maintainer[handle]...";
    $pear_rest->saveMaintainerREST($maintainer['handle']);
    echo "done\n";
}
echo "Generating All Maintainers REST...\n";
$pear_rest->saveAllMaintainersREST();
echo "done\n";
echo "Generating Package REST...\n";
$pear_rest->saveAllPackagesREST();
require_once 'Archive/Tar.php';
require_once 'PEAR/PackageFile.php';
$config = &PEAR_Config::singleton();
$pkg = new PEAR_PackageFile($config);

include_once 'pear-database-package.php';
foreach (package::listAllNames() as $package) {
    echo "  $package\n";
    $pear_rest->savePackageREST($package);
    echo "     Maintainers...";
    $pear_rest->savePackageMaintainerREST($package);
    echo "...done\n";
    $releases = package::info($package, 'releases');
    if ($releases) {
        echo "     Processing All Releases...";
        $pear_rest->saveAllReleasesREST($package);
        echo "done\n";
        foreach ($releases as $version => $blah) {
            $sql = 'SELECT fullpath FROM files WHERE `release` = ?';
            $fileinfo = $dbh->getOne($sql, array($blah['id']));
            $tar = new Archive_Tar($fileinfo);
            if ($pxml = $tar->extractInString('package2.xml')) {
            } elseif ($pxml = $tar->extractInString('package.xml'));
            PEAR::pushErrorHandling(PEAR_ERROR_RETURN);
            $pf = $pkg->fromAnyFile($fileinfo, PEAR_VALIDATE_NORMAL);
            PEAR::popErrorHandling();
            if (!PEAR::isError($pf)) {
                echo "     Version $version...";
                $pear_rest->saveReleaseREST($fileinfo, $pxml, $pf, $blah['doneby'],
                    $blah['id']);
                echo "done\n";
            } else {
                echo "     Skipping INVALID Version $version\n";
            }
        }
        echo "\n";
    } else {
        echo "  done\n";
    }
}
echo "Generating Category Package REST...\n";
foreach (category::listAll() as $category) {
    echo "  $category[name]...";
    $pear_rest->savePackagesCategoryREST($category['name']);
    echo "done\n";
}
