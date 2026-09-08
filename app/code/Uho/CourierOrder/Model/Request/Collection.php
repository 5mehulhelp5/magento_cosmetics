<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Request;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(Record::class, ResourceModel::class);
    }
}
