<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

final class EmailTemplatesSeeder extends AbstractSeed
{
    public function getDescription(): string
    {
        return 'Default email template bodies. INSERT ONLY where missing -- never '
             . 'overwrites a template an admin has customized in the admin panel.';
    }

    public function run(): void
    {
        $rows = [
            [
                'template_key' => 'signup',
                'subject' => 'Welcome to TGG Club!',
                'body' => '<h2>Welcome, {display_name}!</h2><p>Thank you for joining TGG Club! Your <strong>{tier_name}</strong> membership is now active.</p><p><strong>Membership Details:</strong></p><ul><li><strong>Start Date:</strong> {start_date}</li><li><strong>Expiration Date:</strong> {end_date}</li></ul><p>You don\'t need to log in to be a member -- this email is all you need. But if you\'d like to access the member portal, click below to set up a password:</p><p><a href="{set_password_link}">{set_password_link}</a></p><p>Best regards,<br>TGG Club Team</p>',
                'description' => 'Welcome email sent after a new (non-Trial) member\'s first payment succeeds, with a link to optionally set up portal access.',
            ],
            [
                'template_key' => 'payment_received',
                'subject' => 'Receipt: Your TGG Membership is Active!',
                'body' => '<h2>Hello, {display_name}!</h2><p>Thank you for your payment of <strong>${amount}</strong>.</p><p>Your subscription to the <strong>{tier_name}</strong> plan is now active!</p><p><strong>Membership Details:</strong></p><ul><li><strong>Start Date:</strong> {start_date}</li><li><strong>Expiration Date:</strong> {end_date}</li></ul><p>You can now log in to your account dashboard at: <a href="{login_url}">{login_url}</a></p><p>Best regards,<br>TGG Club Team</p>',
                'description' => 'Payment confirmation receipt sent upon successful checkout session completion.',
            ],
            [
                'template_key' => 'credits_converted',
                'subject' => 'Membership Extended: Volunteer Credits Redeemed!',
                'body' => '<h2>Hello, {display_name}!</h2><p>Congratulations! You have successfully redeemed <strong>{credits_used}</strong> volunteer credits.</p><p>As a result, your membership has been extended by <strong>{months_extended} month(s)</strong> free of charge.</p><p>Your new membership expiration date is <strong>{new_end_date}</strong>.</p><p>Thank you for volunteering and contributing your time to the club!</p><p>Best regards,<br>TGG Club Team</p>',
                'description' => "Notification sent when an admin applies volunteer credits to extend a user's membership.",
            ],
            [
                'template_key' => 'password_reset_link',
                'subject' => 'Reset Your TGG Portal Password',
                'body' => '<h2>Password Reset Request</h2><p>Hello, {display_name},</p><p>We received a request to reset the password for your TGG Membership Portal account.</p><p>To reset your password, please click the link below:</p><p><a href="{reset_link}">{reset_link}</a></p><p>You can enter this reset code manually in the app:<br><code>{reset_code}</code></p><p>This code and link are secure and will expire in <strong>{expires_in}</strong>.</p><p>If you did not request a password reset, you can safely ignore this email.</p><p>Best regards,<br>TGG Club Team</p>',
                'description' => 'Sent when a user requests a password reset link.',
            ],
            [
                'template_key' => 'password_reset_completed',
                'subject' => 'Password Reset Successful',
                'body' => '<h2>Password Reset Successful</h2><p>Hello, {display_name},</p><p>Your password for the TGG Membership Portal has been reset successfully.</p><p>You can now log in to the portal using your new password here: <a href="{login_url}">{login_url}</a></p><p>If you did not initiate this password reset, please contact an administrator immediately.</p><p>Best regards,<br>TGG Club Team</p>',
                'description' => 'Sent to confirm that a user has successfully changed their password after a reset request.',
            ],
            [
                'template_key' => 'trial_verification',
                'subject' => 'Confirm Your TGG Club 30-Day Trial',
                'body' => '<h2>Hello, {display_name}!</h2><p>Thanks for requesting a 30-day Trial membership with TGG Club. This is a one-time offer, so please confirm your email address to activate it.</p><p>Click the link below to activate your Trial membership:</p><p><a href="{verify_link}">{verify_link}</a></p><p>This link will expire in <strong>{expires_in}</strong>. Your trial has not started yet, and your 30 days will not begin counting down until you click the link.</p><p>If you did not request this, you can safely ignore this email.</p><p>Best regards,<br>TGG Club Team</p>',
                'description' => 'Sent when a new member registers for the one-time Trial membership, asking them to verify their email before the trial activates.',
            ],
            [
                'template_key' => 'trial_activated',
                'subject' => 'Your 30-Day Trial Membership is Active!',
                'body' => '<h2>Hello, {display_name}!</h2><p>Your email has been verified and your 30-day Trial membership is now active.</p><p><strong>Membership Details:</strong></p><ul><li><strong>Start Date:</strong> {start_date}</li><li><strong>Expiration Date:</strong> {end_date}</li></ul><p>This Trial membership is a one-time offer and cannot be renewed. To continue your membership after it expires, you will need to sign up for a paid membership level.</p><p>You don\'t need to log in to use your Trial membership -- this email is all you need. But if you\'d like to access the member portal, click below to set up a password:</p><p><a href="{set_password_link}">{set_password_link}</a></p><p>Best regards,<br>TGG Club Team</p>',
                'description' => 'Sent when a member confirms their email and their one-time Trial membership is activated. Doubles as their welcome email since Trial members never set a password at signup.',
            ],
        ];

        $sql = 'INSERT INTO `tgg_email_templates` (`template_key`, `subject`, `body`, `description`)
                SELECT :template_key, :subject, :body, :description FROM DUAL
                WHERE NOT EXISTS (SELECT 1 FROM `tgg_email_templates` WHERE `template_key` = :template_key2)';
        $stmt = $this->getAdapter()->getConnection()->prepare($sql);
        foreach ($rows as $row) {
            $row['template_key2'] = $row['template_key'];
            $stmt->execute($row);
        }
    }
}
