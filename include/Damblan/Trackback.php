<?php

require_once 'Services/Trackback.php';

class Damblan_Trackback extends Services_Trackback
{
    /**
     * The time the trackback has been discovered.
     *
     * @var int
     * @since
     */
    var $_timestamp = 0;

    /**
     * Boolean flag, if the trackback has been improved by a PEAR developer, yet.
     *
     * @var bool
     * @since
     */
    var $_approved = false;


    var $akismetOptions = array();

    /**
     * Constructor
     * Overwriten constructor to get the timestamp while creating a trackback.
     * The timestamp is used as the primary key for the trackback table, in
     * combination with the trackback ID (which is the package name).
     *
     * @since
     * @access public
     * @param array $data       The trackback data, an associative array of string values,
     *                          that may/has to contain the following keys, depending on the
     *                          purpose of the created trackback instance:
     *                          - 'id': The ID of the page the trackback was received for.
     *                          - 'trackback_url': The URL where trackbacks for this site should go.
     *                          - 'title': The title of the blog entry, which produced this trackback.
     *                          - 'url': The URL of the entry, which produced this trackback.
     *                          - 'excerpt': An abstract of the blog entry, which produced this trackback.
     *                          - 'blog_name': The name of the weblog, which produced this trackback.
     * @param int   $timestamp  A unix timestamp representing the time the trackback
     *                          was received.
     * @param bool  $approved   Wether the trackback is approved, yet. Default is false.
     * @return void
     */
    function __construct($data)
    {
        $this->akismetOptions = array(
            'url' =>  'http://'.PEAR_CHANNELNAME.'/',
            'key' =>  include(TRACKBACK_AKISMET_KEY_FILE),
        );

        foreach ($data as $key => $val) {
            if ($key == 'approved')
                $val = ($val == 'true');
            if ($key == 'ip')
                $this->set('host', $val);
            $this->set($key, $val);
        }
    }

    /**
     * Get number of all in all available trackbacks.
     *
     *
     * @param object(DB) $dbh The database connection.
     * @param bool $approvedOnly Only show approved trackbacks? (!$isAdmin)
     * @param bool $unapprovedOnly Only show unapproved trackbacks?
     */
    function getCount(&$dbh, $approvedOnly = true, $unapprovedOnly  = false)
    {
        $sql = 'SELECT COUNT(*) FROM trackbacks';
        if ($approvedOnly) {
            $sql .= ' WHERE approved = '.$dbh->quoteSmart('true');
        } else if ($unapprovedOnly) {
            $sql .= ' WHERE approved = '.$dbh->quoteSmart('false');
        }
        return $dbh->getOne($sql);
    }

    /**
     * Check for possible spam
     * Checks the database for recent trackbacks from the same IP.
     * Every IP is allows to post $this->_repostCount times in
     * $this->_repostTimespan seconds.
     *
     * @since
     * @param object(DB) $dbh The database handle.
     * @return bool True if this is a repost, otherwise false.
     */
    function checkRepost(&$dbh, $count = TRACKBACK_REPOST_COUNT, $timespan = TRACKBACK_REPOST_TIMESPAN)
    {
        $sql = 'SELECT COUNT(timestamp) FROM trackbacks WHERE
            ip = '.$dbh->quoteSmart($this->get('host')).' AND
            timestamp > '.$dbh->quoteSmart($this->get('timestamp') - $timespan);
        $res = $dbh->getOne($sql);
        if (PEAR::isError($res)) {
            return PEAR::raiseError('Database error, please try again later.');
        }

        if ($res >= $count) {
            return true;
        }

        return false;
    }

    /**
     * get
     * Overwritten get() to receive timestamp, ip and approved state correctly.
     *
     * @since
     * @access public
     * @param   mixed $key The name of the property to receive.
     * @return  mixed $val The value of the property.
     */
    function get($key)
    {
        $testKey = '_'.$key;
        if (isset($this->$testKey)) {
            return $this->$testKey;
        }

        return Services_Trackback::get($key);
    }

    /**
     * set
     * Overwritten set() to set timestamp, ip and approved state correctly.
     *
     * @since
     * @access public
     * @param   mixed $key The name of the property to receive.
     * @return  mixed $val The value of the property.
     */
    function set($key, $value)
    {
        $testKey = '_'.$key;
        if (isset($this->$testKey)) {
            $this->$testKey = $value;
            return true;
        }

        return Services_Trackback::set($key, $value);
    }

    /**
     * Save a trackback into the database.
     * This method saves a trackback into the database. BEWARE: It
     * does not update exististing database entries! The trackback is
     * just inserted.
     *
     * @since
     * @access public
     * @param object DB $dbh Database connection object (PEAR::DB).
     * @return void
     */
    function save(&$dbh)
    {
        $necessaryData = array('id', 'title', 'url', 'excerpt', 'blog_name', 'host');
        $this->_checkData($necessaryData);
        $data = $this->_getDecodedData($necessaryData);
        $approved = ($this->_approved) ? 'true' : 'false';
        $extra = $this->get('extra');
        $sql = "INSERT INTO trackbacks
            (id, title, url, excerpt, blog_name, approved,
             timestamp, ip, referrer, user_agent)
            VALUES (
                    ".$dbh->quoteSmart($this->get('id')).",
                    ".$dbh->quoteSmart($this->get('title')).",
                    ".$dbh->quoteSmart($this->get('url')).",
                    ".$dbh->quoteSmart($this->get('excerpt')).",
                    ".$dbh->quoteSmart($this->get('blog_name')).",
                    ".$dbh->quoteSmart($approved).",
                    ".$dbh->quoteSmart($this->get('timestamp')).",
                    ".$dbh->quoteSmart($this->get('host')).",
                    ".$dbh->quoteSmart(isset($extra['HTTP_REFERER']) ? $extra['HTTP_REFERER'] : '').",
                    ".$dbh->quoteSmart(isset($extra['HTTP_USER_AGENT']) ? $extra['HTTP_USER_AGENT'] : '')."
                )";
        $res = $dbh->query($sql);
        if (DB::isError($res)) {
            return PEAR::raiseError('Unable to save trackback: '.$res->getMessage());
        }

        return true;
    }

    /**
     * Load a trackback from the database.
     * Load a trackback from the database. At least the ID and timestamp
     * of the trackback have to be set.
     *
     * @since
     * @access public
     * @param object DB $dbh The database connection.
     * @param int $timestamp The timestamp of the trackback to load.
     * @return void
     */
    function load(&$dbh)
    {
        $necessaryData = array('id');
        if (PEAR::isError($necessaryData)) {
            return $necessaryData;
        }

        if (!isset($this->_timestamp)) {
            return PEAR::raiseError('Necessary attribute timestamp missing.');
        }

        $this->_checkData($necessaryData);

        $data = $this->_getDecodedData($necessaryData);

        $sql = "SELECT id, title, excerpt, blog_name, url, timestamp, approved, ip, referrer, user_agent FROM trackbacks WHERE
                    id = ".$dbh->quoteSmart($this->get('id'))."
                    AND timestamp = ".$dbh->quoteSmart($this->get('timestamp'));

        $res = $dbh->getRow($sql, null, DB_FETCHMODE_ASSOC);

        if (DB::isError($res)) {
            return $res;
        } elseif (!is_array($res) || !count($res)) {
            return false;
        }

        foreach ($res as $key => $val) {
            if ($key == 'approved') {
                $val = ($val == 'true');
            }
            if ($key == 'ip') {
                $this->set('host', $val);
            }
            if ($key == 'referrer') {
                $this->_data['extra']['HTTP_REFERER'] = $val;
            }
            if ($key == 'user_agent') {
                $this->_data['extra']['HTTP_USER_AGENT'] = $val;
            }
            $this->set($key, $val);
        }
        return true;
    }

    /**
     * Approves a trackback.
     * Sets the approved flag for the trackback to true and saves that to
     * the database.
     *
     * @since
     * @access public
     * @param object DB $dbh The database connection.
     * @param int $timestamp The timestamp of the trackback to load.
     * @return void
     */
    function approve(&$dbh)
    {
        $this->load($dbh);
        $akismet = $this->createSpamCheck('Akismet', $this->akismetOptions);
        $akismet->submitHam($this);

        $necessaryData = array('id');
        if (!isset($this->_timestamp)) {
            return PEAR::raiseError('Could not approve trackback. Timestamp missing.');
        }
        $data = $this->_getDecodedData($necessaryData);
        if (PEAR::isError($data)) {
            return $data;
        }
        $sql = "UPDATE trackbacks SET approved = ".$dbh->quoteSmart('true')." WHERE
                    id = ".$dbh->quoteSmart($this->get('id'))."
                    AND timestamp = ".$dbh->quoteSmart($this->get('timestamp'));
        $res = $dbh->query($sql) ;
        if (DB::isError($res)) {
            return PEAR::raiseError('Could not approve trackback.');
        }
        $this->_approved = true;
        return true;
    }

    /**
     * delete
     * Delete a trackback
     *
     * @since
     * @access public
     * @param
     * @return void
     */
    function delete(&$dbh, $asSpam = false)
    {
        $necessaryData = array('id');
        $data = $this->_getDecodedData($necessaryData);
        if (PEAR::isError($data)) {
            return $data;
        }
        $res = $this->load($dbh);
        if (PEAR::isError($res)) {
            return $res;
        }
        if ($asSpam === true) {
            $akismet = $this->createSpamCheck('Akismet', $this->akismetOptions);
            $akismet->submitSpam($this);
        }
        $sql = "DELETE FROM trackbacks WHERE
                    id = ".$dbh->quoteSmart($this->get('id'))."
                    AND timestamp = ".$dbh->quoteSmart($this->get('timestamp'));
        $res = $dbh->query($sql) ;
        if (DB::isError($res)) {
            return PEAR::raiseError('Could not delete trackback: '.$res->getMessage());
        }
        return true;
    }

    /**
     * listTrackbacks
     * Get a list of trackbacks for an ID. The list can be influenced through
     * several parameters of this method.
     *
     * @since
     * @access public
     * @static
     * @param object(DB)    $dbh            The database connection object (PEAR::DB).
     * @param int           $id             The ID to fetch trackbacks for.
     * @param bool          $approvedOnly   Wether to fetch only approved trackbacks (default is true).
     * @param string        $orderBy        Order criteria for the list (default is 'timestamp DESC').
     * @param int           $limit          The limit of trackbacks to list (default is 10).
     * @return array                        Array of PEAR_Trackback objects.
     * @throws Exception If no results are received.
     */
    function listTrackbacks(&$dbh, $id, $approvedOnly = true, $orderBy = 'timestamp DESC', $limit = 10)
    {
        $sql = 'SELECT id, title, excerpt, blog_name, url, timestamp, approved, ip FROM trackbacks WHERE
                id LIKE '.$dbh->quoteSmart($id);
        if ($approvedOnly) {
            $sql .= ' AND approved = '.$dbh->quoteSmart('true');
        }
        $sql .= ' ORDER BY '.$orderBy;
        $sql .= ' LIMIT '.$limit;
        $res = $dbh->getAll($sql, null, DB_FETCHMODE_ASSOC);
        if (DB::isError($res)) {
            return PEAR::raiseError('Could not receive trackback list: '.$res->getMessage());
        }
        $ret = array();
        foreach ($res as $row) {
            $ret[] = new Damblan_Trackback($row);
        }
        return $ret;
    }

    /**
     * recentTrackbacks
     * Get a list of trackbacks independent from the package ID.
     *
     * @since
     * @access public
     * @static
     * @param object(DB)    $dbh            The database connection object (PEAR::DB).
     * @param int           $offset         Start position for the trackback list.
     * @param int           $number         Number of trackbacks to list.
     * @param bool          $approvedOnly   Wether to fetch only approved trackbacks (default is true).
     * @return array                        Array of PEAR_Trackback objects.
     * @throws Exception If no results are received.
     */
    function recentTrackbacks(&$dbh, $offset = 0, $number = 10, $approvedOnly = true, $unapprovedOnly = false)
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('Offset out of range. Offset must be integer >= 0.');
        }
        if (($number < 1) || ($number > 50)) {
            throw new InvalidArgumentException('Number out of range. Number must be integer between 1 and 50.');
        }
        $sql = 'SELECT id, title, excerpt, blog_name, url, timestamp, approved, ip
                FROM trackbacks';
        if ($approvedOnly) {
            $sql .= ' WHERE approved = '.$dbh->quoteSmart('true');
        } else if ($unapprovedOnly) {
            $sql .= ' WHERE approved = '.$dbh->quoteSmart('false').' OR approved = ""';
        }
        $sql .= ' ORDER BY timestamp DESC LIMIT ' . $offset . ',' . $number;
        $res = $dbh->getAll($sql, null, DB_FETCHMODE_ASSOC);
        if (DB::isError($res)) {
            return PEAR::raiseError('Could not receive trackback list: '.$res->getMessage());
        }
        $ret = array();
        foreach ($res as $row) {
            $ret[] = new Damblan_Trackback($row);
        }
        return $ret;
    }

    /**
     * Get maintainers to inform of a trackback (the lead maintainers of a package).
     *
     *
     * @since
     * @access public
     * @param  boolean $activeOnly  To get only active leads
     *                 is set to false by default so there's
     *                 no bc problems.
     *
     * @return array(string) The list of maintainer emails.
     */
    function getMaintainers ($activeOnly = true)
    {
        include_once 'pear-database-maintainer.php';
        $maintainers = maintainer::get($this->get('id'), true, $activeOnly);
        $res = array();

        include_once 'pear-database-user.php';
        foreach ($maintainers as $maintainer => $data) {
            $tmpUser = user::info($maintainer, 'email');
            if (empty($tmpUser['email'])) {
                continue;
            }
            $res[] = $tmpUser['email'];
        }
        return $res;
    }

    function compareWords($source, $element)
    {
        return (strpos(strtolower(html_entity_decode($element)), strtolower($source)));
    }
}
