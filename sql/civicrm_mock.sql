-- Mock CiviCRM schema for testing the integration on local MariaDB databases.
-- This creates a mock "wordpress_civicrm" database to simulate the live environment.

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
CREATE TABLE IF NOT EXISTS `civicrm_membership_status` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `label` VARCHAR(128) NOT NULL,
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
  PRIMARY KEY (`id`),
  KEY `idx_contact_contribution` (`contact_id`)
) ENGINE=InnoDB;

-- Seed Data for Testing
INSERT INTO `civicrm_membership_type` (`id`, `name`, `description`, `minimum_fee`, `duration_unit`, `duration_interval`) VALUES
(1, 'Associate', 'Associate membership level', 10.00, 'year', 1),
(2, 'Monthly', 'Monthly membership subscription', 30.00, 'month', 1),
(3, 'Annual', 'Annual membership subscription', 200.00, 'year', 1),
(4, 'Trial', 'One-time 30-day free trial membership. Not renewable; limited to one trial per person.', 0.00, 'day', 30);

INSERT INTO `civicrm_membership_status` (`id`, `name`, `label`, `is_active`) VALUES
(1, 'New', 'New', 1),
(2, 'Current', 'Current', 1),
(3, 'Grace', 'Grace Period', 1),
(4, 'Expired', 'Expired', 0),
(5, 'Pending', 'Pending', 0);

-- Seed some mock members (password hash is 'password' for all, hashed below using local seed)
-- Contact 1: Admin
INSERT INTO `civicrm_contact` (`id`, `display_name`, `first_name`, `last_name`) VALUES (1, 'Jane Admin', 'Jane', 'Admin');
INSERT INTO `civicrm_email` (`contact_id`, `email`) VALUES (1, 'admin@example.com');
INSERT INTO `civicrm_phone` (`contact_id`, `phone`) VALUES (1, '555-0101');

-- Contact 2: Active Member
INSERT INTO `civicrm_contact` (`id`, `display_name`, `first_name`, `last_name`) VALUES (2, 'Bob Active', 'Bob', 'Active');
INSERT INTO `civicrm_email` (`contact_id`, `email`) VALUES (2, 'bob@example.com');
INSERT INTO `civicrm_phone` (`contact_id`, `phone`) VALUES (2, '555-0102');
INSERT INTO `civicrm_membership` (`contact_id`, `membership_type_id`, `join_date`, `start_date`, `end_date`, `status_id`) VALUES
(2, 3, '2025-01-15', '2026-01-15', '2027-01-15', 2); -- Current (Annual)

-- Contact 3: Expired Member
INSERT INTO `civicrm_contact` (`id`, `display_name`, `first_name`, `last_name`) VALUES (3, 'Alice Expired', 'Alice', 'Expired');
INSERT INTO `civicrm_email` (`contact_id`, `email`) VALUES (3, 'alice@example.com');
INSERT INTO `civicrm_phone` (`contact_id`, `phone`) VALUES (3, '555-0103');
INSERT INTO `civicrm_membership` (`contact_id`, `membership_type_id`, `join_date`, `start_date`, `end_date`, `status_id`) VALUES
(3, 2, '2025-05-01', '2025-05-01', '2026-05-01', 4); -- Expired (Monthly)
