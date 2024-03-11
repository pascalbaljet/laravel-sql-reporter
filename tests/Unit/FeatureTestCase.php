<?php

namespace Tests\Unit;

use Carbon\Carbon;
use Mockery;
use Onlime\LaravelSqlReporter\Providers\SqlReporterServiceProvider;
use Orchestra\Testbench\TestCase;

class FeatureTestCase extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        Carbon::setTestNow();
    }

    protected function getPackageProviders($app)
    {
        return [SqlReporterServiceProvider::class];
    }
}
