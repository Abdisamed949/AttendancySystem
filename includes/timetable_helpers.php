<?php
/**
 * Class Time Table — shared Day/Time labels and grid-building logic, used
 * by student/dashboard.php, lecturer/dashboard.php, and
 * class_timetable.php (the university-wide view). One optional day/time
 * window per course_offerings row (course_offerings.day_of_week/
 * start_time/end_time/room) — see
 * migrations/2026_08_course_offerings_timetable.sql. Room is
 * display-only; no double-booking validation is performed anywhere, by
 * explicit request.
 */
declare(strict_types=1);

const DAY_OF_WEEK_LABELS = [
    'saturday' => 'Saturday',
    'sunday' => 'Sunday',
    'monday' => 'Monday',
    'tuesday' => 'Tuesday',
    'wednesday' => 'Wednesday',
    'thursday' => 'Thursday',
    'friday' => 'Friday',
];

// Saturday-first display order, matching ADMAS's own real printed Class
// Time Table (Sat-Thu teaching week) rather than a Monday-first Western
// week.
const DAY_OF_WEEK_DISPLAY_ORDER = [
    'saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday',
];

/**
 * Which program year a semester falls in, e.g. "Semester 4" at 3
 * semesters/year is Year 2. Purely a display convenience — returns null if
 * the semester's name has no digits to derive a sequence number from.
 * Same logic as semesters.php's own local copy (that one predates this
 * shared file and was left in place there to avoid an unrelated diff).
 */
function semester_year_number(string $semesterName, int $semestersPerYear): ?int
{
    $digits = preg_replace('/\D/', '', $semesterName);
    if ($digits === '' || $semestersPerYear <= 0) {
        return null;
    }

    return (int) ceil(((int) $digits) / $semestersPerYear);
}

function format_timetable_time(?string $time): string
{
    if ($time === null || $time === '') {
        return '';
    }

    $ts = strtotime($time);

    return $ts === false ? $time : date('g:iA', $ts);
}

/**
 * Builds a Day x Time grid from a flat list of scheduled offering rows
 * (each needing day_of_week/start_time/end_time plus whatever the caller
 * wants shown in the cell). Rows with no day_of_week or no start/end time
 * are skipped (an offering with no schedule set yet simply doesn't appear
 * on the Time Table, rather than rendering a blank/broken slot).
 *
 * @param array<int, array{day_of_week: ?string, start_time: ?string, end_time: ?string}> $offerings
 * @return array{time_slots: array<string, array{start: string, end: string, label: string}>, cells: array<string, array<string, array>>}
 *         cells[timeSlotKey][day] = array of offering rows in that slot.
 */
/**
 * Renders one Day x Time grid `<table>` (no wrapping card/header) — the
 * exact markup shared by every consumer of build_class_timetable_grid():
 * class_timetable.php, student/class_timetable.php, and the
 * student/lecturer dashboard cards. $courseLabelKey picks which key each
 * cell row uses for its main label — always the course's own full name
 * (e.g. "course_name" or "name"), never the short code, per explicit
 * request that the Class Time Table read like a real printed schedule.
 * $editable adds a small Edit button on each cell entry (requires
 * "offering_id" to be present on every row) — off by default, and left
 * off entirely for every read-only consumer of this function.
 */
function render_class_timetable_grid_table(array $grid, array $dayOrder, string $courseLabelKey, string $tableClass = '', bool $editable = false): void
{
    ?>
    <table class="table admas-table table-sm timetable-table <?= htmlspecialchars($tableClass) ?> mb-0">
        <thead>
            <tr>
                <th class="timetable-time-col">Time</th>
                <?php foreach ($dayOrder as $dayKey): ?>
                    <th><?= htmlspecialchars(DAY_OF_WEEK_LABELS[$dayKey]) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($grid['time_slots'] as $slotKey => $slot): ?>
                <tr>
                    <td class="timetable-time-col"><?= htmlspecialchars($slot['label']) ?></td>
                    <?php foreach ($dayOrder as $dayKey): ?>
                        <td>
                            <?php if (!empty($grid['cells'][$slotKey][$dayKey])): ?>
                                <?php foreach ($grid['cells'][$slotKey][$dayKey] as $cell): ?>
                                    <div class="timetable-print-cell<?= $editable ? ' timetable-print-cell-editable' : '' ?>">
                                        <div><?= htmlspecialchars((string) $cell[$courseLabelKey]) ?></div>
                                        <div><?= htmlspecialchars((string) ($cell['lecturer_name'] ?? $cell['department_name'] ?? 'Unassigned')) ?></div>
                                        <?php if (!empty($cell['room'])): ?><div class="fw-bold"><?= htmlspecialchars((string) $cell['room']) ?></div><?php endif; ?>
                                        <?php if ($editable && isset($cell['offering_id'])): ?>
                                            <button type="button" class="btn btn-sm btn-link p-0 timetable-edit-btn"
                                                data-offering-id="<?= (int) $cell['offering_id'] ?>"
                                                data-course-label="<?= htmlspecialchars((string) $cell[$courseLabelKey]) ?>"
                                                data-day="<?= htmlspecialchars((string) $dayKey) ?>"
                                                data-start-time="<?= htmlspecialchars((string) ($cell['start_time'] ?? '')) ?>"
                                                data-end-time="<?= htmlspecialchars((string) ($cell['end_time'] ?? '')) ?>"
                                                data-room="<?= htmlspecialchars((string) ($cell['room'] ?? '')) ?>"
                                                onclick="admasOpenTimetableEditModal(this)">
                                                <i class="bi bi-pencil-square"></i> Edit
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

function build_class_timetable_grid(array $offerings): array
{
    $timeSlots = [];
    $cells = [];

    foreach ($offerings as $row) {
        $day = $row['day_of_week'] ?? null;
        $start = $row['start_time'] ?? null;
        $end = $row['end_time'] ?? null;
        if (!$day || !$start || !$end || !isset(DAY_OF_WEEK_LABELS[$day])) {
            continue;
        }

        $slotKey = $start . '-' . $end;
        if (!isset($timeSlots[$slotKey])) {
            $timeSlots[$slotKey] = [
                'start' => $start,
                'end' => $end,
                'label' => format_timetable_time($start) . ' - ' . format_timetable_time($end),
            ];
        }

        $cells[$slotKey][$day][] = $row;
    }

    // Chronological order by start time.
    uasort($timeSlots, static fn ($a, $b) => strcmp($a['start'], $b['start']));

    return ['time_slots' => $timeSlots, 'cells' => $cells];
}
