<?php require_once('../config.php') ?>
<!DOCTYPE html>
<html lang="en">
<?php require_once('inc/header.php') ?>
<body class="hold-transition login-page">
  <script>
    start_loader()
  </script>
  <style>
    body.login-page {
      min-height: 100vh;
      margin: 0;
      padding: clamp(1.5rem, 3vw, 3rem) 1rem;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(rgba(3, 7, 18, 0.34), rgba(2, 6, 23, 0.5)), url("<?php echo htmlspecialchars(validate_image($_settings->info('cover')), ENT_QUOTES, 'UTF-8') ?>") center center / cover no-repeat fixed;
      color: #f8fafc;
    }
    html.ocwbs-admin-dark body.login-page {
      background: linear-gradient(rgba(2, 6, 23, 0.82), rgba(2, 6, 23, 0.92)), url("<?php echo htmlspecialchars(validate_image($_settings->info('cover')), ENT_QUOTES, 'UTF-8') ?>") center center / cover no-repeat fixed;
    }
    .login-shell {
      width: 100%;
      max-width: 440px;
      margin: 0 auto;
      text-align: center;
    }
    #page-title {
      margin: 0 0 1.4rem;
      font-size: clamp(2.15rem, 4vw, 3.2rem);
      line-height: 1.15;
      letter-spacing: -.04em;
      color: #f8fafc !important;
      font-weight: 800;
      text-shadow: 0 4px 18px rgba(0, 0, 0, 0.7);
    }
    .login-box {
      width: 100%;
      max-width: 430px;
      margin: 0 auto;
    }
    .login-box .card {
      border: 1px solid rgba(148, 163, 184, 0.22);
      border-radius: 0.8rem;
      background: rgba(17, 24, 39, 0.72);
      box-shadow: 0 18px 42px rgba(0, 0, 0, 0.42);
      backdrop-filter: blur(4px);
    }
    html.ocwbs-admin-dark .login-box .card {
      background: rgba(15, 23, 42, 0.8);
      border-color: rgba(148, 163, 184, 0.28);
      box-shadow: 0 18px 42px rgba(0, 0, 0, 0.6);
    }
    .login-box .card-body {
      padding: 1.5rem 1.35rem 1.25rem;
    }
    .login-logo {
      display: flex;
      justify-content: center;
      margin: 0 auto 1rem;
    }
    .login-logo-mark {
      width: 76px;
      height: 76px;
      object-fit: cover;
      border-radius: 18px;
      border: 1px solid rgba(148, 163, 184, 0.25);
      background: linear-gradient(145deg, rgba(15, 23, 42, 0.8), rgba(30, 41, 59, 0.8));
      box-shadow: 0 10px 18px rgba(0, 0, 0, 0.18);
    }
    .login-box-msg {
      margin: 0 0 1.1rem;
      color: rgba(241, 245, 249, 0.9);
      font-size: 1.05rem;
      text-align: center;
    }
    .login-box .input-group {
      margin-bottom: 1rem;
      border: 1px solid rgba(148, 163, 184, 0.2);
      border-radius: 0.62rem;
      overflow: hidden;
      background: rgba(15, 23, 42, 0.76);
    }
    .login-box .form-control {
      border: none;
      background: transparent;
      height: 2.9rem;
      font-size: 1rem;
      box-shadow: none;
      color: #f8fafc;
      padding-left: .9rem;
    }
    .login-box .form-control::placeholder {
      color: rgba(148, 163, 184, 0.9);
    }
    .login-box .form-control:focus {
      box-shadow: none;
      background: transparent;
      color: #fff;
    }
    .login-box .input-group-text {
      border: none;
      background: transparent;
      color: rgba(148, 163, 184, 0.9);
      min-width: 44px;
      justify-content: center;
    }
    .login-box .btn-password-toggle {
      border: none;
      background: transparent;
      color: rgba(226, 232, 240, 0.9);
      padding: 0 .8rem;
      border-left: 1px solid rgba(148, 163, 184, 0.2);
      height: 100%;
      border-radius: 0;
    }
    .login-box .btn-password-toggle:hover {
      background: rgba(148, 163, 184, 0.08);
    }
    .login-box .remember-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin: .2rem 0 1rem;
      font-size: .92rem;
      color: rgba(226, 232, 240, 0.9);
    }
    .login-box .custom-control {
      min-height: 1.1rem;
      padding-left: 1.6rem;
      display: inline-flex;
      align-items: center;
    }
    .login-box .custom-control-label {
      line-height: 1.2;
      color: rgba(226, 232, 240, 0.9);
    }
    .login-box .custom-control-label::before {
      top: 1px;
      width: 1rem;
      height: 1rem;
      background-color: rgba(15, 23, 42, 0.9);
      border-color: rgba(148, 163, 184, 0.5);
    }
    .login-box .custom-control-input:checked ~ .custom-control-label::before {
      background-color: #38bdf8;
      border-color: #38bdf8;
    }
    .login-box .form-link {
      color: #7dd3fc;
      text-decoration: none;
      font-weight: 500;
    }
    .login-box .submit-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: .75rem;
      margin-top: .35rem;
    }
    .login-box .website-link {
      color: rgba(226, 232, 240, 0.9);
      font-size: .9rem;
      text-decoration: none;
    }
    .login-box .btn-primary {
      min-width: 110px;
      background: linear-gradient(135deg, #3b82f6, #2563eb);
      border: none;
      border-radius: 0.55rem;
      padding: .72rem 1.45rem;
      font-weight: 600;
      box-shadow: 0 10px 22px rgba(37, 99, 235, 0.3);
    }
    .login-box .btn-primary:hover {
      background: linear-gradient(135deg, #2563eb, #1d4ed8);
    }
    #login-feedback {
      border: 1px solid rgba(248, 113, 113, 0.5);
      color: #fecaca;
      background: rgba(127, 29, 29, 0.28);
    }
    @media (max-width: 480px) {
      body.login-page {
        padding: 1.5rem 1rem;
      }
      #page-title {
        margin-bottom: 1rem;
      }
      .login-box .card-body {
        padding: 1.2rem 1rem 1rem;
      }
      .login-box .submit-row {
        display: block;
      }
      .login-box .submit-row .website-link {
        display: inline-block;
        margin-bottom: .75rem;
      }
      .login-box .btn-primary {
        width: 100%;
      }
    }
  </style>
  <div class="login-shell">
    <h1 id="page-title"><b><?php echo htmlspecialchars($_settings->info('name'), ENT_QUOTES, 'UTF-8') ?></b></h1>
    <main class="login-box" aria-labelledby="page-title">
      <div class="card card-primary my-2">
        <div class="card-body">
          <div class="login-logo">
            <img class="login-logo-mark" src="<?php echo htmlspecialchars(validate_image($_settings->info('logo')), ENT_QUOTES, 'UTF-8') ?>" data-fallback-src="<?php echo htmlspecialchars(validate_image($_settings->info('cover')), ENT_QUOTES, 'UTF-8') ?>" alt="<?php echo htmlspecialchars($_settings->info('name'), ENT_QUOTES, 'UTF-8') ?> logo" onerror="if(this.dataset.fallbackSrc && this.src !== this.dataset.fallbackSrc){this.src=this.dataset.fallbackSrc}else{this.hidden=true}">
          </div>
          <p class="login-box-msg">Sign in to your administrator or staff account</p>
          <div id="login-feedback" class="alert alert-danger" role="alert" aria-live="polite" hidden></div>
          <form id="login-frm" action="" method="post">
            <div class="input-group">
              <input type="text" class="form-control" name="username" id="username" autofocus placeholder="Username" autocomplete="username" aria-label="Username" required>
              <div class="input-group-append">
                <span class="input-group-text" aria-hidden="true"><i class="fas fa-user"></i></span>
              </div>
            </div>
            <div class="input-group">
              <input type="password" class="form-control" name="password" id="password" placeholder="Password" autocomplete="current-password" aria-label="Password" required>
              <div class="input-group-append">
                <button class="btn btn-password-toggle" type="button" id="toggle-password" aria-label="Show password" aria-pressed="false">
                  <i class="fas fa-eye" aria-hidden="true"></i>
                </button>
              </div>
            </div>
            <div class="remember-row">
              <div class="custom-control custom-checkbox">
                <input type="checkbox" class="custom-control-input" id="remember-username">
                <label class="custom-control-label" for="remember-username">Remember username</label>
              </div>
              <a href="<?php echo htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>admin/forgot_password.php" class="form-link">Forgot password?</a>
            </div>
            <div class="submit-row">
              <a href="<?php echo htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>" class="website-link">Go to website</a>
              <button type="submit" class="btn btn-primary px-4" id="login-submit">Sign In</button>
            </div>
          </form>
        </div>
      </div>
    </main>
  </div>
  <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="dist/js/adminlte.min.js"></script>
  <script>
    $(function(){
      var $username = $('#username');
      var rememberedUsername = '';
      try {
        rememberedUsername = localStorage.getItem('ocwbs_admin_username');
      } catch (error) {
        $('#login-feedback').text('This browser blocked saved usernames; you can still sign in.').prop('hidden', false);
      }
      if (rememberedUsername) {
        $username.val(rememberedUsername);
        $('#remember-username').prop('checked', true);
      }
      $('#toggle-password').on('click', function(){
        var $password = $('#password');
        var isVisible = $password.attr('type') === 'text';
        $password.attr('type', isVisible ? 'password' : 'text');
        $(this).attr('aria-label', isVisible ? 'Show password' : 'Hide password')
          .attr('aria-pressed', String(!isVisible))
          .find('i').toggleClass('fa-eye', isVisible).toggleClass('fa-eye-slash', !isVisible);
      });
      end_loader();
    });
  </script>
</body>
</html>
