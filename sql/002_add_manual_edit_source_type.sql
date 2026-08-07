-- Migration: allow floorplan_versions.source_type to record direct manual edits
-- (e.g. using the Eraser tool), not just uploads and AI-generated versions.
-- Run this once against your existing database via phpMyAdmin (or mysql CLI) —
-- fresh installs using sql/schema.sql already include this value.

ALTER TABLE floorplan_versions
  MODIFY source_type ENUM('upload','ai_generated','manual_edit') NOT NULL;
