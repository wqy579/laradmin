<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // Laravel 11 已内置应用引导（读取 bootstrap/app.php），无需 CreatesApplication。
}
