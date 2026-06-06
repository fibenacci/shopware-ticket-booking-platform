<?php

declare(strict_types=1);

namespace FibBookingSystem\Api\Controller;

use DateTimeImmutable;
use FibBookingSystem\Core\Domain\Security\BookingRateLimiter;
use FibBookingSystem\Core\Domain\Statistics\BookingStatisticsService;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Operator statistics behind a dedicated ACL privilege — separate from
 * fib_booking.ticket_scan so a scan-only role does not automatically see
 * revenue figures.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class BookingStatisticsController extends AbstractController
{
    private const DEFAULT_RANGE_DAYS = 30;

    public function __construct(
        private readonly BookingStatisticsService $statisticsService,
        private readonly BookingRateLimiter $rateLimiter,
    ) {
    }

    #[Route(
        path: '/api/_action/fib-booking/statistics',
        name: 'api.action.fib_booking.statistics',
        defaults: ['_acl' => ['fib_booking.statistics']],
        methods: ['GET'],
    )]
    public function overview(Request $request, Context $context): JsonResponse
    {
        $actor = $this->resolveActor($context);
        $this->rateLimiter->ensureAccepted(BookingRateLimiter::READ, ($actor ?? 'anonymous') . '|' . $request->getClientIp());

        $to = $this->parseDate((string) $request->query->get('to', ''), new DateTimeImmutable());
        $from = $this->parseDate(
            (string) $request->query->get('from', ''),
            $to->modify(sprintf('-%d days', self::DEFAULT_RANGE_DAYS)),
        );

        if ($from > $to) {
            throw new BadRequestHttpException('Parameter "from" must not be after "to".');
        }

        $response = new JsonResponse($this->statisticsService->overview($from, $to));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }

    private function parseDate(string $value, DateTimeImmutable $fallback): DateTimeImmutable
    {
        if ($value === '') {
            return $fallback;
        }

        // Accept plain dates (YYYY-MM-DD) and full ISO timestamps.
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d|', $value)
            ?: DateTimeImmutable::createFromFormat(\DATE_ATOM, $value);

        if ($parsed === false) {
            throw new BadRequestHttpException('Malformed date parameter (expected YYYY-MM-DD or ISO 8601).');
        }

        return $parsed;
    }

    private function resolveActor(Context $context): ?string
    {
        $source = $context->getSource();

        if ($source instanceof AdminApiSource) {
            return $source->getUserId() ?? ($source->getIntegrationId() !== null ? 'integration:' . $source->getIntegrationId() : null);
        }

        return null;
    }
}
