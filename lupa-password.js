const form = document.querySelector('[data-password-reset-form]');
const emailStep = document.querySelector('[data-reset-email-step]');
const otpStep = document.querySelector('[data-reset-otp-step]');
const pendingInput = document.querySelector('[data-reset-pending-id]');
let resetStep = 'email';

const ensureToastRegion = () => {
  let region = document.querySelector('[data-toast-region]');
  if (region) return region;
  region = document.createElement('div');
  region.className = 'toast-region';
  region.dataset.toastRegion = 'true';
  region.setAttribute('aria-live', 'polite');
  document.body.append(region);
  return region;
};

const showToast = (message) => {
  const text = String(message || '').trim();
  if (!text) return;
  const region = ensureToastRegion();
  if ([...region.querySelectorAll('.app-toast p')].some((item) => item.textContent === text)) return;

  const toast = document.createElement('div');
  toast.className = 'app-toast is-danger';
  toast.setAttribute('role', 'alert');
  const content = document.createElement('div');
  const title = document.createElement('strong');
  title.textContent = 'Terjadi masalah';
  const body = document.createElement('p');
  body.textContent = text;
  content.append(title, body);
  const close = document.createElement('button');
  close.type = 'button';
  close.textContent = 'Tutup';
  let timer = null;
  const dismiss = () => {
    window.clearTimeout(timer);
    toast.classList.add('is-leaving');
    window.setTimeout(() => toast.remove(), 180);
  };
  close.addEventListener('click', dismiss);
  toast.append(content, close);
  region.append(toast);
  timer = window.setTimeout(dismiss, 6000);
};

const showMessage = (message, type = 'success') => {
  if (type === 'error') {
    form.querySelector('.notice.error')?.remove();
    showToast(message);
    return;
  }
  form.querySelector('.notice')?.remove();
  const notice = document.createElement('p');
  notice.className = `notice ${type}`;
  notice.textContent = message;
  form.prepend(notice);
};

const setStep = (step) => {
  resetStep = step;
  emailStep.hidden = step !== 'email';
  otpStep.hidden = step !== 'otp';

  for (const field of emailStep.querySelectorAll('input')) {
    field.disabled = step !== 'email';
  }
  for (const field of otpStep.querySelectorAll('input')) {
    field.disabled = step !== 'otp';
  }
};

form?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const activeContainer = resetStep === 'email' ? emailStep : otpStep;
  const fields = [...activeContainer.querySelectorAll('input')];

  for (const field of fields) {
    field.required = true;
    if (!field.reportValidity()) return;
  }

  const button = activeContainer.querySelector('button[type="submit"]');
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = 'Diproses';

  try {
    const data = Object.fromEntries(new FormData(form).entries());
    data.action = resetStep === 'email' ? 'request' : 'verify';

    const response = await fetch('api/password-reset.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
    const payload = await response.json();

    if (!response.ok) {
      throw new Error(payload.error || 'Request gagal.');
    }

    showMessage(payload.message || 'Berhasil.');

    if (payload.requires_otp) {
      pendingInput.value = payload.pending_id;
      setStep('otp');
      otpStep.querySelector('[name="otp"]')?.focus();
      return;
    }

    window.setTimeout(() => {
      window.location.href = 'login.html';
    }, 1200);
  } catch (error) {
    showMessage(error.message, 'error');
  } finally {
    button.disabled = false;
    button.textContent = originalText;
  }
});

document.querySelector('[data-reset-back]')?.addEventListener('click', () => {
  pendingInput.value = '';
  form.querySelector('[name="otp"]').value = '';
  form.querySelector('[name="password"]').value = '';
  form.querySelector('[name="password_confirmation"]').value = '';
  form.querySelector('.notice')?.remove();
  setStep('email');
});

setStep('email');
