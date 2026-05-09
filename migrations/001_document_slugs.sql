-- 001_document_slugs.sql
--
-- Adds an internal, stable, opaque handle (`slug`) on documents.
-- Crockford-base32-ish, generated in PHP. Never appears in URLs or
-- rendered HTML — used only for DB joins, audit log entries, and
-- support/debugging references. See docs/decisions.md for why.

ALTER TABLE documents ADD COLUMN slug TEXT;

-- Partial unique index: existing rows (NULL) are grandfathered until
-- the migration runner backfills them; new rows must be unique.
CREATE UNIQUE INDEX idx_documents_slug ON documents(slug) WHERE slug IS NOT NULL;
