-- Mock CiviCRM schema for testing the integration on local MariaDB databases.
-- This creates a mock "wordpress_civicrm" database to simulate the live environment.
--
-- Column choices here are deliberately kept close to real CiviCRM semantics (rather than a
-- simplified/convenient shape) for the tables that matter to CiviCRMImporter's correctness --
-- see civicrm_membership_status.is_active vs is_current_member below in particular.

CREATE DATABASE IF NOT EXISTS `wordpress_civicrm` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `wordpress_civicrm`;

-- 1. Contact Table
CREATE TABLE IF NOT EXISTS `civicrm_contact` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `contact_type` VARCHAR(64) DEFAULT 'Individual',
  `display_name` VARCHAR(128) NOT NULL,
  `first_name` VARCHAR(64) NULL,
  `last_name` VARCHAR(64) NULL,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `is_opt_out` TINYINT(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

-- 2. Email Table
CREATE TABLE IF NOT EXISTS `civicrm_email` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `contact_id` INT NOT NULL,
  `email` VARCHAR(254) NOT NULL,
  `is_primary` TINYINT(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_contact_email` (`contact_id`)
) ENGINE=InnoDB;

-- 3. Phone Table
CREATE TABLE IF NOT EXISTS `civicrm_phone` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `contact_id` INT NOT NULL,
  `phone` VARCHAR(32) NOT NULL,
  `is_primary` TINYINT(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_contact_phone` (`contact_id`)
) ENGINE=InnoDB;

-- 4. Membership Type Table
CREATE TABLE IF NOT EXISTS `civicrm_membership_type` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `description` VARCHAR(255) NULL,
  `minimum_fee` DECIMAL(20,2) NOT NULL DEFAULT 0.00,
  `duration_unit` VARCHAR(8) NOT NULL DEFAULT 'year', -- 'month' or 'year'
  `duration_interval` INT NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

-- 5. Membership Status Table
-- Real CiviCRM semantics (verified directly against a production export): `is_active` only means
-- "this status option is enabled/selectable in the admin UI" -- it's 1 for New/Current/Grace/
-- Expired/Pending/Cancelled/Deceased alike. `is_current_member` is the column that actually means
-- "does this status represent a currently-valid membership" (0 for Expired/Pending/Cancelled/
-- Deceased). CiviCRMImporter::runSync() keys off is_current_member for exactly this reason.
CREATE TABLE IF NOT EXISTS `civicrm_membership_status` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `label` VARCHAR(128) NOT NULL,
  `is_current_member` TINYINT(1) DEFAULT 0,
  `is_active` TINYINT(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

-- 6. Membership Table
CREATE TABLE IF NOT EXISTS `civicrm_membership` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `contact_id` INT NOT NULL,
  `membership_type_id` INT NOT NULL,
  `join_date` DATE NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `status_id` INT NOT NULL,
  `contribution_recur_id` INT NULL, -- FK to civicrm_contribution_recur.id; NULL if never on recurring billing
  `is_test` TINYINT(1) NOT NULL DEFAULT 0, -- CiviCRM's own test-mode flag; CiviCRMImporter excludes these
  PRIMARY KEY (`id`),
  KEY `idx_contact_membership` (`contact_id`)
) ENGINE=InnoDB;

-- 7. Contribution (Payments) Table
CREATE TABLE IF NOT EXISTS `civicrm_contribution` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `contact_id` INT NOT NULL,
  `financial_type_id` INT NOT NULL DEFAULT 1, -- Member dues
  `receive_date` DATETIME NOT NULL,
  `total_amount` DECIMAL(20,2) NOT NULL,
  `trxn_id` VARCHAR(255) NULL, -- Stripe payment intent ID
  `contribution_status_id` INT NOT NULL DEFAULT 1, -- 1 = Completed
  `is_test` TINYINT(1) NOT NULL DEFAULT 0, -- CiviCRM's own test-mode flag; CiviCRMImporter excludes these
  PRIMARY KEY (`id`),
  KEY `idx_contact_contribution` (`contact_id`)
) ENGINE=InnoDB;

-- 8. Payment Processor Table (minimal -- just enough for is_test filtering, no real credentials)
CREATE TABLE IF NOT EXISTS `civicrm_payment_processor` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `name` VARCHAR(64) NOT NULL,
  `is_test` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

-- 9. Recurring Contribution Table (CiviCRM's own record of a contact being set up for recurring
-- billing, e.g. via a Stripe Subscription). `contribution_status_id` here follows the same
-- CiviCRM-wide option values as civicrm_contribution.contribution_status_id: 3 = Cancelled,
-- 5 = In Progress (currently active).
CREATE TABLE IF NOT EXISTS `civicrm_contribution_recur` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `contact_id` INT NOT NULL,
  `amount` DECIMAL(20,2) NOT NULL,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'USD',
  `cancel_date` DATETIME NULL,
  `trxn_id` VARCHAR(255) NULL, -- Stripe Subscription ID (e.g. 'sub_...')
  `invoice_id` VARCHAR(255) NULL,
  `contribution_status_id` INT NOT NULL DEFAULT 5,
  `auto_renew` TINYINT(1) NOT NULL DEFAULT 1,
  `payment_processor_id` INT NULL, -- FK to civicrm_payment_processor.id
  PRIMARY KEY (`id`),
  KEY `idx_contact_contribution_recur` (`contact_id`)
) ENGINE=InnoDB;

-- 10. Stripe Customers Table (CiviCRM Stripe extension's own contact-to-Stripe-Customer map --
-- the only place a reusable Stripe Customer ID lives; CiviCRM's generic civicrm_payment_token
-- vault was never populated in the real data this mirrors, so it's intentionally not modeled here).
CREATE TABLE IF NOT EXISTS `civicrm_stripe_customers` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `customer_id` VARCHAR(255) NULL, -- Stripe Customer ID (e.g. 'cus_...')
  `contact_id` INT NULL,
  `processor_id` INT NULL, -- FK to civicrm_payment_processor.id
  `currency` VARCHAR(3) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_contact_stripe_customer` (`contact_id`)
) ENGINE=InnoDB;

-- 11. Membership Payment link table -- ties a specific contribution to the specific membership
-- it paid for. CiviCRMImporter uses this (not the payment amount) to determine which plan a
-- historical payment was actually for, since a member's paid amount can differ from a plan's
-- current price (grandfathered rates).
CREATE TABLE IF NOT EXISTS `civicrm_membership_payment` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `membership_id` INT NOT NULL,
  `contribution_id` INT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_membership_payment_membership` (`membership_id`),
  KEY `idx_membership_payment_contribution` (`contribution_id`)
) ENGINE=InnoDB;

-- Seed Data for Testing
INSERT INTO `civicrm_membership_type` (`id`, `name`, `description`, `minimum_fee`, `duration_unit`, `duration_interval`) VALUES
(1, 'Associate', 'Associate membership level', 10.00, 'year', 1),
(2, 'Monthly', 'Monthly membership subscription', 30.00, 'month', 1),
(3, 'Annual', 'Annual membership subscription', 200.00, 'year', 1),
(4, 'Trial', 'One-time 30-day free trial membership. Not renewable; limited to one trial per person.', 0.00, 'day', 30);

INSERT INTO `civicrm_membership_status` (`id`, `name`, `label`, `is_current_member`, `is_active`) VALUES
(1, 'New', 'New', 1, 1),
(2, 'Current', 'Current', 1, 1),
(3, 'Grace', 'Grace Period', 1, 1),
(4, 'Expired', 'Expired', 0, 1),
(5, 'Pending', 'Pending', 0, 1),
(6, 'Cancelled', 'Cancelled', 0, 1);

INSERT INTO `civicrm_payment_processor` (`id`, `name`, `is_test`) VALUES
(1, 'Stripe Test', 1),
(2, 'Stripe Live', 0);

-- Seed some mock members (password hash is 'password' for all, hashed below using local seed)
-- Contact 1: Admin
INSERT INTO `civicrm_contact` (`id`, `display_name`, `first_name`, `last_name`) VALUES (1, 'Jane Admin', 'Jane', 'Admin');
INSERT INTO `civicrm_email` (`contact_id`, `email`) VALUES (1, 'admin@example.com');
INSERT INTO `civicrm_phone` (`contact_id`, `phone`) VALUES (1, '555-0101');

-- Contact 2: Active Member -- has a currently-active recurring Stripe subscription and a card on
-- file, demonstrating the full auto-renew backfill path.
INSERT INTO `civicrm_contact` (`id`, `display_name`, `first_name`, `last_name`) VALUES (2, 'Bob Active', 'Bob', 'Active');
INSERT INTO `civicrm_email` (`contact_id`, `email`) VALUES (2, 'bob@example.com');
INSERT INTO `civicrm_phone` (`contact_id`, `phone`) VALUES (2, '555-0102');
INSERT INTO `civicrm_contribution_recur` (`id`, `contact_id`, `amount`, `currency`, `cancel_date`, `trxn_id`, `invoice_id`, `contribution_status_id`, `auto_renew`, `payment_processor_id`) VALUES
(1, 2, 200.00, 'USD', NULL, 'sub_mock_bob_active', 'inv_mock_bob_active', 5, 1, 2);
INSERT INTO `civicrm_membership` (`id`, `contact_id`, `membership_type_id`, `join_date`, `start_date`, `end_date`, `status_id`, `contribution_recur_id`) VALUES
(1, 2, 3, '2025-01-15', '2026-01-15', '2027-01-15', 2, 1); -- Current (Annual), tied to recur #1
INSERT INTO `civicrm_stripe_customers` (`customer_id`, `contact_id`, `processor_id`, `currency`) VALUES
('cus_mock_bob', 2, 2, 'usd');
INSERT INTO `civicrm_contribution` (`id`, `contact_id`, `receive_date`, `total_amount`, `trxn_id`, `contribution_status_id`) VALUES
(1, 2, '2026-01-15 10:00:00', 200.00, 'pi_mock_bob_annual', 1);
INSERT INTO `civicrm_membership_payment` (`membership_id`, `contribution_id`) VALUES (1, 1);

-- Contact 3: Expired Member (opted out of bulk email) -- her recurring subscription was canceled
-- and her membership subsequently expired. Also the regression case for the is_active vs
-- is_current_member bug: real CiviCRM data has is_active=1 for 'Expired' too, so this contact
-- only imports as status='expired' correctly if the importer keys off is_current_member.
INSERT INTO `civicrm_contact` (`id`, `display_name`, `first_name`, `last_name`, `is_opt_out`) VALUES (3, 'Alice Expired', 'Alice', 'Expired', 1);
INSERT INTO `civicrm_email` (`contact_id`, `email`) VALUES (3, 'alice@example.com');
INSERT INTO `civicrm_phone` (`contact_id`, `phone`) VALUES (3, '555-0103');
INSERT INTO `civicrm_contribution_recur` (`id`, `contact_id`, `amount`, `currency`, `cancel_date`, `trxn_id`, `invoice_id`, `contribution_status_id`, `auto_renew`, `payment_processor_id`) VALUES
(2, 3, 30.00, 'USD', '2025-11-01 10:00:00', 'sub_mock_alice_canceled', 'inv_mock_alice_canceled', 3, 1, 2);
INSERT INTO `civicrm_membership` (`id`, `contact_id`, `membership_type_id`, `join_date`, `start_date`, `end_date`, `status_id`, `contribution_recur_id`) VALUES
(2, 3, 2, '2025-05-01', '2025-05-01', '2026-05-01', 4, 2); -- Expired (Monthly), tied to canceled recur #2
INSERT INTO `civicrm_stripe_customers` (`customer_id`, `contact_id`, `processor_id`, `currency`) VALUES
('cus_mock_alice', 3, 2, 'usd');
INSERT INTO `civicrm_contribution` (`id`, `contact_id`, `receive_date`, `total_amount`, `trxn_id`, `contribution_status_id`) VALUES
(2, 3, '2025-05-01 10:00:00', 30.00, 'pi_mock_alice_monthly', 1);
INSERT INTO `civicrm_membership_payment` (`membership_id`, `contribution_id`) VALUES (2, 2);

-- Contact 4: One-off payer -- paid once via Stripe (so has a Customer object) but never set up
-- CiviCRM-native recurring billing. Demonstrates the backfill resolving a payment method directly
-- from the Stripe Customer, with no civicrm_contribution_recur row involved at all.
INSERT INTO `civicrm_contact` (`id`, `display_name`, `first_name`, `last_name`) VALUES (4, 'Carol OneOff', 'Carol', 'OneOff');
INSERT INTO `civicrm_email` (`contact_id`, `email`) VALUES (4, 'carol@example.com');
INSERT INTO `civicrm_phone` (`contact_id`, `phone`) VALUES (4, '555-0104');
INSERT INTO `civicrm_membership` (`id`, `contact_id`, `membership_type_id`, `join_date`, `start_date`, `end_date`, `status_id`) VALUES
(3, 4, 1, '2026-02-01', '2026-02-01', '2027-02-01', 2); -- Current (Associate), no recurring ever set up
INSERT INTO `civicrm_contribution` (`id`, `contact_id`, `receive_date`, `total_amount`, `trxn_id`, `contribution_status_id`) VALUES
(3, 4, '2026-02-01 09:00:00', 10.00, 'pi_mock_carol_oneoff', 1);
INSERT INTO `civicrm_membership_payment` (`membership_id`, `contribution_id`) VALUES (3, 3);
INSERT INTO `civicrm_stripe_customers` (`customer_id`, `contact_id`, `processor_id`, `currency`) VALUES
('cus_mock_carol', 4, 2, 'usd');
