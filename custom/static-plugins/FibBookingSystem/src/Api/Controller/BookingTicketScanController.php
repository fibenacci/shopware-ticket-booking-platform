<?php

declare(strict_types=1);

namespace FibBookingSystem\Api\Controller;

use FibBookingSystem\Core\Domain\Security\BookingRateLimiter;
use FibBookingSystem\Core\Domain\Ticket\ScanDirection;
use FibBookingSystem\Core\Domain\Ticket\TicketScanService;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class BookingTicketScanController extends AbstractController
{
    public function __construct(
        private readonly TicketScanService $scanService,
        private readonly BookingRateLimiter $rateLimiter,
    ) {
    }

    #[Route(
        path: '/api/_action/fib-booking/ticket/scan',
        name: 'api.action.fib_booking.ticket.scan',
        defaults: ['_acl' => ['fib_booking.ticket_scan']],
        methods: ['POST'],
    )]
    public function scan(Request $request, RequestDataBag $dataBag, Context $context): JsonResponse
    {
        $actor = $this->resolveActor($context);
        $this->rateLimiter->ensureAccepted(BookingRateLimiter::SCAN, ($actor ?? 'anonymous') . '|' . $request->getClientIp());

        $scanToken = $dataBag->get('scanToken');

        // Tokens are produced by bin2hex(random_bytes(32)) — anything else is
        // rejected before touching the database.
        if (!is_string($scanToken) || !preg_match('/^[0-9a-f]{64}$/', $scanToken)) {
            throw new BadRequestHttpException('Missing or malformed parameter "scanToken".');
        }

        $direction = $dataBag->get('direction', ScanDirection::CHECK_IN);
        if (!is_string($direction) || !in_array($direction, ScanDirection::ALL, true)) {
            throw new BadRequestHttpException('Malformed parameter "direction" (expected "check_in" or "check_out").');
        }

        $result = $this->scanService->scan($scanToken, $context, $actor, 'admin-api', $direction);

        $response = new JsonResponse($result->toArray());
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }

    /**
     * Feature flags the scanner app needs to render its UI (e.g. whether to
     * offer the check-out mode toggle). Same ACL as scanning itself.
     */
    #[Route(
        path: '/api/_action/fib-booking/scanner/config',
        name: 'api.action.fib_booking.scanner.config',
        defaults: ['_acl' => ['fib_booking.ticket_scan']],
        methods: ['GET'],
    )]
    public function scannerConfig(): JsonResponse
    {
        $response = new JsonResponse([
            'checkOutEnabled' => $this->scanService->isCheckOutEnabled(),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
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
