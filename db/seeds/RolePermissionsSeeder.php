<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

final class RolePermissionsSeeder extends AbstractSeed
{
    public function getDescription(): string
    {
        return 'Role-to-permission mappings. Insert-if-missing only (mirrors INSERT IGNORE).';
    }

    public function getDependencies(): array
    {
        return ['RolesSeeder', 'PermissionsSeeder'];
    }

    public function run(): void
    {
        $sql = "INSERT IGNORE INTO `tgg_role_permissions` (`role_id`, `permission_id`)
                SELECT r.id, p.id FROM tgg_roles r, tgg_permissions p
                WHERE (r.name = 'superadmin' AND p.name = 'all')
                   OR (r.name = 'admin' AND p.name IN ('process payments', 'schedule events', 'edit checkins', 'edit volunteer slots', 'password resets'))
                   OR (r.name = 'host' AND p.name IN ('schedule events', 'edit checkins', 'edit volunteer slots'))";
        $this->execute($sql);
    }
}
