<?php

namespace Goez\DbUnit;

use Faker\Factory as FakerFactory;
use Faker\Generator as FakerGenerator;
use Goez\DbUnit\Constraint\HasInDatabase;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Factory as EloquentFactory;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit_Framework_Constraint_Not as ReverseConstraint;

/**
 * Trait RefreshDatabase
 * @package Goez\DbUnit
 * @property array $connectionsToTransact
 */
trait RefreshDatabase
{
    /**
     * @var Container
     */
    protected $container;

    /**
     * @var Manager
     */
    protected $databaseManager;

    /**
     * @var array
     */
    private $config = [];

    /**
     * Define hooks to migrate the database before and after each test.
     *
     * @param array $config
     * @return void
     * @throws \Exception
     */
    public function refreshDatabase(array $config)
    {
        $this->initContainer();
        $this->initConfig($config);
        $this->initDatabaseConnection();
        $this->initMigrations();
        $this->initFactories();
        $this->runMigration();
        $this->beginDatabaseTransaction();
    }

    /**
     * @return void
     */
    private function initContainer()
    {
        $this->container = Container::getInstance();
        Facade::setFacadeApplication($this->container);
    }

    /**
     * @param array $config
     * @throws \InvalidArgumentException
     */
    private function initConfig(array $config)
    {
        if (!isset($config['database_config'])) {
            throw new InvalidArgumentException('Database configuration does not exist.');
        }
        if (!isset($config['migration_path'])) {
            throw new InvalidArgumentException('Migrations directories does not exist.');
        }
        if (!is_array($config['migration_path'])) {
            $config['migration_path'] = (array)$config['migration_path'];
        }
        foreach ($config['migration_path'] as $path) {
            if (!is_dir($path)) {
                throw new InvalidArgumentException('Migrations directory "' . $path . '"" does not exist.');
            }
        }
        if (!file_exists($config['factory_path'])) {
            throw new InvalidArgumentException('Factories directory "' . $config['factory_path'] . '"" does not exist.');
        }
        $this->config = $config;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function initDatabaseConnection()
    {
        /** @var array $databaseConfig */
        $databaseConfig = $this->config['database_config'];

        $manager = new Manager();
        foreach ($databaseConfig as $name => $connectionConfig) {
            $manager->addConnection($connectionConfig, $name);
        }
        $manager->setEventDispatcher(new Dispatcher($this->container));
        $manager->setAsGlobal();
        $manager->bootEloquent();
        $this->container->instance(ConnectionResolverInterface::class, $manager->getDatabaseManager());
        $this->container->instance(Manager::class, $manager);
    }

    /**
     * @return void
     */
    private function initMigrations()
    {
        /** @var Manager $manager */
        $manager = $this->container->make(Manager::class);
        $databaseMigrationRepository = new DatabaseMigrationRepository($manager->getDatabaseManager(), 'migration');
        $databaseMigrationRepository->createRepository();
        $this->container->instance(MigrationRepositoryInterface::class, $databaseMigrationRepository);
        $this->container->instance('db', $manager->getDatabaseManager());
    }

    /**
     * @return void
     */
    private function initFactories()
    {
        $this->container->singleton(FakerGenerator::class, function () {
            return FakerFactory::create('en_US');
        });

        $this->container->singleton(EloquentFactory::class, function (Container $container) {
            return EloquentFactory::construct(
                $container->make(FakerGenerator::class), $this->config['factory_path']
            );
        });
    }

    /**
     * Refresh a conventional test database.
     *
     * @return void
     */
    protected function runMigration()
    {
        $paths = $this->config['migration_path'];

        /** @var Migrator $migrator */
        $migrator = $this->container->make(Migrator::class);
        $migrator->run($paths);
    }

    /**
     * Begin a database transaction on the testing database.
     *
     * @return void
     * @throws \Exception
     */
    public function beginDatabaseTransaction()
    {
        foreach ($this->connectionsToTransact() as $name) {
            Manager::connection($name)->beginTransaction();
        }
    }

    /**
     * @return void
     */
    public function rollbackDatabase()
    {
        foreach ($this->connectionsToTransact() as $name) {
            $connection = Manager::connection($name);
            $connection->rollBack();
            $connection->disconnect();
        }
    }

    /**
     * The database connections that should have transactions.
     *
     * @return array
     */
    protected function connectionsToTransact()
    {
        return property_exists($this, 'connectionsToTransact')
            ? $this->connectionsToTransact : [null];
    }

    /**
     * @param string $class
     * @param array $data
     */
    protected function prepareModelData($class, array $data)
    {
        if (!isset($data[0])) {
            $data = [$data];
        }

        $model = factory($class);
        foreach ($data as $row) {
            $model->create($row);
        }
    }

    /**
     * Assert that a given where condition exists in the database.
     *
     * @param  string $table
     * @param  array $data
     * @param  string $connection
     * @return $this
     */
    protected function assertDatabaseHas($table, array $data, $connection = null)
    {
        /** @var $this TestCase|RefreshDatabase */
        $this->assertThat(
            $table, new HasInDatabase($this->getConnection($connection), $data)
        );

        return $this;
    }

    /**
     * Assert that a given where condition does not exist in the database.
     *
     * @param  string $table
     * @param  array $data
     * @param  string $connection
     * @return $this
     */
    protected function assertDatabaseMissing($table, array $data, $connection = null)
    {
        /** @var $this TestCase|RefreshDatabase */
        $constraint = new ReverseConstraint(
            new HasInDatabase($this->getConnection($connection), $data)
        );

        $this->assertThat($table, $constraint);

        return $this;
    }

    /**
     * Get the database connection.
     *
     * @param  string|null $connection
     * @return \Illuminate\Database\Connection
     */
    protected function getConnection($connection = null)
    {
        /** @var Manager $manager */
        $manager = $this->container->make(Manager::class);
        $database = $manager->getDatabaseManager();
        $connection = $connection ?: $database->getDefaultConnection();
        return $database->connection($connection);
    }
}
