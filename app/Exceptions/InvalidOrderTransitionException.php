<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;
use RuntimeException;

final class InvalidOrderTransitionException extends RuntimeException
{
    public function __construct(
        public readonly OrderStatus $from,
        public readonly OrderStatus $to,
    ) {
        parent::__construct(sprintf(
            'An order cannot change from "%s" to "%s".',
            $from->label(),
            $to->label(),
        ));
    }
}
