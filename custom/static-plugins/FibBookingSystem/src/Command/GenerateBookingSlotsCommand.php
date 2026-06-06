<?php

declare(strict_types=1);

namespace FibBookingSystem\Command;

use DateInterval;
use DateTimeImmutable;
use FibBookingSystem\Core\Content\BookingResource\BookingResourceCollection;
use FibBookingSystem\Core\Content\BookingSlot\BookingSlotCollection;
use FibBookingSystem\Core\Content\BookingSlot\BookingSlotEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
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
 * Slots are unique on (resource, starts_at). Existing slots for a date are
 * left untouched; only missing ones are created — so re-running is safe and
 * never duplicates. Individual slots can be managed via the Admin API
 * (/api/fib-booking-slot).
 */
#[AsCommand(
    name: 'fib-booking:slots:generate',
    description: 'Generates bookable slots (Termine) for a booking resource over a date range.',
)]
class GenerateBookingSlotsCommand extends Command
{
    private const WEEKDAYS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
    private const CREATE_CHUNK_SIZE = 500;

    /**
     * @param EntityRepository<BookingResourceCollection> $resourceRepository
     * @param EntityRepository<BookingSlotCollection>     $slotRepository
     */
    public function __construct(
        private readonly EntityRepository $resourceRepository,
        private readonly EntityRepository $slotRepository,
    ) {
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

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();

        $resourceId = $this->resolveResource(self::stringOption($input, 'resource'), $context);
        if ($resourceId === null) {
            $io->error('Resource not found. Pass --resource=<technical_name|hex id>.');

            return Command::FAILURE;
        }

        $from = new DateTimeImmutable(self::stringOption($input, 'from'));
        $to = new DateTimeImmutable(self::stringOption($input, 'to'));
        $duration = max(5, self::intOption($input, 'duration'));
        $capacity = max(1, self::intOption($input, 'capacity'));
        $times = $this->parseTimes(self::stringOption($input, 'times'));
        $weekdays = $this->parseWeekdays($input->getOption('weekdays'));

        if ($times === []) {
            $io->error('No valid --times given (expected HH:MM, comma-separated).');

            return Command::FAILURE;
        }

        $existing = $this->fetchExistingStartTimes($resourceId, $from, $to, $context);

        $payloads = [];
        $now = (new DateTimeImmutable())->format(\DATE_ATOM);

        for ($day = $from; $day <= $to; $day = $day->add(new DateInterval('P1D'))) {
            if ($weekdays !== null && !in_array((int) $day->format('N'), $weekdays, true)) {
                continue;
            }

            foreach ($times as [$hour, $minute]) {
                $startsAt = $day->setTime($hour, $minute);

                if (isset($existing[$startsAt->format('Y-m-d H:i:s')])) {
                    continue;
                }

                $endsAt = $startsAt->add(new DateInterval(sprintf('PT%dM', $duration)));

                $payloads[] = [
                    'id' => Uuid::randomHex(),
                    'resourceId' => $resourceId,
                    'startsAt' => $startsAt->format(\DATE_ATOM),
                    'endsAt' => $endsAt->format(\DATE_ATOM),
                    'capacity' => $capacity,
                    'active' => true,
                    'createdAt' => $now,
                ];
            }
        }

        // Bulk payload: write in chunks of 500 instead of one create per slot.
        foreach (array_chunk($payloads, self::CREATE_CHUNK_SIZE) as $chunk) {
            $this->slotRepository->create($chunk, $context);
        }

        $io->success(sprintf(
            '%d new slot(s) created (%s – %s, capacity %d); existing slots left untouched.',
            count($payloads),
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
            $capacity,
        ));

        return Command::SUCCESS;
    }

    private function resolveResource(
        string $identifier,
        Context $context,
    ): ?string {
        if ($identifier === '') {
            return null;
        }

        $criteria = new Criteria();

        if (preg_match('/^[0-9a-f]{32}$/i', $identifier)) {
            $criteria->addFilter(new EqualsFilter('id', strtolower($identifier)));
        } else {
            $criteria->addFilter(new EqualsFilter('technicalName', $identifier));
        }

        return $this->resourceRepository->searchIds($criteria, $context)->firstId();
    }

    /**
     * Existing slot start times for the resource in the window, keyed by their
     * storage representation for O(1) lookup.
     *
     * @return array<string, true>
     */
    private function fetchExistingStartTimes(
        string $resourceId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        Context $context,
    ): array {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('resourceId', $resourceId));
        $criteria->addFilter(new RangeFilter('startsAt', [
            RangeFilter::GTE => $from->format(\DATE_ATOM),
            RangeFilter::LTE => $to->add(new DateInterval('P1D'))->format(\DATE_ATOM),
        ]));

        $slots = $this->slotRepository->search($criteria, $context)->getEntities();

        $existing = [];

        /** @var BookingSlotEntity $slot */
        foreach ($slots as $slot) {
            $existing[$slot->getStartsAt()->format('Y-m-d H:i:s')] = true;
        }

        return $existing;
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

    private static function stringOption(
        InputInterface $input,
        string $name,
    ): string {
        $value = $input->getOption($name);

        return is_string($value) ? $value : '';
    }

    private static function intOption(
        InputInterface $input,
        string $name,
    ): int {
        $value = $input->getOption($name);

        return is_numeric($value) ? (int) $value : 0;
    }
}
