<?php $branding = App\Models\CompanyBranding::get(); ?>
<section class="panel company-settings" id="company">
 <div class="panel-header"><div><h2>Identité de l’entreprise</h2><p>Personnalisez votre espace et les pages de connexion.</p></div><span class="company-icon company-preview"><?= App\Core\Company::mark() ?></span></div>
 <form method="post" action="/company" enctype="multipart/form-data" class="stack" data-company-form>
  <?= csrf() ?><input type="hidden" name="version" value="<?= (int)$branding['version'] ?>"><input type="hidden" name="_return" value="/settings#company">
  <label>Nom de l’entreprise<input name="company_name" value="<?= e(App\Core\Company::name()) ?>" maxlength="120" required autocomplete="organization"></label>
  <label>Sous-titre de l’espace<input name="company_subtitle" value="<?= e($branding['subtitle']) ?>" maxlength="100" placeholder="Espace entreprise"></label>
  <label>Initiale ou symbole<input name="company_icon" value="<?= e($branding['icon']) ?>" maxlength="4" placeholder="<?= e(App\Core\Company::initial()) ?>"><small>Utilisé en l’absence de logo. Laissez vide pour prendre l’initiale du nom.</small></label>
  <label>Logo de l’entreprise<input type="file" name="company_logo" accept="image/png,image/jpeg,image/webp"><small>PNG, JPEG ou WebP · 2 Mo maximum · 4 096 × 4 096 pixels. Le logo sera visible avant connexion et sur les formulaires externes.</small></label>
  <p class="help-text" role="status" data-company-preview-status></p>
  <?php if($branding['logo_digest']): ?><label class="check-label"><input type="checkbox" name="remove_logo" value="1">Retirer le logo actuel et utiliser l’initiale ou le symbole</label><?php endif; ?>
  <p class="help-text">Ces informations s’appliquent à toute l’installation. Les domaines autorisés à l’inscription restent configurés dans le fichier <code>.env</code>.</p>
  <button class="button primary">Enregistrer l’entreprise</button>
 </form>
</section>
