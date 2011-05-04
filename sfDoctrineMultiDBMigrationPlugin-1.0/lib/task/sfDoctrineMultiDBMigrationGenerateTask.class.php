<?php


/*
 * This file is part of the multi DB migrate package.
 * (c) Yannick Mahe <yannick.mahe@bysoft.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class MultiDB_Migration_GenerateTask extends sfDoctrineBaseTask
{
  /**
   * @see sfTask
   */
  protected function configure()
  {
    ini_set('memory_limit','1024M');
    $this->addOptions(array(
      new sfCommandOption('application', null, sfCommandOption::PARAMETER_OPTIONAL, 'The application name', true),
      new sfCommandOption('env', null, sfCommandOption::PARAMETER_REQUIRED, 'The environment', 'dev'),
    ));

    $this->namespace = 'multiDBmigration';
    $this->name = 'generate';
    $this->briefDescription = 'Generate migration classes by producing a diff between your old and new schema.';

    $this->detailedDescription = <<<EOF
The [multiDBmigration:generate|INFO] task generates migration classes by
producing a diff between your old and new schema.

  [./symfony doctrine:generate-migrations-diff|INFO]
EOF;
  }

  /**
   * @see sfTask
   */
  protected function execute($arguments = array(), $options = array())
  {
    $databaseManager = new sfDatabaseManager($this->configuration);
    $config = $this->getCliConfig();

    $this->logSection('multiDBmigration', 'generating migration diff');

    if (!is_dir($config['migrations_path']))
    {
      $this->getFilesystem()->mkdirs($config['migrations_path']);
    }
    
    $migrationsPath = $config['migrations_path'];
    $modelsPath = $config['models_path'];
    $yamlSchemaPath = $this->prepareSchemaFile($config['yaml_schema_path']);

    $migration = new MultiDB_Migration($migrationsPath);
    $diff = new MultiDB_Migration_Diff($modelsPath, $yamlSchemaPath, $migration);
    $changes = $diff->generateMigrationClasses();

    $numChanges = count($changes, true) - count($changes);

    if ( ! $numChanges) {
        throw new Doctrine_Task_Exception('Could not generate migration classes from difference');
    } else {
        $this->logSection('multiDBmigration', 'Generated migration classes successfully from difference');
    }
  }
}
