<?php
/*
 *  $Id: Migration.php 1080 2007-02-10 18:17:08Z jwage $
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.phpdoctrine.org>.
 */

/**
 * Doctrine_Migration
 *
 * this class represents a database view
 *
 * @package     Doctrine
 * @subpackage  Migration
 * @license     http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link        www.phpdoctrine.org
 * @since       1.0
 * @version     $Revision: 1080 $
 * @author      Jonathan H. Wage <jwage@mac.com>
 */
class MultiDB_Migration extends Doctrine_Migration
{
    protected $_migrationTableName = 'migration_version',
              $_migrationTableCreated = false,
              $_connection,
              $_migrationClassesDirectory = array(),
              $_migrationClasses = array(),
              $_reflectionClass,
              $_errors = array(),
              $_process;

    protected static $_migrationClassesForDirectories = array();



    /**
     * Load migration classes from the passed directory. Any file found with a .php
     * extension will be passed to the loadMigrationClass()
     *
     * @param string $directory  Directory to load migration classes from
     * @return void
     */
    public function loadMigrationClassesFromDirectory($directory = null)
    {
        $directory = $directory ? $directory:$this->_migrationClassesDirectory;
        $classesToLoad = array();
        $classes = get_declared_classes();
        foreach ((array) $directory as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir),
                RecursiveIteratorIterator::LEAVES_ONLY);

            if (isset(self::$_migrationClassesForDirectories[$dir])) {
                foreach (self::$_migrationClassesForDirectories[$dir] as $num => $className) {
                    $this->_migrationClasses[$connectionName][$num] = $className;
                }
            }
            foreach ($it as $file) {
                $info = pathinfo($file->getFileName());
                if (isset($info['extension']) && $info['extension'] == 'php') {
                    require_once($file->getPathName());

                    $array = array_diff(get_declared_classes(), $classes);
                    $className = end($array);

                    if ($className) {
                        $e = explode('_', $file->getFileName());
                        $timestamp = $e[0];

                        $classesToLoad[$timestamp.$className] = array('className' => $className, 'path' => $file->getPathName());
                    }
                }
            }
        }
        ksort($classesToLoad);
        foreach ($classesToLoad as $class) {
            $info = dirname($class['path']);
            $info = explode(DIRECTORY_SEPARATOR,$info);
            $connectionName = $info[sizeof($info)-1];
            
            $this->loadMigrationClass($class['className'], $class['path'], $connectionName);
        }
    }

    /**
     * Load the specified migration class name in to this migration instances queue of
     * migration classes to execute. It must be a child of Doctrine_Migration in order
     * to be loaded.
     *
     * @param string $name
     * @return void
     */
    public function loadMigrationClass($name, $path = null, $connectionName = '')
    {
        $class = new ReflectionClass($name);

        while ($class->isSubclassOf($this->_reflectionClass)) {

            $class = $class->getParentClass();
            if ($class === false) {
                break;
            }
        }

        if ($class === false) {
            return false;
        }

        if (empty($this->_migrationClasses[$connectionName])) {
            $classMigrationNum = 1;
        } else {
            $nums = array_keys($this->_migrationClasses[$connectionName]);
            $num = end($nums);
            $classMigrationNum = $num + 1;
        }

        $this->_migrationClasses[$connectionName][$classMigrationNum] = $name;

        if ($path) {
            $dir = dirname($path);
            self::$_migrationClassesForDirectories[$dir][$classMigrationNum] = $name;
        }
    }

    /**
     * Get all the loaded migration classes. Array where key is the number/version
     * and the value is the class name.
     *
     * @return array $migrationClasses
     */
    public function getMigrationClasses($connectionName = '')
    {
        return $this->_migrationClasses[$connectionName];
    }


    /**
     * Gets the latest possible version from the loaded migration classes
     *
     * @return integer $latestVersion
     */
    public function getLatestVersion($connectionName = '')
    {
        $versions = array_keys($this->_migrationClasses[$connectionName]);
        rsort($versions);

        return isset($versions[0]) ? $versions[0]:0;
    }

    /**
     * Get the next incremented version number based on the latest version number
     * using getLatestVersion()
     *
     * @return integer $nextVersion
     */
    public function getNextVersion($connectionName = '')
    {
        return $this->getLatestVersion($connectionName) + 1;
    }

    /**
     * Get the next incremented class version based on the loaded migration classes
     *
     * @return integer $nextMigrationClassVersion
     */
    public function getNextMigrationClassVersion($connectionName = '')
    {
        if (empty($this->_migrationClasses[$connectionName])) {
            return 1;
        } else {
            $nums = array_keys($this->_migrationClasses[$connectionName]);
            $num = end($nums) + 1;
            return $num;
        }
    }

    /**
     * Perform a migration process by specifying the migration number/version to
     * migrate to. It will automatically know whether you are migrating up or down
     * based on the current version of the database.
     *
     * @param  integer $to       Version to migrate to
     * @param  boolean $dryRun   Whether or not to run the migrate process as a dry run
     * @return integer $to       Version number migrated to
     * @throws Doctrine_Exception
     */
    public function migrate($to = null, $dryRun = false)
    {
        $this->clearErrors();
            $this->_connection->beginTransaction();

            try {
                // If nothing specified then lets assume we are migrating from
                // the current version to the latest version
                if ($to === null) {
                    $to = $this->getLatestVersion($this->_connection->getName());
                }

                $this->_doMigrate($to);
            } catch (Exception $e) {
                $this->addError($e);
            }

            if ($this->hasErrors()) {
                $this->_connection->rollback();

                if ($dryRun) {
                    return false;
                } else {
                    $this->_throwErrorsException();
                }
            } else {
                if ($dryRun) {
                    $this->_connection->rollback();
                    if ($this->hasErrors()) {
                        return false;
                    } else {
                        return $to;
                    }
                } else {
                    $this->_connection->commit();
                    $this->setCurrentVersion($to);
                    return $to;
                }
        }
        return false;
    }

    /**
     * Get instance of migration class for number/version specified
     *
     * @param integer $num
     * @throws Doctrine_Migration_Exception $e
     */
    public function getMigrationClass($num,$connectionName='')
    {
        if (isset($this->_migrationClasses[$connectionName][$num])) {
            $className = $this->_migrationClasses[$connectionName][$num];
            return new $className();
        }

        throw new Doctrine_Migration_Exception('Could not find migration class for migration step: '.$num);
    }

    /**
     * Do the actual migration process
     *
     * @param  integer $to
     * @return integer $to
     * @throws Doctrine_Exception
     */
    protected function _doMigrate($to)
    {
        $this->_createMigrationTable();

        $from = $this->getCurrentVersion();

        if ($from == $to) {
            return;
        }

        $direction = $from > $to ? 'down':'up';

        if ($direction === 'up') {
            for ($i = $from + 1; $i <= $to; $i++) {
                $this->_doMigrateStep($direction, $i);
            }
        } else {
            for ($i = $from; $i > $to; $i--) {
                $this->_doMigrateStep($direction, $i);
            }
        }

        return $to;
    }

    /**
     * Perform a single migration step. Executes a single migration class and
     * processes the changes
     *
     * @param string $direction Direction to go, 'up' or 'down'
     * @param integer $num
     * @return void
     */
    protected function _doMigrateStep($direction, $num)
    {
        try {
            $migration = $this->getMigrationClass($num,$this->getConnection()->getName());

            $method = 'pre' . $direction;
            $migration->$method();

            if (method_exists($migration, $direction)) {
                $migration->$direction();
            } else if (method_exists($migration, 'migrate')) {
                $migration->migrate($direction);
            }

            if ($migration->getNumChanges() > 0) {
                $changes = $migration->getChanges();
                if ($direction == 'down' && method_exists($migration, 'migrate')) {
                    $changes = array_reverse($changes);
                }
                foreach ($changes as $value) {
                    list($type, $change) = $value;
                    $funcName = 'process' . Doctrine_Inflector::classify($type);
                    if (method_exists($this->_process, $funcName)) {
                        try {
                            $this->_process->$funcName($change);
                        } catch (Exception $e) {
                            $this->addError($e);
                        }
                    } else {
                        throw new Doctrine_Migration_Exception(sprintf('Invalid migration change type: %s', $type));
                    }
                }
            }

            $method = 'post' . $direction;
            $migration->$method();
        } catch (Exception $e) {
            $this->addError($e);
        }
    }
}