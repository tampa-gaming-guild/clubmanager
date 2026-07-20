<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UpdateCreditsConvertedEmailTemplate extends AbstractMigration
{
    private const OLD_SUBJECT = 'Membership Extended: Volunteer Credits Redeemed!';
    private const OLD_BODY = '<h2>Hello, {display_name}!</h2><p>Congratulations! You have successfully redeemed <strong>{credits_used}</strong> volunteer credits.</p><p>As a result, your membership has been extended by <strong>{months_extended} month(s)</strong> free of charge.</p><p>Your new membership expiration date is <strong>{new_end_date}</strong>.</p><p>Thank you for volunteering and contributing your time to the club!</p><p>Best regards,<br>TGG Club Team</p>';

    private const NEW_SUBJECT = 'Membership Extended: Membership Credits Redeemed!';
    private const NEW_BODY = '<h2>Hello, {display_name}!</h2><p>Congratulations! You have successfully redeemed <strong>{credits_used}</strong> Membership Credits.</p><p>As a result, your membership has been extended by <strong>{months_extended} month(s)</strong> free of charge.</p><p>Your new membership expiration date is <strong>{new_end_date}</strong>.</p><p>Thank you for contributing to the club!</p><p>Best regards,<br>TGG Club Team</p>';
    private const NEW_DESCRIPTION = "Notification sent when Membership Credits are applied to extend a member's membership.";

    private const OLD_DESCRIPTION = "Notification sent when an admin applies volunteer credits to extend a user's membership.";

    public function up(): void
    {
        // Only update the row if it still holds the original pre-rename text --
        // same "never overwrite a customization" policy as EmailTemplatesSeeder.
        $this->updateTemplate(self::OLD_BODY, self::NEW_SUBJECT, self::NEW_BODY, self::NEW_DESCRIPTION);
    }

    public function down(): void
    {
        $this->updateTemplate(self::NEW_BODY, self::OLD_SUBJECT, self::OLD_BODY, self::OLD_DESCRIPTION);
    }

    private function updateTemplate(string $matchBody, string $newSubject, string $newBody, string $newDescription): void
    {
        $stmt = $this->getAdapter()->getConnection()->prepare(
            'UPDATE `tgg_email_templates`
             SET `subject` = :subject, `body` = :body, `description` = :description
             WHERE `template_key` = \'credits_converted\' AND `body` = :match_body'
        );
        $stmt->execute([
            'subject' => $newSubject,
            'body' => $newBody,
            'description' => $newDescription,
            'match_body' => $matchBody,
        ]);
    }
}
