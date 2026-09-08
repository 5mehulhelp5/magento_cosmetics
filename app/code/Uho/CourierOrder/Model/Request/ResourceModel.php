<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Request;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ResourceModel extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('uho_courier_order_request', Record::REQUEST_ID);
    }
}
