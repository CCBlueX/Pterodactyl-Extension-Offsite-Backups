<?php

declare(strict_types=1);

namespace OffsiteBackups\Transfers;

use RuntimeException;

/** A copy failure that another attempt cannot fix. */
final class Permanent extends RuntimeException {}
