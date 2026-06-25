<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

final class RolePermissionsSeeder extends AbstractSeed
{
    public function getDescription(): string
    {
        return 'Role-to-permission mappings. Replaces all non-superadmin mappings on each run to reflect the canonical matrix.';
    }

    public function getDependencies(): array
    {
        return ['RolesSeeder', 'PermissionsSeeder'];
    }

    public function run(): void
    {
        // Remove all existing mappings for non-superadmin roles so the seeder is idempotent.
        $this->execute("DELETE rp FROM tgg_role_permissions rp
                        INNER JOIN tgg_roles r ON r.id = rp.role_id
                        WHERE r.name != 'superadmin'");

        $this->execute("INSERT IGNORE INTO `tgg_role_permissions` (`role_id`, `permission_id`)
                SELECT r.id, p.id FROM tgg_roles r, tgg_permissions p
                WHERE (r.name = 'superadmin' AND p.name = 'all')
                   OR (r.name = 'admin' AND p.name IN (
                          'admin panel', 'manage configuration', 'manage roles',
                          'process payments', 'schedule events', 'edit checkins',
                          'manage hosting', 'password resets', 'volunteer'
                      ))
                   OR (r.name = 'majordomo' AND p.name IN (
                          'manage hosting', 'process payments', 'schedule events',
                          'edit checkins', 'volunteer'
                      ))
                   OR (r.name = 'host' AND p.name IN (
                          'process payments', 'edit checkins', 'volunteer'
                      ))");
    }
}
