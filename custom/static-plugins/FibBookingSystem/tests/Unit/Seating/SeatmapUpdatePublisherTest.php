<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Seating;

use FibBookingSystem\Core\Domain\Seating\SeatmapUpdatePublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * The publisher is the AFTER-COMMIT boundary for live seat-map pushes:
 * deferred topics deduplicate, flushing empties the queue, and a dead hub
 * must never break a booking flow.
 */
class SeatmapUpdatePublisherTest extends TestCase
{
    public function testFlushPublishesEachDeferredSlotOnce(): void
    {
        $published = [];
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published[] = $update->getTopics()[0];

            return 'id';
        });

        $publisher = new SeatmapUpdatePublisher($hub, new NullLogger());
        $publisher->defer('AAAA0000000000000000000000000001');
        $publisher->defer('aaaa0000000000000000000000000001'); // same slot, different casing
        $publisher->defer('aaaa0000000000000000000000000002');
        $publisher->flush();

        static::assertSame([
            SeatmapUpdatePublisher::TOPIC_PREFIX . 'aaaa0000000000000000000000000001',
            SeatmapUpdatePublisher::TOPIC_PREFIX . 'aaaa0000000000000000000000000002',
        ], $published);
    }

    public function testFlushEmptiesTheQueue(): void
    {
        $calls = 0;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function () use (&$calls): string {
            ++$calls;

            return 'id';
        });

        $publisher = new SeatmapUpdatePublisher($hub, new NullLogger());
        $publisher->defer('aaaa0000000000000000000000000001');
        $publisher->flush();
        $publisher->flush();

        static::assertSame(1, $calls, 'a second flush must not re-publish');
    }

    public function testHubFailuresAreSwallowed(): void
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willThrowException(new RuntimeException('hub down'));

        $publisher = new SeatmapUpdatePublisher($hub, new NullLogger());
        $publisher->publish('aaaa0000000000000000000000000001');

        // Reaching this line IS the assertion: booking flows survive a dead hub.
        $this->expectNotToPerformAssertions();
    }
}
