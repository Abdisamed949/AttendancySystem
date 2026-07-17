-- =====================================================================
-- Migration: users.photo_path (Profile photo upload)
--
-- Adds:
--   - users.photo_path VARCHAR(255) NULL
--
-- Stores just the filename (not a full path) of an uploaded profile
-- photo, saved under uploads/profile_photos/ with a randomized name
-- (never the client-supplied filename). NULL = no photo uploaded yet,
-- the initials-circle avatar is used instead. Nothing else in the
-- schema references this column.
--
-- Run once against the existing admas_attendance database.
-- =====================================================================
USE admas_attendance;

ALTER TABLE users
  ADD COLUMN photo_path VARCHAR(255) NULL AFTER email;
