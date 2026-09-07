<?php

declare(strict_types=1);

namespace Uho\OrderIntake\Test\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uho\OrderIntake\Model\GetOrderProduct;

class GetOrderProductTest extends TestCase
{
    #[DataProvider('arbitraryInputProvider')]
    public function testExecuteReturnsEmptyArrayForArbitraryInputs(string $storeCode, int $orderTotal): void
    {
        $result = (new GetOrderProduct())->execute($storeCode, $orderTotal);

        $this->assertSame([], $result);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function arbitraryInputProvider(): array
    {
        return [
            'typical store and total' => ['pr_ua', 350],
            'empty store code' => ['', 100],
            'zero total' => ['pr_ua', 0],
            'negative total' => ['pr_ua', -50],
            'large total' => ['default', 999999999],
        ];
    }
}
