<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Statistics;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Read model for operator statistics — ticket purchases and visit/dwell-time
 * figures aggregated from reservations, orders and the scan audit log.
 *
 * DBAL by design (documented DAL exception, see ARCHITECTURE_PLAN.md): these
 * are set-based aggregations over several tables (GROUP BY day/product,
 * check-in/check-out pairing). Loading the underlying entities through the
 * DAL to aggregate in PHP would be both slower and more code. Nothing here
 * writes — the service is strictly read-only.
 */
class BookingStatisticsService
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{
     *     range: array{from: string, to: string},
     *     purchases: array{total: int, quantity: int, revenue: float, byDay: list<array{date: string, count: int, quantity: int, revenue: float}>, byProduct: list<array{label: string, count: int, quantity: int, revenue: float}>},
     *     attendance: array{checkIns: int, checkOuts: int, currentlyInside: int, dwell: array{sessions: int, averageMinutes: float|null, medianMinutes: float|null}}
     * }
     */
    public function overview(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        // Millisecond precision: created_at is DATETIME(3) — truncating the
        // upper bound to whole seconds would silently drop events that
        // happened within the current second.
        $range = [
            'from' => $from->format('Y-m-d H:i:s.v'),
            'to' => $to->format('Y-m-d H:i:s.v'),
        ];

        return [
            'range' => $range,
            'purchases' => $this->purchases($range),
            'attendance' => $this->attendance($range),
        ];
    }

    /**
     * Confirmed reservations joined against their order line items: count,
     * booked quantity and gross revenue, broken down by day and by product.
     * Reservations without an order (e.g. manually issued) count with zero
     * revenue.
     *
     * @param array{from: string, to: string} $range
     *
     * @return array{total: int, quantity: int, revenue: float, byDay: list<array{date: string, count: int, quantity: int, revenue: float}>, byProduct: list<array{label: string, count: int, quantity: int, revenue: float}>}
     */
    private function purchases(array $range): array
    {
        /** @var list<array{date: string, count: int|string, quantity: int|string|null, revenue: float|string|null}> $byDayRows */
        $byDayRows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT DATE(reservation.created_at) AS date,
                COUNT(*) AS count,
                SUM(reservation.quantity) AS quantity,
                SUM(COALESCE(line_item.total_price, 0)) AS revenue
                FROM fib_booking_reservation reservation
                LEFT JOIN order_line_item line_item
                ON line_item.id = reservation.order_line_item_id
                AND line_item.version_id = reservation.order_line_item_version_id
                WHERE reservation.status = 'confirmed'
                AND reservation.created_at BETWEEN :rangeFrom AND :rangeTo
                GROUP BY DATE(reservation.created_at)
                ORDER BY date
            SQL,
            ['rangeFrom' => $range['from'], 'rangeTo' => $range['to']],
        );

        /** @var list<array{label: string|null, count: int|string, quantity: int|string|null, revenue: float|string|null}> $byProductRows */
        $byProductRows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT COALESCE(line_item.label, '(no order)') AS label,
                COUNT(*) AS count,
                SUM(reservation.quantity) AS quantity,
                SUM(COALESCE(line_item.total_price, 0)) AS revenue
                FROM fib_booking_reservation reservation
                LEFT JOIN order_line_item line_item
                ON line_item.id = reservation.order_line_item_id
                AND line_item.version_id = reservation.order_line_item_version_id
                WHERE reservation.status = 'confirmed'
                AND reservation.created_at BETWEEN :rangeFrom AND :rangeTo
                GROUP BY line_item.product_id, line_item.label
                ORDER BY revenue DESC, count DESC
            SQL,
            ['rangeFrom' => $range['from'], 'rangeTo' => $range['to']],
        );

        $byDay = array_map(static fn (array $row): array => [
            'date' => $row['date'],
            'count' => (int) $row['count'],
            'quantity' => (int) ($row['quantity'] ?? 0),
            'revenue' => round((float) ($row['revenue'] ?? 0), 2),
        ], $byDayRows);

        $byProduct = array_map(static fn (array $row): array => [
            'label' => (string) ($row['label'] ?? '(no order)'),
            'count' => (int) $row['count'],
            'quantity' => (int) ($row['quantity'] ?? 0),
            'revenue' => round((float) ($row['revenue'] ?? 0), 2),
        ], $byProductRows);

        return [
            'total' => array_sum(array_column($byDay, 'count')),
            'quantity' => array_sum(array_column($byDay, 'quantity')),
            'revenue' => round(array_sum(array_column($byDay, 'revenue')), 2),
            'byDay' => $byDay,
            'byProduct' => $byProduct,
        ];
    }

    /**
     * Visit figures from the scan audit log. A dwell session is a successful
     * check-out paired with the latest preceding successful check-in of the
     * same ticket — re-entry therefore produces multiple sessions per ticket,
     * and the dwell figures sum them naturally.
     *
     * @param array{from: string, to: string} $range
     *
     * @return array{checkIns: int, checkOuts: int, currentlyInside: int, dwell: array{sessions: int, averageMinutes: float|null, medianMinutes: float|null}}
     */
    private function attendance(array $range): array
    {
        $params = ['rangeFrom' => $range['from'], 'rangeTo' => $range['to']];

        $checkIns = $this->fetchCount(
            <<<'SQL'
                SELECT COUNT(*) FROM fib_booking_scan_log
                WHERE direction = 'check_in' AND verdict = 'valid'
                AND created_at BETWEEN :rangeFrom AND :rangeTo
            SQL,
            $params,
        );

        $checkOuts = $this->fetchCount(
            <<<'SQL'
                SELECT COUNT(*) FROM fib_booking_scan_log
                WHERE direction = 'check_out' AND verdict = 'checked_out'
                AND created_at BETWEEN :rangeFrom AND :rangeTo
            SQL,
            $params,
        );

        // Status 'scanned' means "checked in and not (yet) checked out".
        $currentlyInside = $this->fetchCount(
            <<<'SQL'
                SELECT COUNT(*) FROM fib_booking_ticket WHERE status = 'scanned'
            SQL,
        );

        // Dwell sessions are aggregated in PHP (median needs the full list);
        // the LIMIT caps a runaway range from loading unbounded rows.
        /** @var list<int|string|null> $dwellSeconds */
        $dwellSeconds = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT TIMESTAMPDIFF(SECOND, (
                    SELECT MAX(in_log.created_at) FROM fib_booking_scan_log in_log
                    WHERE in_log.ticket_id = out_log.ticket_id
                    AND in_log.direction = 'check_in' AND in_log.verdict = 'valid'
                    AND in_log.created_at <= out_log.created_at
                ), out_log.created_at) AS dwell_seconds
                FROM fib_booking_scan_log out_log
                WHERE out_log.direction = 'check_out' AND out_log.verdict = 'checked_out'
                AND out_log.ticket_id IS NOT NULL
                AND out_log.created_at BETWEEN :rangeFrom AND :rangeTo
                LIMIT 10000
            SQL,
            $params,
        );

        return [
            'checkIns' => $checkIns,
            'checkOuts' => $checkOuts,
            'currentlyInside' => $currentlyInside,
            'dwell' => $this->summarizeDwell($dwellSeconds),
        ];
    }

    /**
     * @param array<string, string> $params
     */
    private function fetchCount(
        string $sql,
        array $params = [],
    ): int {
        $value = $this->connection->fetchOne($sql, $params);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param list<int|string|null> $dwellSeconds
     *
     * @return array{sessions: int, averageMinutes: float|null, medianMinutes: float|null}
     */
    private function summarizeDwell(array $dwellSeconds): array
    {
        $minutes = [];
        foreach ($dwellSeconds as $seconds) {
            if ($seconds === null) {
                continue; // check-out without a logged check-in (pre-migration data)
            }
            $minutes[] = ((int) $seconds) / 60;
        }

        if ($minutes === []) {
            return ['sessions' => 0, 'averageMinutes' => null, 'medianMinutes' => null];
        }

        sort($minutes);
        $count = count($minutes);
        $middle = intdiv($count, 2);
        $median = $count % 2 === 1
            ? $minutes[$middle]
            : ($minutes[$middle - 1] + $minutes[$middle]) / 2;

        return [
            'sessions' => $count,
            'averageMinutes' => round(array_sum($minutes) / $count, 1),
            'medianMinutes' => round($median, 1),
        ];
    }
}
