<?php

declare(strict_types=1);

namespace Uho\OrderIntake\Model\ResourceModel\OrderIntake;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Uho\OrderIntake\Model\OrderIntake;
use Uho\OrderIntake\Model\ResourceModel\OrderIntake as OrderIntakeResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(OrderIntake::class, OrderIntakeResource::class);
    }
}
