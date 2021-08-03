<?php
require_once 'PEAR/PackageFile/v2/rw.php';
require_once 'PEAR/PackageFile/Parser/v2.php';
require_once 'PEAR/PackageFile/Generator/v2.php';
class Roadmap_Package_Generator
{
    var $_dbh;
    var $_package;
    var $_errors;
    /**
     * @param string $package Package for this roadmap
     */
    function __construct($package)
    {
        $this->_dbh = &$GLOBALS['dbh'];
        $this->_package = $package;
    }

    /**
     * Retrieve package.xml text
     *
     * @param string $version Roadmap version
     * @return false|string
     */
    function getRoadmapPackage($version)
    {
        $sql = '
            SELECT packagexml
            FROM packages p, releases r, files f
            WHERE
                p.name = ? AND
                r.package = p.id AND
                f.release = r.id
            ORDER BY r.releasedate DESC';

        $packagexml = $this->_dbh->getOne($sql, array($this->_package));
        if ($packagexml) {
            $pf = $this->getPackageXmlV2($packagexml);
            $oldchangelog = $pf->getChangelog();
            $oldversion = $pf->getVersion();
            $pf->setReleaseVersion($version);
            $changelog = $pf->generateChangeLogEntry();
            if (is_array($oldchangelog)) {
                $oldchangelog = $oldchangelog['release'];
                $last = null;
                $insertatend = true;
                if (!isset($oldchangelog[0])) {
                    $oldchangelog = array($oldchangelog);
                }
                foreach ($oldchangelog as $entry) {
                    if (!isset($last)) {
                        $last = $entry;
                        continue;
                    }
                    if (version_compare($entry['version']['release'], $last['version']['release'],
                                        '<')) {
                        $insertatend = false;
                    }
                    break;
                }
                if ($insertatend) {
                    $pf->setChangelogEntry($oldversion, $changelog);
                } else {
                    $pf->clearChangeLog();
                    $pf->setChangelogEntry($oldversion, $changelog);
                    foreach ($oldchangelog as $entry) {
                        $pf->setChangelogEntry($entry['version']['release'], $entry);
                    }
                }
            } else {
                // no pre-existing changelog
                $pf->setChangelogEntry($oldversion, $changelog);
            }
            if ($version[0] == '0') {
                if ($pf->getState() == 'stable') {
                    $pf->setReleaseStability('beta');
                }
            } else {
                if (strpos($version, 'RC')) {
                    $pf->setReleaseStability('beta');
                } else {
                    if (strpos($version, 'e')) {
                        $pf->setReleaseStability('beta');
                    } elseif (strpos($version, 'a')) {
                        $pf->setReleaseStability('alpha');
                    } else {
                        $pf->setReleaseStability('stable');
                    }
                }
            }
        } else {
            // no releases
            $pf = $this->getBlankPackage();
        }
        $pf->setDate(date('Y-m-d'));
        if ($version == '0.1.0') {
            $notes = $this->getReleaseNotes($version);
            if (!trim($notes)) {
                $notes = 'Initial Release';
            }
            $pf->setNotes($notes);
        } else {
            $pf->setNotes($this->getReleaseNotes($version));
        }
        require_once 'PEAR/Config.php';
        $config = PEAR_Config::singleton();
        $pf->setConfig($config);
        $pf->flattenFilelist();
        $contents = $pf->getContents();
        foreach ($contents['dir']['file'] as $i => $file) {
            unset($contents['dir']['file'][$i]['attribs']['md5sum']);
        }
        // ooh hacky, will need to fix PEAR in 1.5.2 to make this easier
        $pf->_packageInfo['contents'] = $contents;
        $gen = new PEAR_PackageFile_Generator_v2($pf);
        $xml = $gen->toXml(PEAR_VALIDATE_DOWNLOADING);
        if ($xml) {
            return $xml;
        }
        $this->_errors = $pf->getValidationWarnings(true);
        return false;
    }

    function getErrors()
    {
        return $this->_errors;
    }

    /**
     * Retrieve a brand new package.xml based on the package info
     *
     * @return PEAR_PackageFile_v2_rw
     */
    function getBlankPackage()
    {
        require_once 'PEAR/Validate.php';
        include_once 'pear-database-package.php';
        $info = package::info($this->_package);
        $maintainers = package::info($this->_package, 'authors');
        $pf = new PEAR_PackageFile_v2_rw;
        $pf->setPackage($this->_package);
        $pf->setChannel('pear.php.net');
        $pf->setSummary($info['summary']);
        $pf->setDescription($info['description']);
        foreach ($maintainers as $maintainer) {
            $pf->addMaintainer($maintainer['role'], $maintainer['handle'],
                $maintainer['name'], $maintainer['email'],
                $maintainer['active'] ? 'yes' : 'no');
        }
        $pf->setReleaseVersion('0.1.0');
        $pf->setAPIVersion('0.1.0');
        $pf->setReleaseStability('alpha');
        $pf->setAPIStability('alpha');
        $pf->setPackageType('php');
        $licensemap =
            array(
                'php' => 'http://www.php.net/license',
                'php license' => 'http://www.php.net/license',
                'lgpl' => 'http://www.gnu.org/copyleft/lesser.html',
                'bsd' => 'http://www.opensource.org/licenses/bsd-license.php',
                'bsd license' => 'http://www.opensource.org/licenses/bsd-license.php',
                'bsd style' => 'http://www.opensource.org/licenses/bsd-license.php',
                'bsd-style' => 'http://www.opensource.org/licenses/bsd-license.php',
                'mit' => 'http://www.opensource.org/licenses/mit-license.php',
                'gpl' => 'http://www.gnu.org/copyleft/gpl.html',
                'apache' => 'http://www.opensource.org/licenses/apache2.0.php'
            );
        if (isset($licensemap[strtolower($info['license'])])) {
            $uri = $licensemap[strtolower($info['license'])];
        } else {
            $uri = false;
        }
        $pf->setLicense($info['license'], $uri);
        $pf->clearContents();
        $pf->addFile('/', 'ADDFILESHERE', array('name' => 'ADDFILESHERE', 'role' => 'php'));
        $pf->setPhpDep('4.3.0');
        $pf->setPearinstallerDep('1.5.4');
        $pf->addRelease();
        return $pf;
    }

    /**
     * Retrieve a package file based on a previous release
     *
     * @param string $pfcontents contents of the previous release's package.xml
     * @return PEAR_PackageFile_v2
     */
    function getPackageXmlV2($pfcontents)
    {
        require_once 'PEAR/PackageFile.php';
        require_once 'PEAR/Config.php';
        $config = PEAR_Config::singleton();
        $pkg = new PEAR_PackageFile($config, false, PEAR_TMPDIR);
        PEAR::pushErrorHandling(PEAR_ERROR_RETURN);
        $pf = $pkg->fromXmlString($pfcontents, PEAR_VALIDATE_DOWNLOADING, 'package.xml');
        PEAR::popErrorHandling();
        if (PEAR::isError($pf)) {
            return $pf;
        }
        if ($pf->getPackagexmlVersion() != '1.0') {
            $pf2 = new PEAR_PackageFile_v2_rw;
            $pf2->fromArray($pf->getArray());
        } else {
            require_once 'PEAR/PackageFile/Generator/v1.php';
            $gen = new PEAR_PackageFile_Generator_v1($pf);
            $pf2 = $gen->toV2('PEAR_PackageFile_v2_rw');
        }
        require_once 'pear-database-package.php';
        $info = package::info($pf2->getPackage());
        $pf2->setPackage($this->_package);
        $pf2->setChannel('pear.php.net');
        $pf2->setSummary($info['summary']);
        $pf2->setDescription($info['description']);
        $m = $pf2->getMaintainers();
        foreach ($m as $maintainer) {
            $pf2->deleteMaintainer($maintainer['handle']);
        }
        $maintainers = package::info($this->_package, 'authors');
        foreach ($maintainers as $maintainer) {
            $pf2->addMaintainer($maintainer['role'], $maintainer['handle'],
                $maintainer['name'], $maintainer['email'],
                $maintainer['active'] ? 'yes' : 'no');
        }
        return $pf2;
    }

    /**
     * Format bugs/feature requests assigned to the roadmap and closed
     * in a changelog format
     *
     * @param string $version Roadmap version
     * @return string
     */
    function getReleaseNotes($version)
    {
        $sql = '
            SELECT b.sdesc, b.assign, b.bug_type, b.id
            FROM
                bugdb b, bugdb_roadmap_link l, bugdb_roadmap r
            WHERE
                r.package = ? AND
                r.roadmap_version = ? AND
                l.roadmap_id = r.id AND
                b.id = l.id AND
                b.status = ?
            ORDER BY b.bug_type, b.id';

        $values = array($this->_package, $version, 'Closed');
        $bugs   = $this->_dbh->getAll($sql , $values, DB_FETCHMODE_ASSOC);
        $notes  = '';
        foreach ($bugs as $bug) {
            $fix = in_array($bug['bug_type'], array('Bug', 'Documentation Bug')) ?
                ' * Fix Bug #' :
                ' * Implement Feature #';
            $summary = wordwrap($bug['sdesc'], 70);
            // indent word-wrapped lines
            $summary = implode("\n   ", explode("\n", $summary));
            $notes .= "$fix$bug[id]: $summary [$bug[assign]]\n";
        }
        return "\n$notes ";
    }
}
