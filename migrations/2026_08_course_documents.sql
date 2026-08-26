-- ---------------------------------------------------------------------
-- Course Documents — lecturer-uploaded learning materials, organized by
-- Chapter (1-7 per course, matching a real semester's teaching plan),
-- with a short description. Downloadable only by a lecturer/student who
-- can actually reach that course (see includes/course_document_helpers.php
-- for the access checks) — never a public/direct file URL.
--
-- Tied to the catalog `course_id` (not a specific course_offerings row):
-- a course's lecture notes/past papers are normally shared across every
-- semester/shift the same course runs, not re-uploaded each time.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS course_documents (
  id                    INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  course_id             INT UNSIGNED NOT NULL,
  chapter_number        TINYINT UNSIGNED NOT NULL,   -- 1 to 7
  title                 VARCHAR(150) NOT NULL,
  description           VARCHAR(500) NULL,
  stored_filename       VARCHAR(255) NOT NULL,       -- random filename on disk, under uploads/course_documents/
  original_filename     VARCHAR(255) NOT NULL,       -- client's own filename, display-only, never used on disk
  file_extension        VARCHAR(10)  NOT NULL,
  file_size             INT UNSIGNED NOT NULL,
  uploaded_by_lecturer_id INT UNSIGNED NOT NULL,
  download_count        INT UNSIGNED NOT NULL DEFAULT 0,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_course_documents_course
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_course_documents_lecturer
    FOREIGN KEY (uploaded_by_lecturer_id) REFERENCES lecturers(id) ON DELETE CASCADE,
  CONSTRAINT chk_course_documents_chapter CHECK (chapter_number BETWEEN 1 AND 7),
  INDEX idx_course_documents_course_chapter (course_id, chapter_number)
) ENGINE=InnoDB;
