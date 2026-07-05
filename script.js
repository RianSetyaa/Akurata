const reveals = document.querySelectorAll('.reveal');

const observer = new IntersectionObserver(
  (entries) => {
    for (const entry of entries) {
      if (entry.isIntersecting) {
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      }
    }
  },
  {
    threshold: 0.16,
    rootMargin: '0px 0px -40px 0px',
  }
);

for (const element of reveals) {
  observer.observe(element);
}

const dashboardPathForUser = (user) => (
  user?.role === 'administrator' && !user?.impersonating
    ? 'administrator/index.html'
    : 'dashboard/index.html'
);

fetch('api/me.php', { credentials: 'same-origin' })
  .then(async (response) => {
    if (!response.ok) return null;
    return response.json();
  })
  .then((payload) => {
    const user = payload?.user;
    if (!user) return;

    const dashboardPath = dashboardPathForUser(user);
    const loginLink = document.querySelector('[data-session-login]');
    const registerLink = document.querySelector('[data-session-register]');
    if (loginLink) {
      loginLink.href = dashboardPath;
      loginLink.textContent = 'Dashboard';
      loginLink.classList.add('nav-cta');
    }
    registerLink?.remove();

    if (document.body.classList.contains('auth-page')) {
      window.location.replace(dashboardPath);
    }
  })
  .catch(() => {});

document.querySelector('[data-whatsapp-form]')?.addEventListener('submit', (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  if (!form.reportValidity()) return;
  const data = Object.fromEntries(new FormData(form).entries());
  const message = [
    'Halo Akurata POS, saya ingin meminta demo.',
    `Nama usaha: ${data.business}`,
    `Kontak: ${data.contact}`,
  ].join('\n');
  window.open(`https://wa.me/6282318320682?text=${encodeURIComponent(message)}`, '_blank', 'noopener');
});

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

const showToast = (message, type = 'error', duration = 6000) => {
  const text = String(message || '').trim();
  if (!text) return;

  const region = ensureToastRegion();
  if ([...region.querySelectorAll('.app-toast p')].some((item) => item.textContent === text)) return;
  const toast = document.createElement('div');
  toast.className = `app-toast ${type === 'success' ? 'is-success' : 'is-danger'}`;
  toast.setAttribute('role', type === 'success' ? 'status' : 'alert');
  const content = document.createElement('div');
  const title = document.createElement('strong');
  title.textContent = type === 'success' ? 'Berhasil' : 'Terjadi masalah';
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
  timer = window.setTimeout(dismiss, duration);
};

for (const form of document.querySelectorAll('.auth-form')) {
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const currentForm = event.currentTarget;
    const activeStepElement = currentForm.querySelector('.form-step.is-active');
    const button = activeStepElement?.querySelector('button[type="submit"]') ?? currentForm.querySelector('button[type="submit"]');
    if (button) {
      const activeFields = activeStepElement?.querySelectorAll('input, select') ?? [];
      const isValid = [...activeFields].every((field) => !field.required || field.reportValidity());
      if (!isValid) {
        return;
      }

      const originalLabel = button.textContent;
      button.textContent = 'Diproses';
      button.disabled = true;

      const finish = (message, type = 'success') => {
        if (type === 'error') {
          currentForm.querySelector('.notice.error')?.remove();
          showToast(message);
          return;
        }
        currentForm.querySelector('.notice')?.remove();
        currentForm.insertAdjacentHTML('afterbegin', `<p class="notice ${type}">${message}</p>`);
      };

      const resetButton = () => {
        button.textContent = originalLabel;
        button.disabled = false;
      };

      if (!currentForm.dataset.api) {
        window.setTimeout(() => {
          if (currentForm.dataset.redirect) {
            window.location.href = currentForm.dataset.redirect;
            return;
          }

          resetButton();
        }, 800);
        return;
      }

      const activeStep = currentForm.querySelector('.form-step.is-active')?.dataset.step;
      const endpoint = activeStep === '3' ? currentForm.dataset.verifyApi : currentForm.dataset.api;

      fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(Object.fromEntries(new FormData(currentForm).entries())),
      })
        .then(async (response) => {
          const payload = await response.json();
          if (!response.ok) {
            throw new Error(payload.error || 'Request gagal.');
          }
          return payload;
        })
        .then((payload) => {
          if (payload.requires_otp) {
            currentForm.querySelector('[data-pending-id]').value = payload.pending_id;
            finish(payload.message || 'Kode OTP sudah dikirim ke email.');
            currentForm.dispatchEvent(new CustomEvent('auth:otp', {
              detail: {
                email: payload.email,
              },
            }));
            resetButton();
            return;
          }

          finish(payload.message || 'Berhasil.');
          window.setTimeout(() => {
            if (currentForm.dataset.redirect) {
              window.location.href = currentForm.dataset.redirect;
            } else {
              resetButton();
            }
          }, 800);
        })
        .catch((error) => {
          finish(error.message, 'error');
          resetButton();
        });
    }
  });
}

for (const form of document.querySelectorAll('[data-stepped-form]')) {
  const steps = form.querySelectorAll('[data-step]');
  const indicators = document.querySelectorAll('[data-step-indicator]');

  const showStep = (stepNumber) => {
    for (const step of steps) {
      const isActive = step.dataset.step === stepNumber;
      step.classList.toggle('is-active', isActive);

      for (const field of step.querySelectorAll('input, select')) {
        field.disabled = step.dataset.step !== '1' && !isActive;
      }
    }

    for (const indicator of indicators) {
      indicator.classList.toggle('is-active', indicator.dataset.stepIndicator === stepNumber);
    }
  };

  form.querySelector('[data-next-step]')?.addEventListener('click', () => {
    const currentStep = form.querySelector('[data-step="1"]');
    const fields = currentStep?.querySelectorAll('input, select') ?? [];
    const isValid = [...fields].every((field) => field.reportValidity());

    if (isValid) {
      showStep('2');
    }
  });

  for (const previousButton of form.querySelectorAll('[data-prev-step]')) {
    previousButton.addEventListener('click', () => {
      showStep('1');
    });
  }

  form.addEventListener('auth:otp', (event) => {
    const email = event.detail?.email;
    if (email) {
      form.querySelector('[data-step="3"] legend').textContent = `Verifikasi ${email}`;
    }
    showStep('3');
  });

  showStep('1');
}
