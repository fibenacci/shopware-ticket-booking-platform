<?php

declare(strict_types=1);

namespace FibBookingDemoData\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Least-privilege scanner access: an ACL role carrying ONLY the privileges
 * the operator app needs (scan tickets, read statistics) and a non-admin
 * demo user bound to it. The scanner app logs in with this user — it can
 * do nothing else.
 *
 * Also applies the scanner-related plugin config the demo expects (e.g.
 * enabling check-out scans so the dwell-time statistics have data).
 */
class ScannerAccessSeeder
{
    /**
     * @param EntityRepository<\Shopware\Core\Framework\Api\Acl\Role\AclRoleCollection> $aclRoleRepository
     * @param EntityRepository<\Shopware\Core\System\User\UserCollection>               $userRepository
     * @param EntityRepository<\Shopware\Core\System\Locale\LocaleCollection>           $localeRepository
     */
    public function __construct(
        private readonly EntityRepository $aclRoleRepository,
        private readonly EntityRepository $userRepository,
        private readonly EntityRepository $localeRepository,
        private readonly SystemConfigService $systemConfig,
    ) {
    }

    /**
     * @return array{username: string, role: string}|null
     */
    public function seedScannerAccess(
        SeedSection $scanner,
        Context $context,
    ): ?array {
        if ($scanner->has('checkOutEnabled')) {
            $this->systemConfig->set('FibBookingSystem.config.scanCheckOutEnabled', $scanner->bool('checkOutEnabled'));
        }

        $role = $scanner->sectionOrNull('role');
        $roleName = $role?->string('name', 'Booking Scanner') ?? 'Booking Scanner';
        $privileges = $role?->stringList('privileges', ['fib_booking.ticket_scan']) ?? ['fib_booking.ticket_scan'];

        $existingRoleId = $this->aclRoleRepository->searchIds(
            (new Criteria())->addFilter(new EqualsFilter('name', $roleName)),
            $context,
        )->firstId();
        $roleId = is_string($existingRoleId) ? $existingRoleId : SeedIds::stable('acl-role:scanner');

        $this->aclRoleRepository->upsert([
            [
                'id' => $roleId,
                'name' => $roleName,
                'description' => 'Demo role: may scan booking tickets — nothing else.',
                'privileges' => $privileges,
            ],
        ], $context);

        $user = $scanner->sectionOrNull('user');
        if ($user === null) {
            return null;
        }

        $username = $user->string('username');
        $existingUserId = $this->userRepository->searchIds(
            (new Criteria())->addFilter(new EqualsFilter('username', $username)),
            $context,
        )->firstId();
        $userId = is_string($existingUserId) ? $existingUserId : SeedIds::stable('user:scanner');

        $localeId = $this->localeRepository->searchIds((new Criteria())->setLimit(1), $context)->firstId();
        if (!is_string($localeId)) {
            return null;
        }

        $this->userRepository->upsert([
            [
                'id' => $userId,
                'username' => $username,
                'password' => $user->string('password'),
                'firstName' => $user->string('firstName', 'Demo'),
                'lastName' => $user->string('lastName', 'Scanner'),
                'email' => $user->string('email', 'scanner@example.invalid'),
                'localeId' => $localeId,
                'admin' => false,
                'active' => true,
                'aclRoles' => [
                    ['id' => $roleId],
                ],
            ],
        ], $context);

        return ['username' => $username, 'role' => $roleName];
    }
}
