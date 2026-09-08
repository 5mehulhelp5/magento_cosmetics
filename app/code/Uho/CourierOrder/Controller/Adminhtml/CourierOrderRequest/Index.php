<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Controller\Adminhtml\CourierOrderRequest;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page as BackendPage;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Uho_CourierOrderProcessor::grid';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
    ) {
        parent::__construct($context);
    }

    public function execute(): BackendPage
    {
        $resultPage = $this->resultPageFactory->create();
        if (!$resultPage instanceof BackendPage) {
            throw new \LogicException('Expected backend result page instance.');
        }

        $resultPage->setActiveMenu('Uho_CourierOrder::courier_order_request');
        $resultPage->getConfig()->getTitle()->prepend((string) __('Courier Order Requests'));

        return $resultPage;
    }
}
