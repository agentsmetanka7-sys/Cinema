<section class="container py-5 auth-wrap">
  <div class="auth-card text-center animate-fade-up">
    <div class="login-hero mb-4">
      <div class="login-icon-wrap">
        <i class="bi bi-person-circle text-white"></i>
      </div>
      <h1 class="h3 mb-2">Вхід в акаунт</h1>
      <p class="text-secondary mb-0">Використовуйте логін та пароль</p>
    </div>

    <form method="post" action="/auth/login" class="d-flex flex-column gap-3 text-start" id="login-form">
      <input type="hidden" name="next" value="<?= e((string) ($_GET['next'] ?? '')) ?>">
      <div>
        <label class="form-label">
          <i class="bi bi-person"></i>
          Логін
        </label>
        <input 
          class="form-control" 
          type="text" 
          name="login" 
          placeholder="Введіть логін" 
          required
          autocomplete="username"
        >
      </div>
      <div>
        <label class="form-label">
          <i class="bi bi-lock"></i>
          Пароль
        </label>
        <input 
          class="form-control" 
          type="password" 
          name="password" 
          placeholder="Введіть пароль" 
          required
          autocomplete="current-password"
        >
      </div>
      <button class="btn btn-danger btn-lg mt-2" type="submit" id="login-btn">
        <span>Увійти</span>
        <i class="bi bi-arrow-right"></i>
      </button>
    </form>

    <div class="mt-4 pt-3 border-top border-secondary-subtle">
      <p class="mb-2 text-secondary">Немає акаунта?</p>
      <a href="/register" class="btn btn-outline-light">
        <i class="bi bi-person-plus me-2"></i>
        Зареєструватися
      </a>
    </div>
  </div>
</section>

<script>
(function() {
  const form = document.getElementById('login-form');
  const btn = document.getElementById('login-btn');
  
  form.addEventListener('submit', function() {
    btn.classList.add('btn-loading');
    btn.disabled = true;
  });
})();
</script>
