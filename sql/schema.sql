-- MariaDB Database Schema for Simple CiviCRM Member Tracking (Local App Tables)
-- These tables support tracking check-ins, events, volunteer signups, and password/settings info.
-- Contact details, memberships, and contributions are stored in CiviCRM tables.

CREATE DATABASE IF NOT EXISTS `tgg_members` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `tgg_members`;

-- 0a. Roles Table
CREATE TABLE IF NOT EXISTS `tgg_roles` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `name` VARCHAR(50) NOT NULL UNIQUE,
  `description` VARCHAR(255) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default roles
INSERT INTO `tgg_roles` (`name`, `description`) VALUES
('superadmin', 'Super Administrator with full access'),
('admin', 'Administrator with management access'),
('host', 'Event Host with scheduling and check-in access'),
('member', 'Regular Club Member'),
('guest', 'Guest visitor with limited access')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- 0b. Permissions Table
CREATE TABLE IF NOT EXISTS `tgg_permissions` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `name` VARCHAR(50) NOT NULL UNIQUE,
  `description` VARCHAR(255) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default permissions
INSERT INTO `tgg_permissions` (`name`, `description`) VALUES
('all', 'All permissions / full access'),
('process payments', 'View payments ledger and process billing'),
('schedule events', 'Create and edit calendar events'),
('edit checkins', 'Log and edit attendance check-ins'),
('edit volunteer slots', 'Assign or cancel volunteer shifts and credits'),
('password resets', 'Perform password resets for contacts')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- 0c. Role Permissions Mapping Table
CREATE TABLE IF NOT EXISTS `tgg_role_permissions` (
  `role_id` INT NOT NULL,
  `permission_id` INT NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`),
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `tgg_roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `tgg_permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default mappings
INSERT IGNORE INTO `tgg_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM tgg_roles r, tgg_permissions p 
WHERE (r.name = 'superadmin' AND p.name = 'all')
   OR (r.name = 'admin' AND p.name IN ('process payments', 'schedule events', 'edit checkins', 'edit volunteer slots', 'password resets'))
   OR (r.name = 'host' AND p.name IN ('schedule events', 'edit checkins', 'edit volunteer slots'));

-- 1. Member settings and credentials (links to civicrm_contact)
CREATE TABLE IF NOT EXISTS `tgg_member_settings` (
  `contact_id` INT NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'member',
  `custom_display_name` VARCHAR(255) NULL,
  `is_profile_public` TINYINT(1) NOT NULL DEFAULT 1, -- 0 = Private, 1 = Public
  `public_fields` TEXT NULL, -- JSON formatted array of fields allowed to be public (e.g. ["display_name", "join_date"])
  `credits_earned` FLOAT NOT NULL DEFAULT 0.0,
  `credits_applied` FLOAT NOT NULL DEFAULT 0.0,
  `expired_credits` FLOAT NOT NULL DEFAULT 0.0,
  `failed_login_attempts` INT NOT NULL DEFAULT 0,
  `locked_until` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`contact_id`),
  CONSTRAINT `fk_member_settings_role` FOREIGN KEY (`role`) REFERENCES `tgg_roles` (`name`) ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 1b. Member Roles Mapping Table
CREATE TABLE IF NOT EXISTS `tgg_member_roles` (
  `contact_id` INT NOT NULL,
  `role_name` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`contact_id`, `role_name`),
  CONSTRAINT `fk_member_roles_contact` FOREIGN KEY (`contact_id`) REFERENCES `tgg_member_settings` (`contact_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_member_roles_role` FOREIGN KEY (`role_name`) REFERENCES `tgg_roles` (`name`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trigger to automatically assign default role on settings creation
DELIMITER ;;
CREATE TRIGGER IF NOT EXISTS `tgg_member_settings_after_insert`
AFTER INSERT ON `tgg_member_settings`
FOR EACH ROW
BEGIN
  INSERT INTO `tgg_member_roles` (`contact_id`, `role_name`)
  VALUES (NEW.contact_id, NEW.role)
  ON DUPLICATE KEY UPDATE `role_name` = VALUES(`role_name`);
END;;
DELIMITER ;

-- 2. Member Check-ins (Attendance Log)
CREATE TABLE IF NOT EXISTS `tgg_checkins` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `contact_id` INT NOT NULL, -- references civicrm_contact.id
  `checked_in_at` DATETIME NOT NULL,
  `notes` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_contact_checkin` (`contact_id`),
  KEY `idx_date_checkin` (`checked_in_at`)
) ENGINE=InnoDB;

-- 3. Calendar Events / Sessions
CREATE TABLE IF NOT EXISTS `tgg_events` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `start_time` DATETIME NOT NULL,
  `end_time` DATETIME NOT NULL,
  `max_volunteers` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_event_times` (`start_time`, `end_time`)
) ENGINE=InnoDB;

-- 4. Volunteer Signups for Events
CREATE TABLE IF NOT EXISTS `tgg_volunteer_signups` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `event_id` INT NOT NULL, -- references tgg_events.id
  `contact_id` INT NOT NULL, -- references civicrm_contact.id
  `role` VARCHAR(100) NOT NULL, -- e.g., 'Open', 'Close', 'Greeter'
  `signed_up_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_contact_role` (`event_id`, `contact_id`, `role`),
  CONSTRAINT `fk_volunteer_event` FOREIGN KEY (`event_id`) REFERENCES `tgg_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. Subscription Plans (Local Options)
CREATE TABLE IF NOT EXISTS `tgg_subscription_plans` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `price` DECIMAL(20,2) NOT NULL,
  `duration_unit` VARCHAR(20) NOT NULL DEFAULT 'year', -- 'month' or 'year'
  `duration_interval` INT NOT NULL DEFAULT 1,
  `civicrm_membership_type_id` INT NOT NULL,
  `active` VARCHAR(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

-- Seed default local subscription plans (options) matching mock CiviCRM membership types
INSERT INTO `tgg_subscription_plans` (`id`, `name`, `description`, `price`, `duration_unit`, `duration_interval`, `civicrm_membership_type_id`, `active`) VALUES
(1, 'Monthly Member', 'Monthly membership subscription', 15.00, 'month', 1, 1, 'active'),
(2, 'Annual Standard', 'Yearly standard individual membership', 120.00, 'year', 1, 2, 'active'),
(3, 'Annual Premium', 'Yearly premium member support', 250.00, 'year', 1, 3, 'active'),
(5, 'Daily', 'Daily membership level', 10.00, 'day', 1, 9, 'active')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `description`=VALUES(`description`), `price`=VALUES(`price`), `duration_unit`=VALUES(`duration_unit`), `duration_interval`=VALUES(`duration_interval`), `civicrm_membership_type_id`=VALUES(`civicrm_membership_type_id`), `active`=VALUES(`active`);

-- 6. Billing Transaction Ledger
CREATE TABLE IF NOT EXISTS `tgg_billing_ledger` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `contact_id` INT NOT NULL,
  `plan_id` INT NOT NULL,
  `stripe_session_id` VARCHAR(255) NOT NULL,
  `payment_intent_id` VARCHAR(255) NOT NULL,
  `amount` DECIMAL(20,2) NOT NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'usd',
  `payment_status` VARCHAR(50) NOT NULL,
  `action_type` VARCHAR(20) NOT NULL, -- 'join' or 'renew'
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stripe_session` (`stripe_session_id`),
  KEY `idx_contact_ledger` (`contact_id`),
  CONSTRAINT `fk_ledger_plan` FOREIGN KEY (`plan_id`) REFERENCES `tgg_subscription_plans` (`id`)
) ENGINE=InnoDB;

-- 7. Local Member Subscriptions
CREATE TABLE IF NOT EXISTS `tgg_subscriptions` (
  `contact_id` INT NOT NULL,
  `plan_id` INT NOT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
  `join_date` DATE NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`contact_id`),
  CONSTRAINT `fk_sub_plan` FOREIGN KEY (`plan_id`) REFERENCES `tgg_subscription_plans` (`id`)
) ENGINE=InnoDB;

-- 8. Volunteer Credits Settings (Single-precision float weights)
CREATE TABLE IF NOT EXISTS `tgg_volunteer_credits` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `credit_key` VARCHAR(50) NOT NULL UNIQUE,
  `credit_label` VARCHAR(100) NOT NULL,
  `credits` FLOAT NOT NULL DEFAULT 1.0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

-- Seed default volunteer credit weights
INSERT INTO `tgg_volunteer_credits` (`id`, `credit_key`, `credit_label`, `credits`) VALUES
(1, 'weekday_open', 'Weekday Open', 1.0),
(2, 'weekday_close', 'Weekday Close', 1.0),
(3, 'sunday_open', 'Sunday Open', 2.0),
(4, 'sunday_close', 'Sunday Close', 2.0),
(5, 'credits_per_month', 'Credits required for 1 month free membership', 4.0),
(6, 'weekday_greeter', 'Weekday Greeter', 0.0),
(7, 'sunday_greeter', 'Sunday Greeter', 0.0),
(8, 'credit_expiration_days', 'Credit Expiration (Days)', 365.0)
ON DUPLICATE KEY UPDATE `credit_label`=VALUES(`credit_label`), `credits`=VALUES(`credits`);

-- 9. Volunteer Credit Transactions Ledger (Single-precision floats)
CREATE TABLE IF NOT EXISTS `tgg_volunteer_credit_transactions` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `contact_id` INT NOT NULL,
  `event_id` INT DEFAULT NULL,
  `volunteer_date` DATE NOT NULL,
  `shift` VARCHAR(50) NOT NULL,
  `credits_earned` FLOAT NOT NULL DEFAULT 0.0,
  `credits_applied` FLOAT NOT NULL DEFAULT 0.0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_contact_shift` (`event_id`,`contact_id`,`shift`),
  KEY `idx_contact_credits` (`contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Email Templates Table
CREATE TABLE IF NOT EXISTS `tgg_email_templates` (
  `template_key` VARCHAR(50) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `description` VARCHAR(255) NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`template_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default email templates
INSERT INTO `tgg_email_templates` (`template_key`, `subject`, `body`, `description`) VALUES
('signup', 'Welcome to TGG Club!', '<h2>Welcome, {display_name}!</h2><p>Thank you for signing up for the TGG Membership Portal. Your account has been registered with the email <strong>{email}</strong>.</p><p>If you have not done so already, please complete your checkout to activate your subscription.</p><p>You can access the portal and complete any pending payments by logging in here: <a href=\"{login_url}\">{login_url}</a></p><p>Best regards,<br>TGG Club Team</p>', 'Welcome email sent immediately after a user registers their account details.'),
('payment_received', 'Receipt: Your TGG Membership is Active!', '<h2>Hello, {display_name}!</h2><p>Thank you for your payment of <strong>${amount}</strong>.</p><p>Your subscription to the <strong>{tier_name}</strong> plan is now active!</p><p><strong>Membership Details:</strong></p><ul><li><strong>Start Date:</strong> {start_date}</li><li><strong>Expiration Date:</strong> {end_date}</li></ul><p>You can now log in to your account dashboard at: <a href=\"{login_url}\">{login_url}</a></p><p>Best regards,<br>TGG Club Team</p>', 'Payment confirmation receipt sent upon successful checkout session completion.'),
('credits_converted', 'Membership Extended: Volunteer Credits Redeemed!', '<h2>Hello, {display_name}!</h2><p>Congratulations! You have successfully redeemed <strong>{credits_used}</strong> volunteer credits.</p><p>As a result, your membership has been extended by <strong>{months_extended} month(s)</strong> free of charge.</p><p>Your new membership expiration date is <strong>{new_end_date}</strong>.</p><p>Thank you for volunteering and contributing your time to the club!</p><p>Best regards,<br>TGG Club Team</p>', 'Notification sent when an admin applies volunteer credits to extend a user\'s membership.'),
('password_reset_link', 'Reset Your TGG Portal Password', '<h2>Password Reset Request</h2><p>Hello, {display_name},</p><p>We received a request to reset the password for your TGG Membership Portal account.</p><p>To reset your password, please click the link below:</p><p><a href=\"{reset_link}\">{reset_link}</a></p><p>You can enter this reset code manually in the app:<br><code>{reset_code}</code></p><p>This code and link are secure and will expire in <strong>{expires_in}</strong>.</p><p>If you did not request a password reset, you can safely ignore this email.</p><p>Best regards,<br>TGG Club Team</p>', 'Sent when a user requests a password reset link.'),
('password_reset_completed', 'Password Reset Successful', '<h2>Password Reset Successful</h2><p>Hello, {display_name},</p><p>Your password for the TGG Membership Portal has been reset successfully.</p><p>You can now log in to the portal using your new password here: <a href=\"{login_url}\">{login_url}</a></p><p>If you did not initiate this password reset, please contact an administrator immediately.</p><p>Best regards,<br>TGG Club Team</p>', 'Sent to confirm that a user has successfully changed their password after a reset request.')
ON DUPLICATE KEY UPDATE `subject`=VALUES(`subject`), `body`=VALUES(`body`), `description`=VALUES(`description`);

-- 11. Password Resets Table
CREATE TABLE IF NOT EXISTS `tgg_password_resets` (
  `email` VARCHAR(255) NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`email`),
  KEY `idx_reset_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Email Log Table
CREATE TABLE IF NOT EXISTS `tgg_email_log` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `recipient_id` INT NULL,
  `sender_id` INT NULL,
  `recipient` VARCHAR(255) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_recipient_id` (`recipient_id`),
  KEY `idx_sender_id` (`sender_id`),
  KEY `idx_sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Contacts Table (Local storage for CiviCRM contact details)
CREATE TABLE IF NOT EXISTS `tgg_contacts` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `contact_type` VARCHAR(64) DEFAULT 'Individual',
  `display_name` VARCHAR(128) NOT NULL,
  `first_name` VARCHAR(64) NULL,
  `last_name` VARCHAR(64) NULL,
  `email` VARCHAR(254) NOT NULL,
  `phone` VARCHAR(32) NULL,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci AUTO_INCREMENT=100000;

-- 14. Membership Statuses Table
CREATE TABLE IF NOT EXISTS `tgg_membership_statuses` (
  `id` INT NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `label` VARCHAR(128) NOT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default membership statuses
INSERT INTO `tgg_membership_statuses` (`id`, `name`, `label`, `is_active`) VALUES
(1, 'New', 'New', 1),
(2, 'Current', 'Current', 1),
(3, 'Grace', 'Grace Period', 1),
(4, 'Expired', 'Expired', 0),
(5, 'Pending', 'Pending', 0)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `label`=VALUES(`label`), `is_active`=VALUES(`is_active`);




