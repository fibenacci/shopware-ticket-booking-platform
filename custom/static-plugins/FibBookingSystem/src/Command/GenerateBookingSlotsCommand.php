<?php

declare(strict_types=1);

namespace FibBookingSystem\Command;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Operator tooling: bulk-generate bookable dates ("Termine") for a resource.
 *
 *   bin/console fib-booking:slots:generate --resource=main_stage \
 *       --from=2026-07-01 --to=2026-07-31 --times=18:00,20:30 \
 *       --duration=120 --capacity=10 --weekdays=Fri,Sat
 *
 * Slots are upserted on (resource, starts_at) — re-running with adjusted
 * capacity updates existing slots instead of duplicating them. Individual
 * slots can be managed via the Admin API (/api/fib-booking-slot).
 */
#[AsCommand(
    name: 'fib-booking:slots:generate',
    description: 'Generates bookable slots (Termine) for a booking resource over a date range.',
)]
class GenerateBookingSlotsCommand extends Command
{
    private const WEEKDAYS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('resource', null, InputOption::VALUE_REQUIRED, 'Resource technical name or hex id')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'First day (YYYY-MM-DD)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Last day inclusive (YYYY-MM-DD)')
            ->addOption('times', null, InputOption::VALUE_REQUIRED, 'Comma-separated start times (HH:MM)', '18:00')
            ->addOption('duration', null, InputOption::VALUE_REQUIRED, 'Slot duration in minutes', '120')
            ->addOption('capacity', null, InputOption::VALUE_REQUIRED, 'Capacity per slot', '10')
            ->addOption('weekdays', null, InputOption::VALUE_REQUIRED, 'Comma-separated weekdays (Mon..Sun); default: all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $resourceBytes = $this->resolveResource((string) $input->getOption('resource'));
        if ($resourceBytes === null) {
            $io->error('Resource not found. Pass --resource=<technical_name|hex id>.');

            return Command::FAILURE;
        }

        $from = new DateTimeImmutable((string) $input->getOption('from'));
        $to = new DateTimeImmutable((string) $input->getOption('to'));
        $duration = max(5, (int) $input->getOption('duration'));
        $capacity = max(1, (int) $input->getOption('capacity'));
        $times = $this->parseTimes((string) $input->getOption('times'));
        $weekdays = $this->parseWeekdays($input->getOption('weekdays'));

        if ($times === []) {
            $io->error('No valid --times given (expected HH:MM, comma-separated).');

            return Command::FAILURE;
        }

        $created = 0;
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s.v');

        for ($day = $from; $day <= $to; $day = $day->add(new DateInterval('P1D'))) {
            if ($weekdays !== null && !in_array((int) $day->format('N'), $weekdays, true)) {
                continue;
            }

            foreach ($times as [$hour, $minute]) {
                $startsAt = $day->setTime($hour, $minute);
                $endsAt = $startsAt->add(new DateInterval(sprintf('PT%dM', $duration)));

                $this->connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO fib_booking_slot (id, resource_id, starts_at, ends_at, capacity, active, created_at)
                        VALUES (:id, :resourceId, :startsAt, :endsAt, :capacity, 1, :createdAt)
                        ON DUPLICATE KEY UPDATE
                            ends_at = VALUES(ends_at),
                            capacity = VALUES(capacity),
                            active = 1,
                            updated_at = VALUES(created_at)
                        SQL,
                    [
                        'id' => Uuid::randomBytes(),
                        'resourceId' => $resourceBytes,
                        'startsAt' => $startsAt->format('Y-m-d H:i:s.v'),
                        'endsAt' => $endsAt->format('Y-m-d H:i:s.v'),
                        'capacity' => $capacity,
                        'createdAt' => $now,
                    ],
                );
                ++$created;
            }
        }

        $io->success(sprintf('%d slot(s) upserted (%s – %s, capacity %d).', $created, $from->format('Y-m-d'), $to->format('Y-m-d'), $capacity));

        return Command::SUCCESS;
    }

    private function resolveResource(string $identifier): ?string
    {
        if ($identifier === '') {
            return null;
        }

        if (preg_match('/^[0-9a-f]{32}$/i', $identifier)) {
            $found = $this->connection->fetchOne(
                <<<'SQL'
                    SELECT id FROM fib_booking_resource WHERE id = :id
                    SQL,
                ['id' => Uuid::fromHexToBytes(strtolower($identifier))],
            );
        } else {
            $found = $this->connection->fetchOne(
                <<<'SQL'
                    SELECT id FROM fib_booking_resource WHERE technical_name = :technicalName
                    SQL,
                ['technicalName' => $identifier],
            );
        }

        return $found === false ? null : (string) $found;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function parseTimes(string $times): array
    {
        $parsed = [];
        foreach (explode(',', $times) as $time) {
            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($time), $matches)) {
                $parsed[] = [(int) $matches[1], (int) $matches[2]];
            }
        }

        return $parsed;
    }

    /**
     * @return list<int>|null
     */
    private function parseWeekdays(mixed $weekdays): ?array
    {
        if (!is_string($weekdays) || trim($weekdays) === '') {
            return null;
        }

        $parsed = [];
        foreach (explode(',', strtolower($weekdays)) as $day) {
            $key = substr(trim($day), 0, 3);
            if (isset(self::WEEKDAYS[$key])) {
                $parsed[] = self::WEEKDAYS[$key];
            }
        }

        return $parsed === [] ? null : $parsed;
    }
}
