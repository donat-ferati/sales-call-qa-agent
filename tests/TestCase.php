<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['db']->connection();

        if ($app->environment() !== 'testing' || $app['config']['database.default'] !== 'mysql'
            || $connection->getDriverName() !== 'mysql' || $connection->getDatabaseName() !== 'sales_call_qa_agent_testing'
            || !in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true))
        {
            throw new RuntimeException('Tests require the local isolated sales_call_qa_agent_testing MySQL database.');
        }

        return $app;
    }
}
