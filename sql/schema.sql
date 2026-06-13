-- MariaDB Database Schema for Simple CiviCRM Member Tracking (Local App Tables)
-- These tables support tracking check-ins, events, volunteer signups, and password/settings info.
-- Contact details, memberships, and contributions are stored in CiviCRM tables.

CREATE DATABASE IF NOT EXISTS `tgg_membership` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `tgg_membership`;

-- 1. Member settings and credentials (links to civicrm_contact)
CREATE TABLE IF NOT EXISTS `tgg_member_settings` (
  `contact_id` INT NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` VARCHAR(50) NOT NULL DEFAULT 'member', -- 'admin' or 'member'
  `custom_display_name` VARCHAR(255) NULL,
  `is_profile_public` TINYINT(1) NOT NULL DEFAULT 1, -- 0 = Private, 1 = Public
  `public_fields` TEXT NULL, -- JSON formatted array of fields allowed to be public (e.g. ["display_name", "join_date"])
  `credits_earned` FLOAT NOT NULL DEFAULT 0.0,
  `credits_applied` FLOAT NOT NULL DEFAULT 0.0,
  `expired_credits` FLOAT NOT NULL DEFAULT 0.0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`contact_id`)
) ENGINE=InnoDB;

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
  UNIQUE KEY `uq_event_contact` (`event_id`, `contact_id`),
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


