<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UpdateWelcomeEmailTemplates extends AbstractMigration
{
    private const OLD_SIGNUP_BODY = '<h2>Welcome, {display_name}!</h2><p>Thank you for signing up for the TGG Membership Portal. Your account has been registered with the email <strong>{email}</strong>.</p><p>If you have not done so already, please complete your checkout to activate your subscription.</p><p>You can access the portal and complete any pending payments by logging in here: <a href="{login_url}">{login_url}</a></p><p>Best regards,<br>TGG Club Team</p>';

    private const NEW_SIGNUP_SUBJECT = 'Welcome to TGG Club!';
    private const NEW_SIGNUP_BODY = '<h2>Welcome, {display_name}!</h2><p>Thank you for joining TGG Club! Your <strong>{tier_name}</strong> membership is now active.</p><p><strong>Membership Details:</strong></p><ul><li><strong>Start Date:</strong> {start_date}</li><li><strong>Expiration Date:</strong> {end_date}</li></ul><p>You don\'t need to log in to be a member -- this email is all you need. But if you\'d like to access the member portal, click below to set up a password:</p><p><a href="{set_password_link}">{set_password_link}</a></p><p>Best regards,<br>TGG Club Team</p>';
    private const NEW_SIGNUP_DESCRIPTION = 'Welcome email sent after a new (non-Trial) member\'s first payment succeeds, with a link to optionally set up portal access.';

    private const OLD_TRIAL_ACTIVATED_BODY = '<h2>Hello, {display_name}!</h2><p>Your email has been verified and your 30-day Trial membership is now active.</p><p><strong>Membership Details:</strong></p><ul><li><strong>Start Date:</strong> {start_date}</li><li><strong>Expiration Date:</strong> {end_date}</li></ul><p>This Trial membership is a one-time offer and cannot be renewed. To continue your membership after it expires, you will need to sign up for a paid membership level.</p><p>You can log in to your account dashboard at: <a href="{login_url}">{login_url}</a></p><p>Best regards,<br>TGG Club Team</p>';

    private const NEW_TRIAL_ACTIVATED_SUBJECT = 'Your 30-Day Trial Membership is Active!';
    private const NEW_TRIAL_ACTIVATED_BODY = '<h2>Hello, {display_name}!</h2><p>Your email has been verified and your 30-day Trial membership is now active.</p><p><strong>Membership Details:</strong></p><ul><li><strong>Start Date:</strong> {start_date}</li><li><strong>Expiration Date:</strong> {end_date}</li></ul><p>This Trial membership is a one-time offer and cannot be renewed. To continue your membership after it expires, you will need to sign up for a paid membership level.</p><p>You don\'t need to log in to use your Trial membership -- this email is all you need. But if you\'d like to access the member portal, click below to set up a password:</p><p><a href="{set_password_link}">{set_password_link}</a></p><p>Best regards,<br>TGG Club Team</p>';
    private const NEW_TRIAL_ACTIVATED_DESCRIPTION = 'Sent when a member confirms their email and their one-time Trial membership is activated. Doubles as their welcome email since Trial members never set a password at signup.';

    public function up(): void
    {
        // Only update rows that still hold the original pre-redesign default
        // text. If an admin has customized either template via the admin
        // panel, the body will no longer match and the row is left alone --
        // same "never overwrite a customization" policy as EmailTemplatesSeeder.
        $this->updateTemplate('signup', self::OLD_SIGNUP_BODY, self::NEW_SIGNUP_SUBJECT, self::NEW_SIGNUP_BODY, self::NEW_SIGNUP_DESCRIPTION);
        $this->updateTemplate('trial_activated', self::OLD_TRIAL_ACTIVATED_BODY, self::NEW_TRIAL_ACTIVATED_SUBJECT, self::NEW_TRIAL_ACTIVATED_BODY, self::NEW_TRIAL_ACTIVATED_DESCRIPTION);
    }

    public function down(): void
    {
        $this->updateTemplate('signup', self::NEW_SIGNUP_BODY, 'Welcome to TGG Club!', self::OLD_SIGNUP_BODY, 'Welcome email sent immediately after a user registers their account details.');
        $this->updateTemplate('trial_activated', self::NEW_TRIAL_ACTIVATED_BODY, 'Your 30-Day Trial Membership is Active!', self::OLD_TRIAL_ACTIVATED_BODY, 'Sent when a member confirms their email and their one-time Trial membership is activated.');
    }

    private function updateTemplate(string $templateKey, string $matchBody, string $newSubject, string $newBody, string $newDescription): void
    {
        $stmt = $this->getAdapter()->getConnection()->prepare(
            'UPDATE `tgg_email_templates`
             SET `subject` = :subject, `body` = :body, `description` = :description
             WHERE `template_key` = :template_key AND `body` = :match_body'
        );
        $stmt->execute([
            'subject' => $newSubject,
            'body' => $newBody,
            'description' => $newDescription,
            'template_key' => $templateKey,
            'match_body' => $matchBody,
        ]);
    }
}
