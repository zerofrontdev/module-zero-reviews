<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Reviews\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\Reviews\Model\Verified;

class VerifiedTest extends TestCase
{
    private AdapterInterface&MockObject $db;
    private Verified $verified;

    protected function setUp(): void
    {
        $this->db = $this->createMock(AdapterInterface::class);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->db);
        $resource->method('getTableName')->willReturnArgument(0);
        $this->verified = new Verified($resource);
    }

    public function testNoReviewsMeansNoQuery(): void
    {
        $this->db->expects(self::never())->method('select');

        self::assertSame([], $this->verified->among([]));
    }

    public function testTheVerifiedOnesComeBackByIntegerId(): void
    {
        $this->db->method('select')->willReturn($this->select());
        $this->db->method('fetchCol')->willReturn(['4', '9']);

        self::assertSame([4 => true, 9 => true], $this->verified->among([4, 5, 9]));
    }

    public function testMarkingTwiceUpdatesTheOrderInsteadOfFailing(): void
    {
        $this->db->expects(self::once())->method('insertOnDuplicate')
            ->with('zero_review_verified', ['review_id' => 4, 'order_id' => 70], ['order_id']);

        $this->verified->mark(4, 70);
    }

    public function testTheLatestOrderIsReturnedOrNullWhenThereIsNone(): void
    {
        $this->db->method('select')->willReturn($this->select());
        $this->db->method('fetchOne')->willReturnOnConsecutiveCalls('70', false);

        self::assertSame(70, $this->verified->orderWith(3, 11));
        self::assertNull($this->verified->orderWith(3, 12));
    }

    private function select(): Select&MockObject
    {
        $select = $this->createMock(Select::class);
        foreach (['from', 'join', 'where', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }

        return $select;
    }
}
