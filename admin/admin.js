/* SwissBricks Admin JS */

// ── Image preview ─────────────────────────────────────────
document.addEventListener('change', function (e) {
  if (e.target.id !== 'imageUpload') return;
  const file = e.target.files[0];
  if (!file) return;
  const preview = document.getElementById('imagePreview');
  if (preview) {
    const reader = new FileReader();
    reader.onload = evt => { preview.src = evt.target.result; preview.style.display = 'block'; };
    reader.readAsDataURL(file);
  }
});

// ── Confirm dangerous actions ─────────────────────────────
document.addEventListener('click', function (e) {
  const btn = e.target.closest('[data-confirm]');
  if (!btn) return;
  if (!confirm(btn.dataset.confirm || 'Are you sure?')) {
    e.preventDefault();
    e.stopPropagation();
  }
});

// ── Auto-dismiss alerts ────────────────────────────────────
document.querySelectorAll('.alert').forEach(el => {
  setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity 0.5s'; setTimeout(() => el.remove(), 500); }, 5000);
});

// ── Generate slug from name ────────────────────────────────
const nameInput = document.getElementById('productName');
const slugInput = document.getElementById('productSlug');
if (nameInput && slugInput) {
  nameInput.addEventListener('input', function () {
    if (slugInput.dataset.locked === 'true') return;
    slugInput.value = this.value
      .toLowerCase()
      .replace(/[^a-z0-9\s-]/g, '')
      .replace(/\s+/g, '-')
      .replace(/-+/g, '-')
      .trim();
  });
  slugInput.addEventListener('input', function () {
    this.dataset.locked = 'true';
  });
}

// ── Status update live ─────────────────────────────────────
document.querySelectorAll('.status-select').forEach(sel => {
  sel.addEventListener('change', function () {
    const form = this.closest('form');
    if (form) form.submit();
  });
});
