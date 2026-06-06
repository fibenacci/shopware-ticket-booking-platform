<?php

declare(strict_types=1);

namespace FibBookingDemoData\Command;

use FibBookingDemoData\Service\BookingDemoDataSeeder;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds the booking demo data defined in Resources/seeds/booking-demo.json:
 * bookable products with their resources, the Starter/Premium/Luxury package
 * scenario with calendar slots, and a confirmed reservation + QR ticket.
 */
#[AsCommand(
    name: 'fib-booking:demodata',
    description: 'Seeds booking demo data from Resources/seeds/booking-demo.json (products, resources, slots, packages, reservation + ticket).',
)]
class SeedDemoDataCommand extends Command
{
    public function __construct(private readonly BookingDemoDataSeeder $seeder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('skip-reservation', null, InputOption::VALUE_NONE, 'Do not create the demo reservation + ticket');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);
        $io->title('FIB Booking demo data');

        $result = $this->seeder->seed(
            Context::createCLIContext(),
            !$input->getOption('skip-reservation'),
        );

        $io->table(
            ['Product', 'Number', 'Product ID', 'Resource ID'],
            array_map(static fn (array $product): array => [
                $product['name'],
                $product['number'],
                $product['id'],
                $product['resourceId'],
            ], [...$result['products'], ...$result['packages']]),
        );

        $io->writeln(sprintf('  %d calendar slot(s) upserted for the package resource.', $result['slots']));

        if ($result['homepageAssigned']) {
            $io->writeln('  Homepage layout "FIB Booking Home" assigned (booking calendar on the start page).');
        }

        if ($result['scannerUser'] !== null) {
            $io->writeln(sprintf(
                '  Scanner access: user <info>%s</info> with least-privilege role <info>%s</info> (demo credentials — see seeds JSON).',
                $result['scannerUser']['username'],
                $result['scannerUser']['role'],
            ));
        }

        if ($result['reservationId'] !== null) {
            $io->writeln(sprintf(
                '  Reservation <info>B-DEMO-1</info> (%s)%s',
                $result['reservationId'],
                $result['ticketNumber'] !== null
                    ? sprintf(' with ticket <info>%s</info>', $result['ticketNumber'])
                    : ' — ticket already issued earlier',
            ));
        }
        $io->newLine();

        // Machine-readable block — consumed by CI / E2E (eval-able).
        $output->writeln('FIB_BOOKING_PRODUCT_ID=' . $result['products'][0]['id']);
        $output->writeln('FIB_BOOKING_RESOURCE_ID=' . $result['products'][0]['resourceId']);
        if ($result['packages'] !== []) {
            $output->writeln('FIB_BOOKING_PACKAGE_PRODUCT_ID=' . $result['packages'][0]['id']);
            $output->writeln('FIB_BOOKING_PACKAGE_RESOURCE_ID=' . $result['packages'][0]['resourceId']);
        }

        $io->success('Demo data seeded (idempotent — safe to re-run).');

        return Command::SUCCESS;
    }
}
