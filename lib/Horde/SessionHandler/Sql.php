<?php
/**
 * Horde_SessionHandler implementation for PHP's PEAR database abstraction
 * layer.
 *
 * Required parameters:<pre>
 *   'phptype'  - (string) The database type (e.g. 'pgsql', 'mysql', etc.).
 *   'hostspec' - (string) The hostname of the database server.
 *   'protocol' - (string) The communication protocol ('tcp', 'unix', etc.).
 *   'username' - (string) The username with which to connect to the database.
 *   'password' - (string) The password associated with 'username'.
 *   'database' - (string) The name of the database.
 *   'options'  - (array) Additional options to pass to the database.
 *   'tty'      - (string) The TTY on which to connect to the database.
 *   'port'     - (integer) The port on which to connect to the database.
 * </pre>
 *
 * Optional parameters:<pre>
 *   'table'      - (string) The name of the sessiondata table in 'database'.
 *   'persistent' - (boolean) Use persistent DB connections?
 * </pre>
 *
 * Optional values when using separate reading and writing servers, for example
 * in replication settings:<pre>
 *   'splitread' - (boolean) Whether to implement the separation or not.
 *   'read'      - (array) Array containing the parameters which are
 *                 different for the writer database connection, currently
 *                 supports only 'hostspec' and 'port' parameters.
 * </pre>
 *
 * The table structure can be found in:
 *   horde/scripts/sql/horde_sessionhandler.sql.
 *
 * Copyright 2002-2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @author  Mike Cochrane <mike@graftonhall.co.nz>
 * @package Horde_SessionHandler
 */
class Horde_SessionHandler_Sql extends Horde_SessionHandler
{
    /**
     * Handle for the current database connection.
     *
     * @var DB
     */
    protected $_db;

    /**
     * Handle for the current database connection, used for writing. Defaults
     * to the same handle as $_db if a separate write database is not required.
     *
     * @var DB
     */
    protected $_write_db;

    /**
     * Attempts to open a connection to the SQL server.
     *
     * @param string $save_path     The path to the session object.
     * @param string $session_name  The name of the session.
     *
     * @throws Horde_Exception
     */
    protected function _open($save_path = null, $session_name = null)
    {
        Horde::assertDriverConfig($this->_params, 'sessionhandler',
                                  array('phptype'),
                                  'session handler SQL');

        if (!isset($this->_params['database'])) {
            $this->_params['database'] = '';
        }
        if (!isset($this->_params['username'])) {
            $this->_params['username'] = '';
        }
        if (!isset($this->_params['hostspec'])) {
            $this->_params['hostspec'] = '';
        }
        if (empty($this->_params['table'])) {
            $this->_params['table'] = 'horde_sessionhandler';
        }

        /* Connect to the SQL server using the supplied parameters. */
        $this->_write_db = DB::connect($this->_params,
                                       array('persistent' => !empty($this->_params['persistent']),
                                             'ssl' => !empty($this->_params['ssl'])));
        if (is_a($this->_write_db, 'PEAR_Error')) {
            throw new Horde_Exception_Prior($this->_write_db);
        }

        $this->_write_db->setOption('portability', DB_PORTABILITY_LOWERCASE | DB_PORTABILITY_ERRORS);

        /* Check if we need to set up the read DB connection
         * seperately. */
        if (!empty($this->_params['splitread'])) {
            $params = array_merge($this->_params, $this->_params['read']);
            $this->_db = DB::connect($params,
                                     array('persistent' => !empty($params['persistent']),
                                           'ssl' => !empty($params['ssl'])));
            if (is_a($this->_db, 'PEAR_Error')) {
                throw new Horde_Exception_Prior($this->_db);
            }
            $this->_db->setOption('portability', DB_PORTABILITY_LOWERCASE | DB_PORTABILITY_ERRORS);
        } else {
            /* Default to the same DB handle for reads. */
            $this->_db =& $this->_write_db;
        }
    }

    /**
     * Close the backend.
     *
     * @throws Horde_Exception
     */
    protected function _close()
    {
        /* Close any open transactions. */
        $this->_db->commit();
        $this->_db->autoCommit(true);
        $this->_write_db->commit();
        $this->_write_db->autoCommit(true);

        @$this->_write_db->disconnect();
        @$this->_db->disconnect();
    }

    /**
     * Read the data for a particular session identifier from the backend.
     *
     * @param string $id  The session identifier.
     *
     * @return string  The session data.
     */
    protected function _read($id)
    {
        /* Begin a transaction. */
        $result = $this->_write_db->autocommit(false);
        if (is_a($result, 'PEAR_Error')) {
            Horde::logMessage($result, 'ERR');
            return '';
        }

        /* Execute the query. */
        $result = Horde_SQL::readBlob($this->_write_db, $this->_params['table'], 'session_data', array('session_id' => $id));

        if (is_a($result, 'PEAR_Error')) {
            Horde::logMessage($result, 'ERR');
            return '';
        }

        return $result;
    }

    /**
     * Write session data to the backend.
     *
     * @param string $id            The session identifier.
     * @param string $session_data  The session data.
     *
     * @return boolean  True on success, false otherwise.
     */
    protected function _write($id, $session_data)
    {
        /* Build the SQL query. */
        $query = sprintf('SELECT session_id FROM %s WHERE session_id = ?',
                         $this->_params['table']);
        $values = array($id);

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('SQL Query by Horde_SessionHandler_Sql::write(): query = "%s"', $query), 'DEBUG');

        /* Execute the query. */
        $result = $this->_write_db->getOne($query, $values);
        if (is_a($result, 'PEAR_Error')) {
            Horde::logMessage($result, 'ERR');
            return false;
        }

        if ($result) {
            $result = Horde_SQL::updateBlob($this->_write_db, $this->_params['table'], 'session_data',
                                            $session_data, array('session_id' => $id),
                                            array('session_lastmodified' => time()));
        } else {
            $result = Horde_SQL::insertBlob($this->_write_db, $this->_params['table'], 'session_data',
                                            $session_data, array('session_id' => $id,
                                                                 'session_lastmodified' => time()));
        }

        if (is_a($result, 'PEAR_Error')) {
            $this->_write_db->rollback();
            $this->_write_db->autoCommit(true);
            Horde::logMessage($result, 'ERR');
            return false;
        }

        $result = $this->_write_db->commit();
        if (is_a($result, 'PEAR_Error')) {
            $this->_write_db->autoCommit(true);
            Horde::logMessage($result, 'ERR');
            return false;
        }

        $this->_write_db->autoCommit(true);
        return true;
    }

    /**
     * Destroy the data for a particular session identifier in the backend.
     *
     * @param string $id  The session identifier.
     *
     * @return boolean  True on success, false otherwise.
     */
    public function destroy($id)
    {
        /* Build the SQL query. */
        $query = sprintf('DELETE FROM %s WHERE session_id = ?',
                         $this->_params['table']);
        $values = array($id);

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('SQL Query by Horde_SessionHandler_Sql::destroy(): query = "%s"', $query), 'DEBUG');

        /* Execute the query. */
        $result = $this->_write_db->query($query, $values);
        if (is_a($result, 'PEAR_Error')) {
            Horde::logMessage($result, 'ERR');
            return false;
        }

        $result = $this->_write_db->commit();
        if (is_a($result, 'PEAR_Error')) {
            Horde::logMessage($result, 'ERR');
            return false;
        }

        return true;
    }

    /**
     * Garbage collect stale sessions from the backend.
     *
     * @param integer $maxlifetime  The maximum age of a session.
     *
     * @return boolean  True on success, false otherwise.
     */
    public function gc($maxlifetime = 300)
    {
        /* Build the SQL query. */
        $query = sprintf('DELETE FROM %s WHERE session_lastmodified < ?',
                         $this->_params['table']);
        $values = array(time() - $maxlifetime);

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('SQL Query by Horde_SessionHandler_Sql::gc(): query = "%s"', $query), 'DEBUG');

        /* Execute the query. */
        $result = $this->_write_db->query($query, $values);
        if (is_a($result, 'PEAR_Error')) {
            Horde::logMessage($result, 'ERR');
            return false;
        }

        return true;
    }

    /**
     * Get a list of the valid session identifiers.
     *
     * @return array  A list of valid session identifiers.
     * @throws Horde_Exception
     */
    public function getSessionIDs()
    {
        $this->open();

        /* Build the SQL query. */
        $query = 'SELECT session_id FROM ' . $this->_params['table'] .
                 ' WHERE session_lastmodified >= ?';
        $values = array(time() - ini_get('session.gc_maxlifetime'));

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('SQL Query by Horde_SessionHandler_Sql::getSessionIDs(): query = "%s"', $query), 'DEBUG');

        /* Execute the query. */
        $result = $this->_db->getCol($query, 0, $values);
        if (is_a($result, 'PEAR_Error')) {
            Horde::logMessage($result, 'ERR');
            return false;
        }

        return $result;
    }

}
