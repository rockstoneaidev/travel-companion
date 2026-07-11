<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests get the application TestCase and a transactional database.
| Unit tests get neither — if a "unit" test needs the database, it belongs in
| Feature. See docs/conventions/11-testing.md.
|
| The database is real PostgreSQL + PostGIS + pgvector, not SQLite: the geo and
| vector columns this product is built on cannot exist on SQLite.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| Project-specific expectations. Keep them few and meaningful.
|
*/

expect()->extend('toBeIso8601', function () {
    expect($this->value)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?([+-]\d{2}:\d{2}|Z)$/');

    return $this;
});
