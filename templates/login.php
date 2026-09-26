<div class="loginbox">
  <div class="logo"><img src="<?= e(asset('logo-jc.png')) ?>" alt="Journée Circuit"></div>
  <div class="card">
    <h1>Connexion</h1>
    <p class="muted" style="margin-top:0">Mêmes identifiants que l’ancien portail.</p>
    <?php if (!empty($error)): ?><div class="flash ko"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="/login" autocomplete="on">
      <?= csrf_field() ?>
      <div class="field"><label class="f" for="email">E-mail</label><input id="email" type="email" name="email" value="<?= e($email ?? '') ?>" required autofocus style="width:100%"></div>
      <div class="field"><label class="f" for="pw">Mot de passe</label><input id="pw" type="password" name="password" required style="width:100%"></div>
      <button class="btn" type="submit" style="width:100%">Se connecter</button>
    </form>
  </div>
</div>
