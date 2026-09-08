<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Exception;

/**
 * Transient processing failure (DB deadlock, temporary lock wait, transient inventory reservation
 * conflict). Only this exception type triggers a retry; all other exceptions are terminal (failed).
 */
class TransientProcessingException extends \RuntimeException
{
}
