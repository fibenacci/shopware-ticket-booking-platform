<?php

declare(strict_types=1);

namespace FibBookingSystem\Api\Controller;

use FibBookingSystem\Core\Domain\Ticket\BookingTicketService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class BookingTicketScanController extends AbstractController
{
    public function __construct(private readonly BookingTicketService $ticketService)
    {
    }

    #[Route(
        path: '/api/_action/fib-booking/ticket/scan',
        name: 'api.action.fib_booking.ticket.scan',
        methods: ['POST'],
    )]
    public function scan(RequestDataBag $dataBag, Context $context): JsonResponse
    {
        unset($context);

        $scanToken = $dataBag->get('scanToken');

        if (!is_string($scanToken) || $scanToken === '') {
            throw new BadRequestHttpException('Missing required parameter "scanToken".');
        }

        return new JsonResponse([
            'valid' => $this->ticketService->markScanned($scanToken),
        ]);
    }
}
