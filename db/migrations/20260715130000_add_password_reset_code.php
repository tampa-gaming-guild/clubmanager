<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

final class AddPasswordResetCode extends AbstractMigration
{
    private const RESET_SUBJECT = 'Reset Your TGG Portal Password';

    private const OLD_RESET_BODY = '<h2>Password Reset Request</h2><p>Hello, {display_name},</p><p>We received a request to reset the password for your TGG Membership Portal account.</p><p>To reset your password, please click the link below:</p><p><a href="{reset_link}">{reset_link}</a></p><p>You can enter this reset code manually in the app:<br><code>{reset_code}</code></p><p>This code and link are secure and will expire in <strong>{expires_in}</strong>.</p><p>If you did not request a password reset, you can safely ignore this email.</p><p>Best regards,<br>TGG Club Team</p>';
    private const OLD_RESET_DESCRIPTION = 'Sent when a user requests a password reset link.';

    private const NEW_RESET_BODY = '<h2>Password Reset Request</h2><p>Hello, {display_name},</p><p>We received a request to reset the password for your TGG Membership Portal account.</p><p>To reset your password, please click the link below:</p><p><a href="{reset_link}">{reset_link}</a></p><p>Or enter this code on the reset page, along with your email address:</p><p style="font-family: \'Courier New\', Courier, monospace; font-size: 28px; font-weight: bold; letter-spacing: 6px; margin: 16px 0;">{reset_code}</p><p>This code and link will expire in <strong>{expires_in}</strong>.</p><p>If you did not request a password reset, you can safely ignore this email.</p><p>Best regards,<br>TGG Club Team</p>';
    private const NEW_RESET_DESCRIPTION = 'Sent when a user requests a password reset link, with a short numeric code as an alternative to the link.';

    public function up(): void
    {
        // Short 6-digit code (stored sha256-hashed) as a typeable alternative to
        // the long link token. Nullable: rows predating this migration, and rows
        // whose code has been consumed, simply cannot match the code path.
        // code_attempts caps guesses -- a 6-digit space needs a strike limit.
        $this->table('tgg_password_resets')
            ->addColumn('code', 'string', ['limit' => 64, 'null' => true, 'default' => null, 'after' => 'token'])
            ->addColumn('code_attempts', 'integer', ['limit' => MysqlAdapter::INT_TINY, 'signed' => false, 'null' => false, 'default' => 0, 'after' => 'code'])
            ->update();

        $this->updateTemplate(self::OLD_RESET_BODY, self::NEW_RESET_BODY, self::NEW_RESET_DESCRIPTION);
    }

    public function down(): void
    {
        $this->updateTemplate(self::NEW_RESET_BODY, self::OLD_RESET_BODY, self::OLD_RESET_DESCRIPTION);

        $this->table('tgg_password_resets')
            ->removeColumn('code_attempts')
            ->removeColumn('code')
            ->update();
    }

    // Only update the row if it still holds the default body. If an admin has
    // customized the template via the admin panel, the body no longer matches
    // and the row is left alone -- same "never overwrite a customization"
    // policy as EmailTemplatesSeeder and 20260624120000_update_welcome_email_templates.
    private function updateTemplate(string $matchBody, string $newBody, string $newDescription): void
    {
        $stmt = $this->getAdapter()->getConnection()->prepare(
            'UPDATE `tgg_email_templates`
             SET `subject` = :subject, `body` = :body, `description` = :description
             WHERE `template_key` = :template_key AND `body` = :match_body'
        );
        $stmt->execute([
            'subject' => self::RESET_SUBJECT,
            'body' => $newBody,
            'description' => $newDescription,
            'template_key' => 'password_reset_link',
            'match_body' => $matchBody,
        ]);
    }
}
