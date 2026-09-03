import './bootstrap-noop';
import '../css/theme.css';

/* ── Theme (light/dark via cookie, matches structure prompt) ───────────── */
const THEME_KEY = 'notaria-theme';

function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  document.cookie = `${THEME_KEY}=${theme};path=/;max-age=31536000;samesite=lax`;
}

function initTheme() {
  const saved = document.cookie
    .split('; ')
    .find((c) => c.startsWith(`${THEME_KEY}=`))
    ?.split('=')[1];
  applyTheme(saved || 'light');

  document.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const current = document.documentElement.getAttribute('data-theme');
      applyTheme(current === 'dark' ? 'light' : 'dark');
    });
  });
}

/* ── Reusable request helpers (structure prompt asks for these) ─────────── */
function csrf() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

export async function postForm(url, formData) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
    body: formData,
  });
  return res;
}

/* ── Upload page UX: filename display, drag/drop, submit loading state ──── */
function initUpload() {
  const form = document.querySelector('[data-upload-form]');
  if (!form) return;

  const input = form.querySelector('input[type="file"]');
  const zone = form.querySelector('[data-dropzone]');
  const nameOut = form.querySelector('[data-file-name]');
  const submit = form.querySelector('[data-submit]');

  function showName() {
    const f = input.files?.[0];
    if (f) {
      nameOut.textContent = f.name;
      zone.classList.add('has-file');
    }
  }

  zone?.addEventListener('click', () => input.click());
  input?.addEventListener('change', showName);

  ['dragover', 'dragenter'].forEach((e) =>
    zone?.addEventListener(e, (ev) => {
      ev.preventDefault();
      zone.classList.add('drag');
    }),
  );
  ['dragleave', 'drop'].forEach((e) =>
    zone?.addEventListener(e, (ev) => {
      ev.preventDefault();
      zone.classList.remove('drag');
    }),
  );
  zone?.addEventListener('drop', (ev) => {
    const f = ev.dataTransfer?.files?.[0];
    if (f) {
      input.files = ev.dataTransfer.files;
      showName();
    }
  });

  // Processing is synchronous and can take minutes on CPU — make that visible
  // so the user doesn't think it hung.
  form.addEventListener('submit', () => {
    if (submit) {
      submit.disabled = true;
      submit.textContent = 'Procesando… puede tardar varios minutos';
    }
  });
}

document.addEventListener('DOMContentLoaded', () => {
  initTheme();
  initUpload();
});
