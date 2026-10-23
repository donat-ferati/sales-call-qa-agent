<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        if ($app['config']['database.default'] !== 'mysql' || $app['config']['database.connections.mysql.database'] !== 'sales_call_qa_agent_testing')
        {
            throw new RuntimeException('Tests require the isolated sales_call_qa_agent_testing MySQL database.');
        }

        return $app;
    }
}
