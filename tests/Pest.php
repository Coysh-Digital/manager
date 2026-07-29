<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------------------------------
| Test case binding
|--------------------------------------------------------------------------------------------------
|
| Feature and Invariants tests hit a real Postgres database. The audit log depends on a trigger and
| on revoked table privileges, so testing against SQLite would be testing something other than what
| ships.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Invariants');

pest()->extend(TestCase::class)->in('Unit');
