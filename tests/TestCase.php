<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-14 10:00:00');
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }
}
