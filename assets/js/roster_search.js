/**
 * attendance.php's Xiiso Grid — lets a lecturer/dean/rector filter the
 * on-screen roster by Student No or Full Name as they type, without a page
 * reload (pure client-side, since the whole roster is already rendered —
 * no extra query needed). Rows are matched against their own "Student No"
 * and "Full Name" cells (the first two <td>s of each roster row), so a
 * search for either the ID or any part of the name works the same way.
 */
(function () {
    const input = document.getElementById('rosterSearchInput');
    const table = document.getElementById('xiisoGridTable');
    if (!input || !table) {
        return;
    }

    const rows = table.querySelectorAll('tbody tr[data-student-row]');

    input.addEventListener('input', () => {
        const term = input.value.trim().toLowerCase();

        rows.forEach((row) => {
            const cells = row.querySelectorAll('td');
            const studentNo = (cells[0] ? cells[0].textContent : '').toLowerCase();
            const fullName = (cells[1] ? cells[1].textContent : '').toLowerCase();
            const matches = term === '' || studentNo.includes(term) || fullName.includes(term);
            row.style.display = matches ? '' : 'none';
        });
    });
})();
