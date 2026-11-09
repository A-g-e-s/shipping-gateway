<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Tests\Common\Tracking;

use Ages\ShippingGateway\Common\Tracking\DeliveredDate;
use Ages\ShippingGateway\Common\Tracking\ParcelStatus;
use Ages\ShippingGateway\Common\Tracking\ParcelTracking;
use PHPUnit\Framework\TestCase;

final class ParcelTrackingTest extends TestCase
{
    private static function delivered(?string $date): ParcelStatus
    {
        return new ParcelStatus('D', 'Doručeno', $date !== null ? new \DateTimeImmutable($date) : null, delivered: true);
    }

    public function testLatestPolicyKeepsLatestDeliveredDate(): void
    {
        $tracking = new ParcelTracking('1');
        $tracking->addStatus(new ParcelStatus('X', 'Na cestě', new \DateTimeImmutable('2024-01-01')));
        self::assertFalse($tracking->delivered);
        self::assertNull($tracking->deliveredDate);

        $tracking->addStatus(self::delivered(null));
        $tracking->addStatus(self::delivered('2024-01-03'));
        $tracking->addStatus(self::delivered('2024-01-02'));
        $tracking->addStatus(self::delivered(null));

        self::assertTrue($tracking->delivered);
        self::assertEquals(new \DateTimeImmutable('2024-01-03'), $tracking->deliveredDate);
        self::assertFalse($tracking->damaged);
        self::assertCount(5, $tracking->parcelStatuses);
    }

    public function testLastAddedPolicyTakesDateOfLastDeliveredStatus(): void
    {
        $tracking = new ParcelTracking('1', deliveredDatePolicy: DeliveredDate::LastAdded);
        $tracking->markDelivered(new \DateTimeImmutable('2024-01-05'));
        self::assertEquals(new \DateTimeImmutable('2024-01-05'), $tracking->deliveredDate);

        $tracking->addStatus(self::delivered('2024-01-03'));
        $tracking->addStatus(self::delivered('2024-01-02'));
        self::assertEquals(new \DateTimeImmutable('2024-01-02'), $tracking->deliveredDate);

        $tracking->addStatus(new ParcelStatus('P', 'Poškozeno', damaged: true));
        self::assertTrue($tracking->damaged);
    }

    public function testToArrayHash(): void
    {
        $tracking = new ParcelTracking('1', 'CZ', 1.5, '11000', 'OBJ1');
        $tracking->addStatus(new ParcelStatus('05', 'Doručeno', new \DateTimeImmutable('2024-01-01'), 'Praha', 'CZ01', 'info', 'Doručeno', true));

        $hash = $tracking->toArrayHash();

        self::assertSame(['1', 'CZ', '11000', 'OBJ1', 1.5, true, false], [
            $hash['parcelNumber'], $hash['deliveryCountryCode'], $hash['deliveryZipCode'], $hash['clientReference'],
            $hash['weight'], $hash['delivered'], $hash['damaged'],
        ]);
        self::assertEquals(new \DateTimeImmutable('2024-01-01'), $hash['deliveredDate']);
        $statuses = $hash['parcelStatuses'];
        self::assertInstanceOf(\Nette\Utils\ArrayHash::class, $statuses);
        self::assertCount(1, $statuses);
        self::assertEquals($tracking->parcelStatuses[0]->toArrayHash(), $statuses[0]);
        self::assertEquals($statuses, $hash['parcelStatus']);
    }
}
