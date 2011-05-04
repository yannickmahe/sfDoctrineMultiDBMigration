<?php

/*
 * This file is part of the multi DB migrate package.
 * (c) Yannick Mahe <yannick.mahe@bysoft.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class MultiDB_Migration_MigrateTask extends sfDoctrineBaseTask
{
  /**
   * @see sfTask
   */
  protected function configure()
  {
    $this->addArguments(array(
      new sfCommandArgument('version', sfCommandArgument::OPTIONAL, 'The version to migrate to'),
    ));

    $this->addOptions(array(
      new sfCommandOption('up', null, sfCommandOption::PARAMETER_NONE, 'Migrate up one version'),
      new sfCommandOption('down', null, sfCommandOption::PARAMETER_NONE, 'Migrate down one version'),
      new sfCommandOption('dry-run', null, sfCommandOption::PARAMETER_NONE, 'Do not persist migrations'),
    ));

    $this->namespace = 'multiDBmigration';
    $this->name = 'migrate';
    $this->briefDescription = 'Migrates database to current/specified version';

    $this->detailedDescription = <<<EOF
The [multiDBmigration:migrate|INFO] task migrates the database:

  [./symfony multiDBmigration:migrate|INFO]

Provide a version argument to migrate to a specific version:

  [./symfony multiDBmigration:migrate 10|INFO]

To migration up or down one migration, use the [--up|COMMENT] or [--down|COMMENT] options:

  [./symfony multiDBmigration:migrate --down|INFO]

If your database supports rolling back DDL statements, you can run migrations
in dry-run mode using the [--dry-run|COMMENT] option:

  [./symfony multiDBmigration:migrate --dry-run|INFO]
EOF;
  }

  /**
   * @see sfTask
   */
  protected function execute($arguments = array(), $options = array())
  {
    $databaseManager = new sfDatabaseManager($this->configuration);

    $config = $this->getCliConfig();
    
    //Run this for every connection. All connection have folders in the migration path
    //Get the connection names
    $dir = scandir($config['migrations_path']);
    foreach($dir as $connectionName){
        if($connectionName == '.' || $connectionName == '..' || $connectionName == '.svn'){
            continue;
        }
        
        $migration = new MultiDB_Migration($config['migrations_path'].DIRECTORY_SEPARATOR.$connectionName,$connectionName);
        $from = $migration->getCurrentVersion();

        if (is_numeric($arguments['version']))
        {
          $version = $arguments['version'];
        }
        else if ($options['up'])
        {
          $version = $from + 1;
        }
        else if ($options['down'])
        {
          $version = $from - 1;
        }
        else
        {
          $version = $migration->getLatestVersion($connectionName);
        }

        if ($from == $version)
        {
          $this->logSection('multiDBmigration', sprintf($connectionName.' already at migration version %s', $version));
        }

        $this->logSection('multiDBmigration', sprintf('Migrating '.$connectionName.' from version %s to %s%s', $from, $version, $options['dry-run'] ? ' (dry run)' : ''));
        try
        {
          $migration->migrate($version, $options['dry-run']);
        }
        catch (Exception $e)
        {
        }
        
        //render errors
        if ($migration->hasErrors())
        {
          if ($this->commandApplication && $this->commandApplication->withTrace())
          {
            $this->logSection('multiDBmigration', 'The following errors occurred:');
            foreach ($migration->getErrors() as $error)
            {
              $this->commandApplication->renderException($error);
            }
          }
          else
          {
            $this->logBlock(array_merge(
              array('The following errors occurred:', ''),
              array_map(create_function('$e', 'return \' - \'.$e->getMessage();'), $migration->getErrors())
            ), 'ERROR_LARGE');
          }

          return 1;
        }
    
    }



    $this->logSection('multiDBmigration', 'Migration complete');
  }
}
