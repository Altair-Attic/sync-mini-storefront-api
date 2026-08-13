<?php

declare(strict_types=1);

namespace ProjectSync\Exceptions;

use RuntimeException;

final class BusinessProfileNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Business profile was not found.');
    }
}
