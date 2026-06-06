<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Storefront;

use FibBookingSystem\Storefront\Controller\BookingApiController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\JsonResponse;

class BookingApiControllerCacheHeaderTest extends TestCase
{
    public function testNoStoreJsonSetsNoStoreHeaders(): void
    {
        $controller = (new ReflectionClass(BookingApiController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(BookingApiController::class, 'noStoreJson');
        $method->setAccessible(true);

        $response = $method->invoke($controller, ['success' => true], JsonResponse::HTTP_ACCEPTED);

        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertSame(JsonResponse::HTTP_ACCEPTED, $response->getStatusCode());
        static::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        static::assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        static::assertStringContainsString('max-age=0', (string) $response->headers->get('Cache-Control'));
        static::assertSame('no-cache', $response->headers->get('Pragma'));
    }
}
