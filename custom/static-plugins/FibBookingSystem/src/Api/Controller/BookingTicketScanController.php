<?php

declare(strict_types=1);

namespace FibBookingSystem\Api\Controller;

use FibBookingSystem\Core\Domain\Security\BookingRateLimiter;
use FibBookingSystem\Core\Domain\Ticket\RotatingCodeService;
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
        private readonly RotatingCodeService $rotatingCodeService,
    ) {
    }

    #[Route(
        path: '/api/_action/fib-booking/ticket/scan',
        name: 'api.action.fib_booking.ticket.scan',
        defaults: ['_acl' => ['fib_booking.ticket_scan']],
        methods: ['POST'],
    )]
    public function scan(
        Request $request,
        RequestDataBag $dataBag,
        Context $context,
    ): JsonResponse {
        $actor = $this->resolveActor($context);
        $this->rateLimiter->ensureAccepted(BookingRateLimiter::SCAN, ($actor ?? 'anonymous') . '|' . $request->getClientIp());

        $scanToken = $dataBag->get('scanToken');

        if (!is_string($scanToken)) {
            throw new BadRequestHttpException('Missing parameter "scanToken".');
        }

        $direction = $dataBag->get('direction', ScanDirection::CHECK_IN);
        if (!is_string($direction) || !in_array($direction, ScanDirection::ALL, true)) {
            throw new BadRequestHttpException('Malformed parameter "direction" (expected "check_in" or "check_out").');
        }

        $gate = $this->resolveGate($dataBag);

        // Two wire formats in ONE field so the scanner forwards whatever the
        // QR carried, unchanged: a static 64-hex token, or the rotating
        // FIBR1:<ticketNumber>:<code> composite.
        $rotating = $this->rotatingCodeService->parseWireToken($scanToken);

        if ($rotating !== null) {
            $result = $this->scanService->scanRotating($rotating[0], $rotating[1], $context, $actor, 'admin-api', $direction, $gate);
        } elseif (preg_match('/^[0-9a-f]{64}$/', $scanToken)) {
            $result = $this->scanService->scan($scanToken, $context, $actor, 'admin-api', $direction, $gate);
        } else {
            throw new BadRequestHttpException('Malformed parameter "scanToken".');
        }

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

    /**
     * Optional entrance/lane label for the audit trail — free-form but
     * bounded, so a misbehaving client cannot stuff arbitrary blobs in.
     */
    private function resolveGate(RequestDataBag $dataBag): ?string
    {
        $gate = $dataBag->get('gate');

        if ($gate !== null && (!is_string($gate) || mb_strlen($gate) > 64)) {
            throw new BadRequestHttpException('Malformed parameter "gate" (expected a string of at most 64 characters).');
        }

        return is_string($gate) && trim($gate) !== '' ? trim($gate) : null;
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
