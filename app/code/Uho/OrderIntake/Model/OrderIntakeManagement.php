<?php

declare(strict_types=1);

namespace Uho\OrderIntake\Model;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\StoreIsInactiveException;
use Uho\OrderIntake\Api\Data\OrderIntakeInterface;
use Uho\OrderIntake\Api\OrderIntakeManagementInterface;
use Uho\OrderIntake\Model\ResourceModel\OrderIntake as OrderIntakeResource;

class OrderIntakeManagement implements OrderIntakeManagementInterface
{
    public function __construct(
        private readonly OrderIntakeFactory $orderIntakeFactory,
        private readonly OrderIntakeResource $orderIntakeResource,
        private readonly StoreRepositoryInterface $storeRepository,
    ) {
    }

    /**
     * @throws InputException
     * @throws LocalizedException
     * @throws CouldNotSaveException
     */
    public function place(
        string $storeCode,
        float $total,
        string $customerName,
        string $phone,
        string $city,
        string $trackingNumber,
        ?string $deliveryMethod = null,
    ): int {
        $this->validateRequiredFields($storeCode, $total, $customerName, $phone, $city, $trackingNumber);
        $this->assertStoreCodeResolves($storeCode);
        $this->assertTrackingNumberIsUnique($trackingNumber);

        $orderIntake = $this->orderIntakeFactory->create();
        $orderIntake->setStoreCode($storeCode);
        $orderIntake->setTotal($total);
        $orderIntake->setCustomerName($customerName);
        $orderIntake->setPhone($phone);
        $orderIntake->setCity($city);
        $orderIntake->setDeliveryMethod($deliveryMethod);
        $orderIntake->setTrackingNumber($trackingNumber);
        $orderIntake->setStatus(OrderIntakeInterface::STATUS_PENDING);

        $this->orderIntakeResource->save($orderIntake);

        return (int) $orderIntake->getEntityId();
    }

    /**
     * @throws InputException
     */
    private function validateRequiredFields(
        string $storeCode,
        float $total,
        string $customerName,
        string $phone,
        string $city,
        string $trackingNumber,
    ): void {
        $requiredFields = [
            'storeCode' => $storeCode,
            'customerName' => $customerName,
            'phone' => $phone,
            'city' => $city,
            'trackingNumber' => $trackingNumber,
        ];

        $inputException = new InputException();

        foreach ($requiredFields as $field => $value) {
            if (trim($value) === '') {
                $inputException->addError(__('"%1" is required.', $field));
            }
        }

        if ($total <= 0.0) {
            $inputException->addError(__('"total" must be greater than zero.'));
        }

        if ($inputException->wasErrorAdded()) {
            throw $inputException;
        }
    }

    /**
     * @throws LocalizedException
     */
    private function assertStoreCodeResolves(string $storeCode): void
    {
        try {
            $this->storeRepository->getActiveStoreByCode($storeCode);
        } catch (NoSuchEntityException | StoreIsInactiveException) {
            throw new LocalizedException(__('Store "%1" does not exist or is inactive.', $storeCode));
        }
    }

    /**
     * @throws LocalizedException
     */
    private function assertTrackingNumberIsUnique(string $trackingNumber): void
    {
        if ($this->orderIntakeResource->trackingNumberExists($trackingNumber)) {
            throw new LocalizedException(
                __('An order intake with tracking number "%1" already exists.', $trackingNumber)
            );
        }
    }
}
