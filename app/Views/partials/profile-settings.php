<?php /**
 * Settings panel #profile ("Votre profil"), shown to every user: display name and avatar
 * upload/removal, posted as multipart/form-data to /account; the e-mail is read-only.
 * Also opens #password-dialog. Included by sections/settings.php. Expects $user.
 */ ?>
<section class="panel" id="profile">
 <div class="panel-header"><div><h2>Votre profil</h2><p>Le visage derrière les prochaines idées.</p></div><?= avatar($user['name'],'',(int)$user['id']) ?></div>
 <form action="/account" method="post" enctype="multipart/form-data" class="stack settings-form" data-avatar-form>
  <input type="hidden" name="_return" value="/settings"><?= csrf() ?>
  <label>Nom d’affichage<input name="name" value="<?= e($user['name']) ?>" required maxlength="120"></label>
  <label>Adresse professionnelle<input value="<?= e($user['email']) ?>" disabled></label>
  <label>Photo de profil<input type="file" name="avatar" accept="image/png,image/jpeg,image/webp"><small>PNG, JPEG ou WebP · 2 Mo maximum · 4 096 × 4 096 pixels. La photo est visible par les membres connectés.</small></label>
  <?php if(App\Models\UserAvatar::url((int)$user['id'])): ?><label class="check-label"><input type="checkbox" name="remove_avatar" value="1">Retirer ma photo et revenir aux initiales</label><?php endif; ?>
  <p class="help-text" role="status" data-avatar-status></p>
  <div class="settings-actions"><button class="button primary">Enregistrer le profil</button><button type="button" class="button subtle" data-open="password-dialog">Changer mon mot de passe</button></div>
 </form>
</section>
