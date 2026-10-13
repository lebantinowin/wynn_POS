<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
        $this->actingAs(User::factory()->make(['id' => User::OWNER_ID, 'name' => 'Test owner']));
    }
}
