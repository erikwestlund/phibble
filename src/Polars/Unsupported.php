<?php

declare(strict_types=1);

namespace Phibble\Polars;

use RuntimeException;

/**
 * Thrown by Translator for an expression Polars cannot run with the same
 * result as phibble's evaluator. PolarsTable runs that verb on a Frame.
 *
 * @internal
 */
final class Unsupported extends RuntimeException {}
