<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown by ReconciliationPlannerInterface::plan() when no combination of eligible products
 * reconciles the payload total within the configured ceiling — fail closed, no order created.
 */
class ReconciliationFailedException extends LocalizedException
{
}
