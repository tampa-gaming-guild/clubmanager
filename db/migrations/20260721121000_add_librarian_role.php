<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds the 'librarian' role and 'manage library' permission for the board
 * game library feature, following the majordomo precedent in
 * 20260625180000_role_permission_restructure.php.
 */
final class AddLibrarianRole extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("INSERT IGNORE INTO tgg_permissions (name, description) VALUES
            ('manage library', 'Manage the board game library: add/edit/remove games and track member-loaned copies; syncs to BoardGameGeek')");

        $this->execute("INSERT IGNORE INTO tgg_roles (name, description, sort_order) VALUES
            ('librarian', 'Manages the club''s board game library', 6)");

        $this->execute("INSERT IGNORE INTO tgg_role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM tgg_roles r, tgg_permissions p
                WHERE (r.name = 'librarian' AND p.name = 'manage library')
                   OR (r.name = 'admin' AND p.name = 'manage library')");
    }

    public function down(): void
    {
        // Safety: refuse to run if any user still has the librarian role.
        $row = $this->query("SELECT COUNT(*) AS cnt FROM tgg_member_roles WHERE role_name = 'librarian'")->fetch();
        if ((int)$row['cnt'] > 0) {
            throw new \RuntimeException(
                "Migration aborted: {$row['cnt']} user(s) still have the librarian role. " .
                "Remove librarian role assignments before rolling back this migration."
            );
        }

        $this->execute("DELETE rp FROM tgg_role_permissions rp
                        INNER JOIN tgg_roles r ON r.id = rp.role_id
                        WHERE r.name = 'librarian'");
        $this->execute("DELETE FROM tgg_role_permissions
                        WHERE permission_id = (SELECT id FROM tgg_permissions WHERE name = 'manage library')");
        $this->execute("DELETE FROM tgg_roles WHERE name = 'librarian'");
        $this->execute("DELETE FROM tgg_permissions WHERE name = 'manage library'");
    }
}
