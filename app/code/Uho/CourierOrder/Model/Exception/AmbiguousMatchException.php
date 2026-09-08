<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown by CourierOrderResolverInterface when zero or more than one candidate matches —
 * fail closed rather than guessing.
 */
class AmbiguousMatchException extends LocalizedException
{
}
