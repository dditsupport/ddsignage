-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jul 07, 2026 at 05:48 PM
-- Server version: 10.11.18-MariaDB-cll-lve
-- PHP Version: 8.4.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `gtvpheud_DangeeCast`
--

-- --------------------------------------------------------

--
-- Table structure for table `apk_versions`
--

CREATE TABLE `apk_versions` (
  `id` int(10) UNSIGNED NOT NULL,
  `version_name` varchar(40) NOT NULL,
  `version_code` int(10) UNSIGNED NOT NULL,
  `apk_url` varchar(500) NOT NULL,
  `release_notes` text DEFAULT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 0,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cities`
--

CREATE TABLE `cities` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crashes`
--

CREATE TABLE `crashes` (
  `id` int(10) UNSIGNED NOT NULL,
  `device_id` int(10) UNSIGNED DEFAULT NULL,
  `android_id` varchar(64) DEFAULT NULL,
  `app_version` varchar(40) DEFAULT NULL,
  `android_version` varchar(40) DEFAULT NULL,
  `device_model` varchar(120) DEFAULT NULL,
  `stack_trace` mediumtext NOT NULL,
  `device_timestamp_ms` bigint(20) UNSIGNED DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `devices`
--

CREATE TABLE `devices` (
  `id` int(10) UNSIGNED NOT NULL,
  `android_id` varchar(64) NOT NULL,
  `mac_address` varchar(32) DEFAULT NULL,
  `store_id` int(10) UNSIGNED DEFAULT NULL,
  `display_name` varchar(160) DEFAULT NULL,
  `app_version` varchar(40) DEFAULT NULL,
  `device_model` varchar(120) DEFAULT NULL,
  `android_version` varchar(40) DEFAULT NULL,
  `screen_width` int(10) UNSIGNED DEFAULT NULL,
  `screen_height` int(10) UNSIGNED DEFAULT NULL,
  `storage_free_bytes` bigint(20) UNSIGNED DEFAULT NULL,
  `storage_total_bytes` bigint(20) UNSIGNED DEFAULT NULL,
  `stats_reported_at` datetime DEFAULT NULL,
  `media_cached_count` int(10) UNSIGNED DEFAULT NULL,
  `media_total_count` int(10) UNSIGNED DEFAULT NULL,
  `cache_status_at` datetime DEFAULT NULL,
  `last_seen` datetime DEFAULT NULL,
  `last_ip` varchar(45) DEFAULT NULL,
  `assigned` tinyint(1) NOT NULL DEFAULT 0,
  `force_refresh` tinyint(1) NOT NULL DEFAULT 0,
  `poll_interval_seconds` int(10) UNSIGNED NOT NULL DEFAULT 300,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `device_bg_audio`
--

CREATE TABLE `device_bg_audio` (
  `device_id` int(10) UNSIGNED NOT NULL,
  `weekday` enum('monday','tuesday','wednesday','thursday','friday','saturday','sunday') NOT NULL,
  `audio_media_id` int(10) UNSIGNED DEFAULT NULL,
  `volume` decimal(3,2) NOT NULL DEFAULT 1.00,
  `video_duck_volume` decimal(3,2) NOT NULL DEFAULT 0.20,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `device_bg_audio_items`
--

CREATE TABLE `device_bg_audio_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `device_id` int(10) UNSIGNED NOT NULL,
  `weekday` enum('monday','tuesday','wednesday','thursday','friday','saturday','sunday') NOT NULL,
  `audio_media_id` int(10) UNSIGNED NOT NULL,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `device_logs`
--

CREATE TABLE `device_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `device_id` int(10) UNSIGNED NOT NULL,
  `event_type` varchar(40) NOT NULL,
  `details` text DEFAULT NULL,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `media`
--

CREATE TABLE `media` (
  `id` int(10) UNSIGNED NOT NULL,
  `filename` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_hash` char(64) NOT NULL,
  `mime_type` varchar(120) NOT NULL,
  `file_type` enum('image','video','audio') NOT NULL,
  `file_size_bytes` bigint(20) UNSIGNED NOT NULL,
  `duration_seconds` int(10) UNSIGNED DEFAULT NULL,
  `uploaded_by` varchar(80) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `playlist_items`
--

CREATE TABLE `playlist_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `device_id` int(10) UNSIGNED NOT NULL,
  `weekday` enum('monday','tuesday','wednesday','thursday','friday','saturday','sunday') NOT NULL,
  `media_id` int(10) UNSIGNED NOT NULL,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `start_hour` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `end_hour` tinyint(3) UNSIGNED NOT NULL DEFAULT 24,
  `duration_seconds` int(10) UNSIGNED NOT NULL DEFAULT 10,
  `audio_media_id` int(10) UNSIGNED DEFAULT NULL,
  `video_volume` decimal(3,2) DEFAULT NULL,
  `audio_volume` decimal(3,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `playlist_triggers`
--

CREATE TABLE `playlist_triggers` (
  `id` int(10) UNSIGNED NOT NULL,
  `device_id` int(10) UNSIGNED NOT NULL,
  `weekday` enum('monday','tuesday','wednesday','thursday','friday','saturday','sunday') NOT NULL,
  `trigger_time` time NOT NULL,
  `media_id` int(10) UNSIGNED NOT NULL,
  `volume` decimal(3,2) NOT NULL DEFAULT 1.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stores`
--

CREATE TABLE `stores` (
  `id` int(10) UNSIGNED NOT NULL,
  `city_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(160) NOT NULL,
  `address` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `apk_versions`
--
ALTER TABLE `apk_versions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_apk_version_code` (`version_code`),
  ADD KEY `idx_apk_current` (`is_current`);

--
-- Indexes for table `cities`
--
ALTER TABLE `cities`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_cities_name` (`name`);

--
-- Indexes for table `crashes`
--
ALTER TABLE `crashes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_crashes_device` (`device_id`),
  ADD KEY `idx_crashes_android_id` (`android_id`),
  ADD KEY `idx_crashes_received` (`received_at`);

--
-- Indexes for table `devices`
--
ALTER TABLE `devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_devices_android_id` (`android_id`),
  ADD KEY `idx_devices_store` (`store_id`),
  ADD KEY `idx_devices_last_seen` (`last_seen`);

--
-- Indexes for table `device_bg_audio`
--
ALTER TABLE `device_bg_audio`
  ADD PRIMARY KEY (`device_id`,`weekday`),
  ADD KEY `idx_dba_audio` (`audio_media_id`);

--
-- Indexes for table `device_bg_audio_items`
--
ALTER TABLE `device_bg_audio_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dbai_device_weekday_sort` (`device_id`,`weekday`,`sort_order`),
  ADD KEY `idx_dbai_audio` (`audio_media_id`);

--
-- Indexes for table `device_logs`
--
ALTER TABLE `device_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dl_device_time` (`device_id`,`timestamp`),
  ADD KEY `idx_dl_event_time` (`event_type`,`timestamp`);

--
-- Indexes for table `media`
--
ALTER TABLE `media`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_media_filename` (`filename`),
  ADD KEY `idx_media_hash` (`file_hash`),
  ADD KEY `idx_media_type` (`file_type`);

--
-- Indexes for table `playlist_items`
--
ALTER TABLE `playlist_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pi_device_weekday_sort` (`device_id`,`weekday`,`sort_order`),
  ADD KEY `idx_pi_media` (`media_id`),
  ADD KEY `idx_pi_audio` (`audio_media_id`);

--
-- Indexes for table `playlist_triggers`
--
ALTER TABLE `playlist_triggers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pt_device_weekday_time` (`device_id`,`weekday`,`trigger_time`),
  ADD KEY `idx_pt_media` (`media_id`);

--
-- Indexes for table `stores`
--
ALTER TABLE `stores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_stores_city_name` (`city_id`,`name`),
  ADD KEY `idx_stores_city` (`city_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `apk_versions`
--
ALTER TABLE `apk_versions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cities`
--
ALTER TABLE `cities`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crashes`
--
ALTER TABLE `crashes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `devices`
--
ALTER TABLE `devices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `device_bg_audio_items`
--
ALTER TABLE `device_bg_audio_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `device_logs`
--
ALTER TABLE `device_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `media`
--
ALTER TABLE `media`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `playlist_items`
--
ALTER TABLE `playlist_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `playlist_triggers`
--
ALTER TABLE `playlist_triggers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stores`
--
ALTER TABLE `stores`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `crashes`
--
ALTER TABLE `crashes`
  ADD CONSTRAINT `fk_crashes_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `devices`
--
ALTER TABLE `devices`
  ADD CONSTRAINT `fk_devices_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `device_bg_audio`
--
ALTER TABLE `device_bg_audio`
  ADD CONSTRAINT `fk_dba_audio` FOREIGN KEY (`audio_media_id`) REFERENCES `media` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_dba_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `device_bg_audio_items`
--
ALTER TABLE `device_bg_audio_items`
  ADD CONSTRAINT `fk_dbai_audio` FOREIGN KEY (`audio_media_id`) REFERENCES `media` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_dbai_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `device_logs`
--
ALTER TABLE `device_logs`
  ADD CONSTRAINT `fk_dl_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `playlist_items`
--
ALTER TABLE `playlist_items`
  ADD CONSTRAINT `fk_pi_audio` FOREIGN KEY (`audio_media_id`) REFERENCES `media` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pi_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pi_media` FOREIGN KEY (`media_id`) REFERENCES `media` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `playlist_triggers`
--
ALTER TABLE `playlist_triggers`
  ADD CONSTRAINT `fk_pt_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pt_media` FOREIGN KEY (`media_id`) REFERENCES `media` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stores`
--
ALTER TABLE `stores`
  ADD CONSTRAINT `fk_stores_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
