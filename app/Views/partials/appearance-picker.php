<?php /* Empty name = project fields; otherwise a complete nested form key. */
$pickerIconName=$pickerName===''?'icon':$pickerName.'[icon]';
$pickerColorName=$pickerName===''?'color':$pickerName.'[color]'; ?>
<fieldset class="appearance-picker" data-appearance-picker>
 <legend><?= e($pickerLabel) ?></legend>
 <div class="appearance-preview"><span class="work-mark" data-appearance-preview data-mark-color="<?= e($pickerMark['color']) ?>"><?= icon($pickerMark['icon']) ?></span><span data-appearance-name><?= e(App\Models\Appearance::ICONS[$pickerMark['icon']]) ?></span></div>
 <div class="appearance-icon-grid" role="group" aria-label="Pictogramme">
 <?php foreach(App\Models\Appearance::ICONS as $symbol=>$label): ?><label class="appearance-icon-choice" title="<?= e($label) ?>"><input type="radio" name="<?= e($pickerIconName) ?>" value="<?= e($symbol) ?>" <?= $pickerMark['icon']===$symbol?'checked':'' ?> required aria-label="<?= e($label) ?>"><span><?= icon($symbol) ?></span></label><?php endforeach; ?>
 </div>
 <div class="appearance-colors" role="group" aria-label="Couleur">
 <?php foreach(App\Models\Appearance::PALETTE as $color): ?><button type="button" class="appearance-swatch" data-appearance-color="<?= e($color) ?>" data-mark-color="<?= e($color) ?>" aria-label="Couleur <?= e($color) ?>" aria-pressed="<?= strtolower($pickerMark['color'])===$color?'true':'false' ?>"><span></span><?= icon('check') ?></button><?php endforeach; ?>
 <label class="appearance-custom-color">Personnalisée<input type="color" name="<?= e($pickerColorName) ?>" value="<?= e($pickerMark['color']) ?>" aria-label="Couleur personnalisée"></label>
 </div>
</fieldset>
