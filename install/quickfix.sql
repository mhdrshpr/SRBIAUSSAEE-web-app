-- SSAEE Panel v2 — Quick Fix: Add missing columns
-- Run this in phpMyAdmin to update your existing database

-- Add must_setup column to users
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `must_setup` TINYINT(1) NOT NULL DEFAULT 0;

-- Add start_date and end_date to board_members (if not exists)
ALTER TABLE `board_members`
    ADD COLUMN IF NOT EXISTS `start_date` DATE NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `end_date` DATE NULL DEFAULT NULL;

-- Add University to workshop_registrations (if not exists)
ALTER TABLE `workshop_registrations`
    ADD COLUMN IF NOT EXISTS `University` VARCHAR(200) NULL DEFAULT NULL AFTER `StudentID`;

-- Add tech_admin to users role enum
ALTER TABLE `users`
    MODIFY `role` ENUM('tech_admin','superadmin','cert_admin','members_admin','associations_admin','workshops_admin','banners_admin','classes_admin') NOT NULL;

-- Add link_code and link_expires to web_users
ALTER TABLE `web_users`
    ADD COLUMN IF NOT EXISTS `link_code` VARCHAR(16) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `link_expires` DATETIME NULL DEFAULT NULL;