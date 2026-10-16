<?php

declare(strict_types=1);

namespace Phibble\Sql;

use RuntimeException;

/**
 * An expression has no faithful SQL translation for this dialect. SqlTable
 * catches it and continues in memory after collect().
 */
final class Untranslatable extends RuntimeException {}
