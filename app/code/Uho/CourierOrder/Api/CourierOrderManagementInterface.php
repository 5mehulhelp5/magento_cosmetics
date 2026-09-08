<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Api;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Uho\CourierOrder\Api\Data\CourierOrderAcceptResultInterface;
use Uho\CourierOrder\Api\Data\CourierOrderRequestInterface;

interface CourierOrderManagementInterface
{
    /**
     * @param CourierOrderRequestInterface $request
     * @throws LocalizedException
     * @throws CouldNotSaveException
     * @return CourierOrderAcceptResultInterface
     */
    public function submit(CourierOrderRequestInterface $request): CourierOrderAcceptResultInterface;
}
