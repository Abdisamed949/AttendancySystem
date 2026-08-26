-- ---------------------------------------------------------------------
-- Course Documents: Quiz / Assignment / Chapter type + optional cover
-- image per document. `chapter_number` becomes meaningful only when
-- document_type = 'chapter' (NULL for quiz/assignment), so the old
-- BETWEEN 1 AND 7 CHECK constraint is replaced with one that also
-- accepts NULL.
-- ---------------------------------------------------------------------
ALTER TABLE course_documents
  DROP CONSTRAINT chk_course_documents_chapter;

ALTER TABLE course_documents
  ADD COLUMN document_type ENUM('chapter', 'quiz', 'assignment') NOT NULL DEFAULT 'chapter' AFTER course_id,
  MODIFY COLUMN chapter_number TINYINT UNSIGNED NULL,
  ADD COLUMN cover_image_path VARCHAR(255) NULL AFTER file_size,
  ADD CONSTRAINT chk_course_documents_chapter CHECK (chapter_number IS NULL OR (chapter_number BETWEEN 1 AND 7));
