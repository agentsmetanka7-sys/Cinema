<section class="container py-5 auth-wrap">
  <div class="auth-card text-center animate-fade-up">
    <div class="login-hero mb-4">
      <div class="login-icon-wrap" style="background: linear-gradient(145deg, var(--success), #16a34a);">
        <i class="bi bi-person-plus text-white"></i>
      </div>
      <h1 class="h3 mb-2">Створення акаунта</h1>
      <p class="text-secondary mb-0">Тільки логін і пароль</p>
    </div>

    <form method="post" action="/auth/register" class="d-flex flex-column gap-3 text-start" id="register-form">
      <div>
        <label class="form-label">
          <i class="bi bi-at"></i> Логін
        </label>
        <input class="form-control" type="text" name="login" placeholder="Наприклад: ivan_01" required autocomplete="username">
      </div>
      <div>
        <label class="form-label">
          <i class="bi bi-lock"></i> Пароль
        </label>
        <input class="form-control" type="password" name="password" placeholder="Мінімум 6 символів" required minlength="6" autocomplete="new-password">
      </div>
      <div>
        <label class="form-label">
          <i class="bi bi-shield-lock"></i> Повторний пароль
        </label>
        <input class="form-control" type="password" name="password_confirm" placeholder="Повторіть пароль" required minlength="6" autocomplete="new-password">
      </div>
      <button class="btn btn-danger btn-lg mt-2" type="submit" id="register-btn">
        <span>Створити акаунт</span>
        <i class="bi bi-check-circle"></i>
      </button>
    </form>

    <div class="mt-4 pt-3 border-top border-secondary-subtle">
      <p class="mb-2 text-secondary">Вже є акаунт?</p>
      <a href="/login" class="btn btn-outline-light">
        <i class="bi bi-box-arrow-in-right me-2"></i>Увійти
      </a>
    </div>
  </div>
</section>

<script>
(function() {
  const form = document.getElementById('register-form');
  const btn = document.getElementById('register-btn');
  
  form.addEventListener('submit', function(e) {
    const pass = form.querySelector('[name="password"]').value;
    const confirm = form.querySelector('[name="password_confirm"]').value;
    
    if (pass !== confirm) {
      e.preventDefault();
      if (typeof showToast === 'function') {
        showToast('Паролі не співпадають', 'error');
      }
      return;
    }
    
    btn.classList.add('btn-loading');
    btn.disabled = true;
  });
})();
</script>
