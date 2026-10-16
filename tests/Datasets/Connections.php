<?php

declare(strict_types=1);

// SQLite always; PostgreSQL and MySQL when PHIBBLE_TEST_PGSQL / PHIBBLE_TEST_MYSQL are set.
dataset('connections', fn () => array_map(fn ($c) => [$c], sqlConnections()));
