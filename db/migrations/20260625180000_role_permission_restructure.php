<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RolePermissionRestructure extends AbstractMigration
{
    public function up(): void
    {
        // Safety: refuse to run if any user still has the guest role.
        $row = $this->query("SELECT COUNT(*) AS cnt FROM tgg_member_roles WHERE role_name = 'guest'")->fetch();
        if ((int)$row['cnt'] > 0) {
            throw new \RuntimeException(
                "Migration aborted: {$row['cnt']} user(s) still have the guest role. " .
                "Remove guest role assignments before running this migration."
            );
        }

        // 1. Remove guest role entirely.
        $this->execute("DELETE FROM tgg_role_permissions
                        WHERE role_id = (SELECT id FROM tgg_roles WHERE name = 'guest')");
        $this->execute("DELETE FROM tgg_roles WHERE name = 'guest'");

        // 2. Rename 'edit volunteer slots' → 'manage hosting'.
        $this->execute("UPDATE tgg_permissions
                        SET name = 'manage hosting',
                            description = 'Manage volunteer slots and credits for other members; assign the host role'
                        WHERE name = 'edit volunteer slots'");

        // 3. Insert new permissions (INSERT IGNORE is safe for reruns).
        $this->execute("INSERT IGNORE INTO tgg_permissions (name, description) VALUES
            ('admin panel',           'Access to admin-only member management UI (profile rate info, membership visibility)'),
            ('manage configuration',  'Edit club configuration: membership plans and subscription rates'),
            ('manage roles',          'Assign and remove roles for admin, majordomo, host, and member accounts'),
            ('volunteer',             'Sign up for volunteer slots at events')");

        // 4. Add majordomo role.
        $this->execute("INSERT IGNORE INTO tgg_roles (name, description) VALUES
            ('majordomo', 'Volunteer coordinator who manages hosts and volunteer scheduling')");

        // 5. Rebuild role-permission mappings for all non-superadmin roles.
        $this->execute("DELETE rp FROM tgg_role_permissions rp
                        INNER JOIN tgg_roles r ON r.id = rp.role_id
                        WHERE r.name != 'superadmin'");

        $this->execute("INSERT IGNORE INTO tgg_role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM tgg_roles r, tgg_permissions p
                WHERE (r.name = 'admin' AND p.name IN (
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

        // 6. Update host role description.
        $this->execute("UPDATE tgg_roles SET description = 'Event Host with check-in access'
                        WHERE name = 'host'");
    }

    public function down(): void
    {
        // Reverse: rebuild old permission set and role mappings.

        // Remove new permissions added in up().
        $this->execute("DELETE FROM tgg_permissions
                        WHERE name IN ('admin panel', 'manage configuration', 'manage roles', 'volunteer')");

        // Rename 'manage hosting' back to 'edit volunteer slots'.
        $this->execute("UPDATE tgg_permissions
                        SET name = 'edit volunteer slots',
                            description = 'Assign or cancel volunteer shifts and credits'
                        WHERE name = 'manage hosting'");

        // Remove majordomo role and its mappings.
        $this->execute("DELETE rp FROM tgg_role_permissions rp
                        INNER JOIN tgg_roles r ON r.id = rp.role_id
                        WHERE r.name = 'majordomo'");
        $this->execute("DELETE FROM tgg_member_roles WHERE role_name = 'majordomo'");
        $this->execute("DELETE FROM tgg_roles WHERE name = 'majordomo'");

        // Restore guest role.
        $this->execute("INSERT IGNORE INTO tgg_roles (name, description) VALUES
            ('guest', 'Guest visitor with limited access')");

        // Restore host description.
        $this->execute("UPDATE tgg_roles SET description = 'Event Host with scheduling and check-in access'
                        WHERE name = 'host'");

        // Rebuild old role-permission mappings.
        $this->execute("DELETE rp FROM tgg_role_permissions rp
                        INNER JOIN tgg_roles r ON r.id = rp.role_id
                        WHERE r.name != 'superadmin'");

        $this->execute("INSERT IGNORE INTO tgg_role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM tgg_roles r, tgg_permissions p
                WHERE (r.name = 'admin' AND p.name IN (
                          'process payments', 'schedule events', 'edit checkins',
                          'edit volunteer slots', 'password resets'
                      ))
                   OR (r.name = 'host' AND p.name IN (
                          'schedule events', 'edit checkins', 'edit volunteer slots'
                      ))");
    }
}
