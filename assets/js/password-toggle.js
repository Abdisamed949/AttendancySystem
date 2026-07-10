/**
 * Attaches an independent show/hide toggle to every password field marked
 * up with the .toggle-password button pattern (see profile.php / login.php).
 */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.toggle-password').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.getAttribute('data-target'));
            if (!target) {
                return;
            }

            var icon = btn.querySelector('i');
            var isHidden = target.type === 'password';

            target.type = isHidden ? 'text' : 'password';
            icon.classList.toggle('bi-eye', !isHidden);
            icon.classList.toggle('bi-eye-slash', isHidden);
            btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });
    });
});
