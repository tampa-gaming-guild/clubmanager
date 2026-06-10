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
  `is_profile_public` TINYINT(1) NOT NULL DEFAULT 1, -- 0 = Private, 1 = Public
  `public_fields` TEXT NULL, -- JSON formatted array of fields allowed to be public (e.g. ["display_name", "join_date"])
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
  `role` VARCHAR(100) NOT NULL, -- e.g., 'Setup Crew', 'Greeter', 'Clean Up'
  `signed_up_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_contact` (`event_id`, `contact_id`),
  CONSTRAINT `fk_volunteer_event` FOREIGN KEY (`event_id`) REFERENCES `tgg_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
