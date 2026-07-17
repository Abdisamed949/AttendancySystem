/**
 * Profile photo upload — live preview + save-button gating, shared by
 * every role's profile.php. Client-side checks are UX-only; the server
 * re-validates everything regardless.
 */
(function () {
    const input = document.getElementById('profilePhotoInput');
    const preview = document.getElementById('profilePhotoPreview');
    const saveBtn = document.getElementById('profilePhotoSaveBtn');
    if (!input || !preview || !saveBtn) {
        return;
    }

    const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    const maxBytes = 5 * 1024 * 1024;

    input.addEventListener('change', () => {
        const file = input.files[0];
        if (!file) {
            saveBtn.disabled = true;
            return;
        }

        if (!allowedTypes.includes(file.type)) {
            alert('Please choose a JPG, PNG, GIF, or WEBP image.');
            input.value = '';
            saveBtn.disabled = true;
            return;
        }
        if (file.size > maxBytes) {
            alert('Image must be 5MB or smaller.');
            input.value = '';
            saveBtn.disabled = true;
            return;
        }

        const reader = new FileReader();
        reader.onload = (e) => {
            const current = document.getElementById('profilePhotoPreview');
            if (current.tagName === 'IMG') {
                current.src = e.target.result;
            } else {
                const img = document.createElement('img');
                img.id = 'profilePhotoPreview';
                img.className = 'rounded-circle';
                img.width = 72;
                img.height = 72;
                img.style.width = '72px';
                img.style.height = '72px';
                img.style.objectFit = 'cover';
                img.style.border = '2px solid var(--admas-sky)';
                img.src = e.target.result;
                current.replaceWith(img);
            }
        };
        reader.readAsDataURL(file);

        saveBtn.disabled = false;
    });
})();
