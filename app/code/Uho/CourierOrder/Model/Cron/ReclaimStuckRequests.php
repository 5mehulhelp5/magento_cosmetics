<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Cron;

use Psr\Log\LoggerInterface;
use Uho\CourierOrder\Model\Request\Lifecycle;

use function sprintf;
class ReclaimStuckRequests
{
    public function __construct(
        private readonly Lifecycle $lifecycle,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): void
    {
        $reclaimed = $this->lifecycle->reclaimStuck();
        if ($reclaimed > 0) {
            $this->logger->warning(
                sprintf('Courier order processor: reclaimed %d stuck "processing" request(s)', $reclaimed)
            );
        }
    }
}
