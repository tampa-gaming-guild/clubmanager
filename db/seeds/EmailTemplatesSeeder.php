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
            [
                'template_key' => 'auto_renew_upcoming',
                'subject' => 'Your TGG Membership Will Auto-Renew Soon',
                'body' => '<h2>Hello, {display_name}!</h2><p>Your <strong>{tier_name}</strong> membership is set to automatically renew on <strong>{renew_date}</strong> for <strong>${amount}</strong> using the card we have on file.</p><p>No action is needed if you\'d like this to happen automatically. If you\'d like to turn off auto-renew or update your payment method, visit your profile: <a href="{manage_url}">{manage_url}</a></p><p>Best regards,<br>TGG Club Team</p>',
                'description' => 'Sent 5 days before an auto-renewal charge is attempted, once per renewal cycle.',
            ],
            [
                'template_key' => 'auto_renew_failed',
                'subject' => 'Action Needed: Your TGG Membership Card Was Declined',
                'body' => '<h2>Hello, {display_name}!</h2><p>We tried to automatically renew your <strong>{tier_name}</strong> membership, but the card on file was declined.</p><p>Your membership remains active for now (it was due to renew on {end_date}), and we\'ll automatically try again. To avoid any interruption, please update your payment method or renew manually: <a href="{renew_url}">{renew_url}</a></p><p>Best regards,<br>TGG Club Team</p>',
                'description' => 'Sent once per renewal cycle after the first failed automatic renewal charge attempt.',
            ],
            [
                'template_key' => 'auto_renew_expired',
                'subject' => 'Your TGG Membership Has Expired',
                'body' => '<h2>Hello, {display_name}!</h2><p>We were unable to automatically renew your <strong>{tier_name}</strong> membership after several attempts, and your card on file was declined each time. Your membership (due to renew on {end_date}) has now expired.</p><p>To continue your membership, please renew manually: <a href="{renew_url}">{renew_url}</a></p><p>Best regards,<br>TGG Club Team</p>',
                'description' => 'Sent when an automatic renewal charge fails for the 3rd and final consecutive attempt, and the membership is marked expired.',
            ],
            [
                'template_key' => 'renewal_reminder',
                'subject' => 'Your TGG Membership Expires Soon',
                'body' => '<h2>Hello, {display_name}!</h2><p>Your <strong>{tier_name}</strong> membership is set to expire on <strong>{end_date}</strong>.</p><p>To keep your membership active without any interruption, please renew before that date:</p><p><a href="{renew_url}">{renew_url}</a></p><p>Best regards,<br>TGG Club Team</p>',
                'description' => 'Sent 5 days before expiry to members who do not have auto-renew enabled, once per renewal cycle.',
            ],
            [
                'template_key' => 'membership_expired',
                'subject' => 'Your TGG Membership Has Expired',
                'body' => '<h2>Hello, {display_name}!</h2><p>Your <strong>{tier_name}</strong> membership expired on <strong>{end_date}</strong> and the grace period has now ended.</p><p>We\'d love to have you back! Click the link below to renew your membership:</p><p><a href="{renew_url}">{renew_url}</a></p><p>Best regards,<br>TGG Club Team</p>',
                'description' => 'Sent once after a member\'s grace period ends and they have not renewed, for members without a failed auto-renewal (those get auto_renew_expired instead).',
            ],
            [
                'template_key' => 'email_change_verification',
                'subject' => 'Confirm Your New TGG Portal Email Address',
                'body' => '<h2>Hello, {display_name}!</h2><p>A request was made to change the login email for the TGG account currently registered to <strong>{old_email}</strong> to this address (<strong>{new_email}</strong>).</p><p>Click the link below to confirm the change:</p><p><a href="{verify_link}">{verify_link}</a></p><p>This link will expire in <strong>{expires_in}</strong>. Your email will not change until you click it.</p><p>If you did not request this, you can safely ignore this email — nothing will change.</p><p>Best regards,<br>TGG Club Team</p>',
                'description' => 'Sent to the NEW address when a member requests an email change, asking them to confirm before the change takes effect.',
            ],
            [
                'template_key' => 'email_change_requested',
                'subject' => 'Security Alert: Email Change Requested on Your TGG Account',
                'body' => '<h2>Hello, {display_name},</h2><p>A request was made to change your TGG portal login email to <strong>{new_email}</strong>. Your email has <strong>NOT</strong> changed yet — the request expires in <strong>{expires_in}</strong> unless it is confirmed from the new address.</p><p>If this was you, check the new address\'s inbox for a confirmation link. No further action is needed here.</p><p><strong>If this was NOT you:</strong></p><ol><li>Cancel the change immediately (no login required): <a href="{cancel_link}">{cancel_link}</a></li><li>Then reset your password right away, because whoever requested this knows your current password: <a href="{reset_link}">{reset_link}</a></li></ol><p>Best regards,<br>TGG Club Team</p>',
                'description' => 'Security alarm sent to the OLD address when an email change is requested, with a one-click cancel link and password reset guidance.',
            ],
            [
                'template_key' => 'email_change_completed',
                'subject' => 'Your TGG Portal Login Email Was Changed',
                'body' => '<h2>Hello, {display_name},</h2><p>Your TGG portal login email was changed from <strong>{old_email}</strong> to <strong>{new_email}</strong>. This address no longer works for portal login.</p><p><strong>If you did not do this</strong>, click the link below within <strong>{revert_expires}</strong> to restore this address and lock the intruder out — then reset your password immediately, because whoever changed your email knew your password:</p><p><a href="{revert_link}">{revert_link}</a></p><p>If the link has expired, contact an administrator immediately.</p><p>Best regards,<br>TGG Club Team</p>',
                'description' => 'Sent to the OLD address when a member-verified email change completes, with a time-limited revert link for account takeover recovery.',
            ],
            [
                'template_key' => 'email_change_admin_notice',
                'subject' => 'Your TGG Portal Login Email Was Updated',
                'body' => '<h2>Hello, {display_name},</h2><p>A staff member updated your TGG portal login email to this address (<strong>{new_email}</strong>).</p><p>Use this address to log in from now on. Your password has not changed.</p><p>If this is unexpected, please contact an administrator.</p><p>Best regards,<br>TGG Club Team</p>',
                'description' => 'Sent to the NEW address when a staff member directly updates a member\'s login email.',
            ],
            [
                'template_key' => 'email_change_staff_notice',
                'subject' => 'Your TGG Portal Login Email Was Changed by Staff',
                'body' => '<h2>Hello, {display_name},</h2><p>A staff member changed your TGG portal login email from <strong>{old_email}</strong> to <strong>{new_email}</strong>. This address no longer works for portal login.</p><p>If this change is unexpected, please contact an administrator.</p><p>Best regards,<br>TGG Club Team</p>',
                'description' => 'Sent to the OLD address when a staff member directly updates a member\'s login email. Deliberately has no revert link — a stranger at a mistyped old address must not be able to undo a staff fix.',
            ],
            [
                'template_key' => 'rate_retired',
                'subject' => 'Your TGG Membership Rate is Changing',
                'body' => '<h2>Hello, {display_name}!</h2><p>The <strong>${old_price}/{billing_frequency}</strong> rate on your <strong>{tier_name}</strong> membership is being retired.</p><p>Starting <strong>{effective_date}</strong> (the start of your next billing period), your rate will be <strong>${new_price}/{billing_frequency}</strong>.</p><p>Your current membership period, through <strong>{end_date}</strong>, is not affected.</p><p>Best regards,<br>TGG Club Team</p>',
                'description' => 'Sent to active members when an admin explicitly retires the rate they were on, moving them to the plan\'s current rate effective their next billing period. Not sent to members who are past their grace period.',
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
