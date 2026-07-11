<?php

declare(strict_types=1);

namespace App\Admin\Exceptions;

use RuntimeException;

/**
 * Lockout- and self-escalation-proofing (docs/ADMIN.md §3.2): an operator's own
 * roles can only be changed by another superadmin, or from the CLI.
 */
final class OperatorCannotModifyOwnRoles extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('You cannot modify your own roles. Ask another superadmin.');
    }
}
