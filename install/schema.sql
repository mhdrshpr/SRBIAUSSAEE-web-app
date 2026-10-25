-- ============================================================
-- SSAEE Panel v2 — Full Database Schema (Fresh Install Only)
-- سازگار با MySQL 5.7+ و MariaDB 10.2+
-- اجرا از طریق phpMyAdmin یا نصب‌کننده (install/)
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- Accounts & Auth
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `role` ENUM('tech_admin','superadmin','cert_admin','members_admin','associations_admin','workshops_admin','banners_admin','classes_admin') NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `telegram_id` BIGINT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` DATETIME NULL DEFAULT NULL,
  `created_by` INT NULL DEFAULT NULL,
  `notes` TEXT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_user_role` (`role`),
  KEY `idx_user_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `user_roles` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `role` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_role` (`user_id`, `role`),
  KEY `idx_ur_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `user_profiles` (
  `ID` INT NOT NULL AUTO_INCREMENT,
  `TelegramID` BIGINT NOT NULL,
  `Phone` VARCHAR(15) NULL DEFAULT NULL,
  `FirstName` VARCHAR(100) NULL DEFAULT NULL,
  `LastName` VARCHAR(100) NULL DEFAULT NULL,
  `FullName` VARCHAR(200) NULL DEFAULT NULL,
  `NationalID` VARCHAR(10) NULL DEFAULT NULL,
  `StudentID` VARCHAR(20) NULL DEFAULT NULL,
  `IsProfileComplete` TINYINT(1) NOT NULL DEFAULT 0,
  `LastLogin` DATETIME NULL DEFAULT NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `unique_telegram` (`TelegramID`),
  KEY `idx_student` (`StudentID`),
  KEY `idx_phone` (`Phone`),
  KEY `idx_national` (`NationalID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `user_sessions` (
  `ID` INT NOT NULL AUTO_INCREMENT,
  `TelegramID` BIGINT NOT NULL,
  `Token` VARCHAR(128) NOT NULL,
  `IP` VARCHAR(45) NULL DEFAULT NULL,
  `UserAgent` TEXT NULL,
  `ExpiresAt` DATETIME NOT NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `unique_token` (`Token`),
  KEY `idx_telegram` (`TelegramID`),
  KEY `idx_expires` (`ExpiresAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `web_users` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(15) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `profile_id` INT NULL DEFAULT NULL,
  `telegram_id` BIGINT NULL DEFAULT NULL,
  `link_code` VARCHAR(16) NULL DEFAULT NULL,
  `link_expires` DATETIME NULL DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `failed_attempts` INT NOT NULL DEFAULT 0,
  `locked_until` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wu_phone` (`phone`),
  KEY `idx_wu_profile` (`profile_id`),
  KEY `idx_wu_telegram` (`telegram_id`),
  KEY `idx_wu_link` (`link_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(15) NOT NULL,
  `token_hash` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pr_phone` (`phone`),
  KEY `idx_pr_token` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ------------------------------------------------------------
-- Security / Logging
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `ip` VARCHAR(45) NOT NULL,
  `username` VARCHAR(100) NOT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_la_ip` (`ip`),
  KEY `idx_la_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `bucket` VARCHAR(50) NOT NULL,
  `identifier` VARCHAR(120) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rl_bucket` (`bucket`, `identifier`),
  KEY `idx_rl_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `admin_id` INT NULL DEFAULT NULL,
  `action` VARCHAR(50) NOT NULL,
  `entity` VARCHAR(50) NOT NULL,
  `entity_id` VARCHAR(50) NULL DEFAULT NULL,
  `details` TEXT NULL,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_al_admin` (`admin_id`),
  KEY `idx_al_entity` (`entity`, `entity_id`),
  KEY `idx_al_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ------------------------------------------------------------
-- Members
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `members` (
  `ID` INT NOT NULL AUTO_INCREMENT,
  `NameFa` VARCHAR(200) NOT NULL,
  `NationalID` VARCHAR(20) NOT NULL,
  `StudentID` VARCHAR(20) NULL DEFAULT NULL,
  `Branch` VARCHAR(100) NULL DEFAULT NULL,
  `Active` TINYINT(1) NOT NULL DEFAULT 0,
  `Points` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `IssueDate` DATE NULL DEFAULT NULL,
  `ExpDate` DATE NULL DEFAULT NULL,
  `FileLink` TEXT NULL,
  `UpgradeReq` TINYINT(1) NOT NULL DEFAULT 0,
  `Phone` VARCHAR(15) NULL DEFAULT NULL,
  `TelegramID` BIGINT NULL DEFAULT NULL,
  `Year` VARCHAR(10) NULL DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `idx_member_national_id` (`NationalID`),
  KEY `idx_member_active` (`Active`),
  KEY `idx_member_student_id` (`StudentID`),
  KEY `idx_member_telegram` (`TelegramID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `membersreq` (
  `ID` INT NOT NULL AUTO_INCREMENT,
  `Date` DATE NOT NULL,
  `Name` VARCHAR(200) NOT NULL,
  `NationalID` VARCHAR(20) NOT NULL,
  `StudentID` VARCHAR(20) NULL DEFAULT NULL,
  `Phone` VARCHAR(15) NULL DEFAULT NULL,
  `TelegramID` BIGINT NULL DEFAULT NULL,
  `YearOfEnter` VARCHAR(10) NULL DEFAULT NULL,
  `Branch` VARCHAR(100) NULL DEFAULT NULL,
  `Status` VARCHAR(50) NOT NULL DEFAULT 'NEW',
  `Introducer` VARCHAR(255) NULL DEFAULT NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_member_req_status` (`Status`),
  KEY `idx_member_req_national_id` (`NationalID`),
  KEY `idx_member_req_phone` (`Phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ------------------------------------------------------------
-- Workshops & Banners
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `workshops` (
  `ID` VARCHAR(50) NOT NULL,
  `Title` VARCHAR(200) NOT NULL,
  `Tag` VARCHAR(50) NOT NULL,
  `Type` VARCHAR(50) NOT NULL DEFAULT 'general',
  `Description` TEXT NULL,
  `GroupLink` VARCHAR(255) NULL DEFAULT NULL,
  `BannerURL` VARCHAR(500) NULL DEFAULT NULL,
  `IsActive` TINYINT(1) NOT NULL DEFAULT 1,
  `StartDate` DATETIME NULL DEFAULT NULL,
  `EndDate` DATETIME NULL DEFAULT NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `unique_tag` (`Tag`),
  KEY `idx_active` (`IsActive`),
  KEY `idx_dates` (`StartDate`, `EndDate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `banners` (
  `ID` VARCHAR(50) NOT NULL,
  `Title` VARCHAR(200) NOT NULL,
  `ImageURL` VARCHAR(500) NULL DEFAULT NULL,
  `LinkURL` VARCHAR(500) NULL DEFAULT NULL,
  `TargetType` ENUM('workshop','external','internal') NOT NULL DEFAULT 'external',
  `TargetTag` VARCHAR(50) NULL DEFAULT NULL,
  `IsActive` TINYINT(1) NOT NULL DEFAULT 1,
  `StartDate` DATETIME NULL DEFAULT NULL,
  `EndDate` DATETIME NULL DEFAULT NULL,
  `ClickCount` INT NOT NULL DEFAULT 0,
  `Priority` INT NOT NULL DEFAULT 0,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_banner_active` (`IsActive`),
  KEY `idx_banner_dates` (`StartDate`, `EndDate`),
  KEY `idx_banner_priority` (`Priority`),
  KEY `idx_banner_tag` (`TargetTag`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `workshop_registrations` (
  `ID` VARCHAR(20) NOT NULL,
  `Date` DATETIME NOT NULL,
  `FullName` VARCHAR(100) NOT NULL,
  `Phone` VARCHAR(11) NOT NULL,
  `StudentID` VARCHAR(20) NOT NULL,
  `University` VARCHAR(200) NULL DEFAULT NULL,
  `Field` VARCHAR(100) NOT NULL,
  `WorkshopType` VARCHAR(50) NOT NULL DEFAULT 'general',
  `WorkshopTag` VARCHAR(50) NULL DEFAULT NULL,
  `Status` ENUM('PENDING','CONFIRMED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  `GroupLink` VARCHAR(255) NULL DEFAULT NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_reg_phone` (`Phone`),
  KEY `idx_reg_student_id` (`StudentID`),
  KEY `idx_reg_status` (`Status`),
  KEY `idx_reg_tag` (`WorkshopTag`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `workshopreq` (
  `ID` INT NOT NULL AUTO_INCREMENT,
  `Date` DATE NOT NULL,
  `Time` TIME NOT NULL,
  `Course` VARCHAR(200) NOT NULL,
  `Teacher` VARCHAR(200) NOT NULL,
  `Phone` VARCHAR(20) NULL DEFAULT NULL,
  `Status` VARCHAR(50) NOT NULL DEFAULT 'NEW',
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_workshopreq_status` (`Status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `classreq` (
  `ID` INT NOT NULL AUTO_INCREMENT,
  `Date` DATE NOT NULL,
  `Time` TIME NOT NULL,
  `Course` VARCHAR(200) NOT NULL,
  `Teacher` VARCHAR(200) NOT NULL,
  `Phone` VARCHAR(20) NULL DEFAULT NULL,
  `Status` VARCHAR(50) NOT NULL DEFAULT 'NEW',
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_classreq_status` (`Status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ------------------------------------------------------------
-- Certificates
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `certificates` (
  `ID` VARCHAR(50) NOT NULL,
  `NameEn` VARCHAR(200) NOT NULL,
  `NameFa` VARCHAR(200) NOT NULL,
  `NationalID` VARCHAR(20) NULL DEFAULT NULL,
  `Course` VARCHAR(200) NOT NULL,
  `CertType` VARCHAR(200) NULL DEFAULT NULL,
  `IssueDate` DATE NOT NULL,
  `ExpDate` DATE NULL DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `idx_cert_national_id` (`NationalID`),
  KEY `idx_cert_exp_date` (`ExpDate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ------------------------------------------------------------
-- Public Relations (Collaborations)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `associations` (
  `ID` INT NOT NULL AUTO_INCREMENT,
  `Date` DATE NOT NULL,
  `Name` VARCHAR(200) NOT NULL,
  `University` VARCHAR(200) NULL DEFAULT NULL,
  `Field` VARCHAR(200) NULL DEFAULT NULL,
  `Course` VARCHAR(200) NULL DEFAULT NULL,
  `Phone` VARCHAR(15) NULL DEFAULT NULL,
  `Status` VARCHAR(50) NOT NULL DEFAULT 'NEW',
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_assoc_status` (`Status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `teachers` (
  `ID` INT NOT NULL AUTO_INCREMENT,
  `Date` DATE NOT NULL,
  `Name` VARCHAR(200) NOT NULL,
  `University` VARCHAR(200) NULL DEFAULT NULL,
  `AcRank` VARCHAR(100) NULL DEFAULT NULL,
  `Course` VARCHAR(200) NULL DEFAULT NULL,
  `Phone` VARCHAR(15) NULL DEFAULT NULL,
  `Status` VARCHAR(50) NOT NULL DEFAULT 'NEW',
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_teachers_status` (`Status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `companies` (
  `ID` INT NOT NULL AUTO_INCREMENT,
  `Date` DATE NOT NULL,
  `Name` VARCHAR(200) NOT NULL,
  `Note` TEXT NULL,
  `Phone` VARCHAR(15) NULL DEFAULT NULL,
  `Status` VARCHAR(50) NOT NULL DEFAULT 'NEW',
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_companies_status` (`Status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `sponsor` (
  `ID` INT NOT NULL AUTO_INCREMENT,
  `Date` DATE NOT NULL,
  `Brand` VARCHAR(200) NOT NULL,
  `Interface` VARCHAR(200) NULL DEFAULT NULL,
  `Type` VARCHAR(100) NULL DEFAULT NULL,
  `Money` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `Details` TEXT NULL,
  `Email` VARCHAR(100) NULL DEFAULT NULL,
  `Phone` VARCHAR(15) NULL DEFAULT NULL,
  `Telegram` VARCHAR(100) NULL DEFAULT NULL,
  `Website` VARCHAR(200) NULL DEFAULT NULL,
  `Note` TEXT NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_sponsor_brand` (`Brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `suggestions` (
  `ID` INT NOT NULL AUTO_INCREMENT,
  `Phone` VARCHAR(15) NOT NULL,
  `Unit` VARCHAR(100) NULL DEFAULT NULL,
  `Subject` VARCHAR(200) NULL DEFAULT NULL,
  `Note` TEXT NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `idx_suggestions_phone` (`Phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ------------------------------------------------------------
-- Activities & Announcements
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `activities` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(200) NOT NULL,
  `act_date` DATE NOT NULL,
  `description` TEXT NULL,
  `image_url` VARCHAR(500) NULL DEFAULT NULL,
  `needs_review` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_act_date` (`act_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ------------------------------------------------------------
-- Trashbin (soft-delete vault — records + moved local files, 30 days)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `trashbin` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `admin_id` INT NULL DEFAULT NULL,
  `table_name` VARCHAR(50) NOT NULL,
  `record_id` VARCHAR(50) NOT NULL,
  `payload` MEDIUMTEXT NULL,
  `file_path` VARCHAR(500) NULL DEFAULT NULL,
  `deleted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tb_table` (`table_name`, `record_id`),
  KEY `idx_tb_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `announcements` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT NOT NULL,
  `type` ENUM('info','warning','danger','success') NOT NULL DEFAULT 'info',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `start_date` DATETIME NULL DEFAULT NULL,
  `end_date` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_an_active` (`is_active`),
  KEY `idx_an_dates` (`start_date`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ------------------------------------------------------------
-- Notifications
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NULL DEFAULT NULL,
  `telegram_id` BIGINT NULL DEFAULT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `type` VARCHAR(50) NOT NULL DEFAULT 'info',
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_telegram` (`telegram_id`),
  KEY `idx_notif_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ------------------------------------------------------------
-- Telegram Bot
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `bot_admins` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `telegram_id` BIGINT NOT NULL,
  `role` ENUM('admin','support') NOT NULL DEFAULT 'support',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_telegram` (`telegram_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `bot_user_states` (
  `telegram_id` BIGINT NOT NULL,
  `state` VARCHAR(50) NULL DEFAULT NULL,
  `data` TEXT NULL,
  `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`telegram_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `bot_support_sessions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_telegram_id` BIGINT NOT NULL,
  `user_name` VARCHAR(255) NULL DEFAULT NULL,
  `status` ENUM('open','closed') NOT NULL DEFAULT 'open',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bss_user` (`user_telegram_id`),
  KEY `idx_bss_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `bot_messages` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `session_id` INT NULL DEFAULT NULL,
  `from_telegram_id` BIGINT NOT NULL,
  `to_telegram_id` BIGINT NULL DEFAULT NULL,
  `message` TEXT NOT NULL,
  `direction` ENUM('user_to_admin','admin_to_user') NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bm_session` (`session_id`),
  KEY `idx_bm_from` (`from_telegram_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ------------------------------------------------------------
-- Class group links (current semester)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `class_links` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `course_name` VARCHAR(200) NOT NULL,
  `teacher` VARCHAR(200) NOT NULL,
  `class_day` VARCHAR(20) NULL DEFAULT NULL,
  `class_time` VARCHAR(20) NULL DEFAULT NULL,
  `link` VARCHAR(500) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cl_course` (`course_name`),
  KEY `idx_cl_teacher` (`teacher`),
  KEY `idx_cl_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ------------------------------------------------------------
-- Board members (معرفی اعضای هیئت مدیره — بر اساس دوره)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `board_members` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `position` VARCHAR(200) NULL DEFAULT NULL,
  `term` VARCHAR(50) NOT NULL DEFAULT '',
  `start_date` DATE NULL DEFAULT NULL,
  `end_date` DATE NULL DEFAULT NULL,
  `is_current` TINYINT(1) NOT NULL DEFAULT 1,
  `telegram` VARCHAR(100) NULL DEFAULT NULL,
  `linkedin` VARCHAR(300) NULL DEFAULT NULL,
  `photo` VARCHAR(300) NULL DEFAULT NULL,
  `sort` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bm_term` (`term`),
  KEY `idx_bm_current` (`is_current`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ------------------------------------------------------------
-- Workshop satisfaction surveys
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `workshop_surveys` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `registration_id` VARCHAR(20) NOT NULL,
  `chat_id` BIGINT NOT NULL,
  `workshop_tag` VARCHAR(50) NULL DEFAULT NULL,
  `rating` TINYINT NULL DEFAULT NULL,
  `feedback_text` TEXT NULL,
  `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `answered_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ws_reg` (`registration_id`),
  KEY `idx_ws_rating` (`rating`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `workshop_sessions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `workshop_tag` VARCHAR(50) NOT NULL,
  `session_number` INT NOT NULL DEFAULT 1,
  `session_date` DATE NOT NULL,
  `session_time` TIME NOT NULL,
  `reminder_2h_sent` TINYINT(1) NOT NULL DEFAULT 0,
  `reminder_75m_sent` TINYINT(1) NOT NULL DEFAULT 0,
  `survey_sent` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ws_tag` (`workshop_tag`),
  KEY `idx_ws_date` (`session_date`, `session_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ------------------------------------------------------------
-- Certificate template (auto-generation)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `cert_template_fields` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `field_key` VARCHAR(50) NOT NULL,
  `label` VARCHAR(100) NOT NULL,
  `x_pos` INT NOT NULL DEFAULT 0,
  `y_pos` INT NOT NULL DEFAULT 0,
  `font_size` INT NOT NULL DEFAULT 24,
  `width` INT NOT NULL DEFAULT 0,
  `font_color` VARCHAR(7) NOT NULL DEFAULT '#000000',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_field_key` (`field_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

INSERT IGNORE INTO `cert_template_fields` (`field_key`, `label`, `x_pos`, `y_pos`, `font_size`, `width`, `font_color`) VALUES
('name_fa', 'نام و نام خانوادگی', 400, 280, 56, 0, '#1a1a1a'),
('name_en', 'نام انگلیسی', 400, 320, 44, 0, '#333333'),
('national_id', 'کد ملی', 400, 360, 44, 0, '#333333'),
('cert_id', 'شماره گواهینامه', 400, 400, 40, 0, '#666666'),
('course', 'نام دوره', 400, 440, 44, 0, '#333333'),
('issue_date', 'تاریخ صدور', 400, 480, 40, 0, '#666666'),
('president', 'دبیر انجمن', 400, 520, 44, 0, '#1a1a1a'),
('custom_text', 'توضیح/نوع گواهی نامه', 400, 560, 36, 0, '#666666'),
('signature', 'مهر / امضای دبیر', 900, 520, 0, 160, '#000000');

-- ------------------------------------------------------------
-- Activity snapshots (30-day rollback system)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `activity_snapshots` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `admin_id` INT NULL DEFAULT NULL,
  `table_name` VARCHAR(50) NOT NULL,
  `record_id` VARCHAR(50) NOT NULL,
  `snapshot_data` JSON NOT NULL,
  `action_type` ENUM('delete','update','create') NOT NULL DEFAULT 'delete',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL DEFAULT DATE_ADD(NOW(), INTERVAL 30 DAY),
  PRIMARY KEY (`id`),
  KEY `idx_as_table` (`table_name`, `record_id`),
  KEY `idx_as_expires` (`expires_at`),
  KEY `idx_as_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ------------------------------------------------------------
-- Settings
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('satisfaction_rate', '85'),
('researchers_club_score', '0'),
('bot_username', ''),
('maintenance_mode', '0'),
('maintenance_message', 'سایت در حال بروزرسانی است. لطفاً بعداً مراجعه کنید.');
