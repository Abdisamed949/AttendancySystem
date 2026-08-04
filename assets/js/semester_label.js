// Shared label formatter for semester <option> text, wherever a Semester
// dropdown is built client-side. Two semesters can legitimately share the
// same name within one faculty (e.g. "Semester 6" recreated for a new
// Academic Year) — always showing the Academic Year + Status makes them
// impossible to confuse, instead of relying on the bare name alone.
function admasSemesterLabel(sem) {
    const status = sem.status ? sem.status.charAt(0).toUpperCase() + sem.status.slice(1) : '';
    const year = sem.academic_year_label ? sem.academic_year_label : '';
    const parts = [year, status].filter(Boolean).join(' · ');
    return parts ? `${sem.name} (${parts})` : sem.name;
}
