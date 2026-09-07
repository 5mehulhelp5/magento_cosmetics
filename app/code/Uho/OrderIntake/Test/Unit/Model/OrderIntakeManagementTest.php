<?php

declare(strict_types=1);

namespace Uho\OrderIntake\Test\Unit\Model;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\StoreIsInactiveException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Uho\OrderIntake\Api\Data\OrderIntakeInterface;
use Uho\OrderIntake\Model\OrderIntake;
use Uho\OrderIntake\Model\OrderIntakeFactory;
use Uho\OrderIntake\Model\OrderIntakeManagement;
use Uho\OrderIntake\Model\ResourceModel\OrderIntake as OrderIntakeResource;

class OrderIntakeManagementTest extends TestCase
{
    private const string STORE_CODE = 'pr_ua';
    private const float TOTAL = 350.0;
    private const string CUSTOMER_NAME = 'Іванова Марія';
    private const string PHONE = '+380671234567';
    private const string CITY = 'Київ';
    private const string TRACKING_NUMBER = '20450123456789';

    private OrderIntakeFactory&MockObject $orderIntakeFactory;
    private OrderIntakeResource&MockObject $orderIntakeResource;
    private StoreRepositoryInterface&MockObject $storeRepository;
    private OrderIntakeManagement $management;

    protected function setUp(): void
    {
        $this->orderIntakeFactory = $this->createMock(OrderIntakeFactory::class);
        $this->orderIntakeResource = $this->createMock(OrderIntakeResource::class);
        $this->storeRepository = $this->createMock(StoreRepositoryInterface::class);

        $this->management = new OrderIntakeManagement(
            $this->orderIntakeFactory,
            $this->orderIntakeResource,
            $this->storeRepository,
        );
    }

    public function testPlaceThrowsAnAggregatedInputExceptionForBlankRequiredFields(): void
    {
        $this->storeRepository->expects($this->never())->method('getActiveStoreByCode');
        $this->orderIntakeResource->expects($this->never())->method('save');

        try {
            $this->management->place(self::STORE_CODE, self::TOTAL, ' ', '', self::CITY, self::TRACKING_NUMBER);
            $this->fail('Expected InputException was not thrown.');
        } catch (InputException $exception) {
            // customerName and phone are both blank -> two aggregated errors.
            $this->assertCount(2, $exception->getErrors());
        }
    }

    public function testPlaceThrowsAnInputExceptionWhenTotalIsNotPositive(): void
    {
        $this->expectException(InputException::class);

        $this->management->place(
            self::STORE_CODE,
            0.0,
            self::CUSTOMER_NAME,
            self::PHONE,
            self::CITY,
            self::TRACKING_NUMBER,
        );
    }

    public function testPlaceRejectsAnUnknownStoreCode(): void
    {
        $this->storeRepository->method('getActiveStoreByCode')
            ->with(self::STORE_CODE)
            ->willThrowException(new NoSuchEntityException(__('Store does not exist')));
        $this->orderIntakeResource->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);

        $this->management->place(
            self::STORE_CODE,
            self::TOTAL,
            self::CUSTOMER_NAME,
            self::PHONE,
            self::CITY,
            self::TRACKING_NUMBER,
        );
    }

    public function testPlaceRejectsAnInactiveStore(): void
    {
        $this->storeRepository->method('getActiveStoreByCode')
            ->willThrowException(new StoreIsInactiveException(__('Store is inactive')));
        $this->orderIntakeResource->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);

        $this->management->place(
            self::STORE_CODE,
            self::TOTAL,
            self::CUSTOMER_NAME,
            self::PHONE,
            self::CITY,
            self::TRACKING_NUMBER,
        );
    }

    public function testPlaceRejectsADuplicateTrackingNumberAndSavesNothing(): void
    {
        $this->storeRepository->method('getActiveStoreByCode')
            ->willReturn($this->createMock(StoreInterface::class));
        $this->orderIntakeResource->method('trackingNumberExists')
            ->with(self::TRACKING_NUMBER)
            ->willReturn(true);
        $this->orderIntakeResource->expects($this->never())->method('save');
        $this->orderIntakeFactory->expects($this->never())->method('create');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'An order intake with tracking number "20450123456789" already exists.'
        );

        $this->management->place(
            self::STORE_CODE,
            self::TOTAL,
            self::CUSTOMER_NAME,
            self::PHONE,
            self::CITY,
            self::TRACKING_NUMBER,
        );
    }

    public function testPlaceSavesAPendingRowOnTheHappyPathAndReturnsItsId(): void
    {
        $this->storeRepository->method('getActiveStoreByCode')
            ->willReturn($this->createMock(StoreInterface::class));
        $this->orderIntakeResource->method('trackingNumberExists')->willReturn(false);

        $orderIntake = $this->createMock(OrderIntake::class);
        $orderIntake->expects($this->once())->method('setStoreCode')->with(self::STORE_CODE);
        $orderIntake->expects($this->once())->method('setTotal')->with(self::TOTAL);
        $orderIntake->expects($this->once())->method('setCustomerName')->with(self::CUSTOMER_NAME);
        $orderIntake->expects($this->once())->method('setPhone')->with(self::PHONE);
        $orderIntake->expects($this->once())->method('setCity')->with(self::CITY);
        $orderIntake->expects($this->once())->method('setDeliveryMethod')->with('самовивіз');
        $orderIntake->expects($this->once())->method('setTrackingNumber')->with(self::TRACKING_NUMBER);
        $orderIntake->expects($this->once())->method('setStatus')->with(OrderIntakeInterface::STATUS_PENDING);
        $orderIntake->method('getEntityId')->willReturn(42);

        $this->orderIntakeFactory->method('create')->willReturn($orderIntake);
        $this->orderIntakeResource->expects($this->once())->method('save')->with($orderIntake);

        $result = $this->management->place(
            self::STORE_CODE,
            self::TOTAL,
            self::CUSTOMER_NAME,
            self::PHONE,
            self::CITY,
            self::TRACKING_NUMBER,
            'самовивіз',
        );

        $this->assertSame(42, $result);
    }
}
