<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown when the incoming courier payload fails structural/business validation.
 * Always fail-closed: no request row is persisted, nothing is enqueued.
 */
class InvalidPayloadException extends LocalizedException
{
}
