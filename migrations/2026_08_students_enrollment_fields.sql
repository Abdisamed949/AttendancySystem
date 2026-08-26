-- Adds the extra enrollment-record fields captured by the university's real
-- paper/Excel "Enrollment" form (Downloads/Enrollment (2).xlsx), beyond what
-- students already stored (name/faculty/department/academic year/shift).
-- All nullable: existing student rows simply show "Not set" until an admin
-- fills them in via the (now extended) Add/Edit Student form.
--
-- Deliberately NOT added: "Student ID Number" (already exists as the
-- auto-generated students.student_no, unchanged), "Student Email" (reuses
-- the existing users.email — no separate column), "Faculty"/"Department"/
-- "Academic Year" (already exist as faculty_id/department_id/
-- academic_year_id), "Shift" (already exists — the template itself doesn't
-- have this column, added separately at the app layer, not here).
ALTER TABLE students
    ADD COLUMN mother_name VARCHAR(120) NULL AFTER grandfather_name,
    ADD COLUMN sex ENUM('male', 'female') NULL AFTER mother_name,
    ADD COLUMN birth_date DATE NULL AFTER sex,
    ADD COLUMN street_address VARCHAR(255) NULL AFTER birth_date,
    ADD COLUMN phone VARCHAR(30) NULL AFTER street_address,
    ADD COLUMN emergency_contact_name VARCHAR(120) NULL AFTER phone,
    ADD COLUMN emergency_contact_phone VARCHAR(30) NULL AFTER emergency_contact_name,
    ADD COLUMN nationality VARCHAR(80) NULL AFTER emergency_contact_phone,
    ADD COLUMN enrollment_date DATE NULL AFTER nationality,
    ADD COLUMN certificate_type VARCHAR(120) NULL AFTER enrollment_date,
    ADD COLUMN school_roll_number VARCHAR(60) NULL AFTER certificate_type,
    ADD COLUMN degree VARCHAR(120) NULL AFTER school_roll_number,
    ADD COLUMN program VARCHAR(120) NULL AFTER degree,
    ADD COLUMN class_year VARCHAR(30) NULL AFTER program;
