<?php

declare(strict_types=1);

namespace OffsiteBackups\Transfers;

use RuntimeException;

/** Another write or a prune holds the repository. */
final class RepositoryBusy extends RuntimeException {}
