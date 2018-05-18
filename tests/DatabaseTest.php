<?php

namespace Tests;

use Goez\DbUnit\RefreshDatabase;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit_Framework_TestCase as TestCase;
use Tests\fixture\User;

class DatabaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @throws \Exception
     */
    protected function setUp()
    {
        $this->refreshDatabase([
            'database_config' => require __DIR__ . '/config/database.php',
            'migration_path' => __DIR__ . '/fixture/migrations',
            'factory_path' => __DIR__ . '/fixture/factories',
        ]);
    }

    protected function tearDown()
    {
        $this->rollbackDatabase();
    }

    /**
     * @test
     */
    public function it_should_migrate_tables()
    {
        $data = Manager::connection()->table('users')->get();
        $this->assertEmpty($data);
    }

    /**
     * @test
     */
    public function it_should_create_multiple_records()
    {
        $users = factory(User::class, 5)->make();
        $this->assertInstanceOf(Collection::class, $users);
        $this->assertCount(5, $users);
    }

    /**
     * @test
     */
    public function it_should_create_a_record()
    {
        $expected = [
            'name' => 'John Yu',
            'email' => 'johnyu@example.com',
        ];
        factory(User::class)->create($expected)->save();
        $this->assertDatabaseHas('users', $expected);
    }
}
