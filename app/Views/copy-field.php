<?php
/** Read-only value with an accessible, delegated copy action. */
function copy_field(string $label, string $value, bool $secret=false): string {
    $id='copy-value-'.bin2hex(random_bytes(8));
    $field=str_contains($value,"\n")
        ? '<textarea id="'.$id.'" readonly rows="2" spellcheck="false">'.e($value).'</textarea>'
        : '<input id="'.$id.'" readonly type="'.($secret?'password':'text').'" value="'.e($value).'" autocomplete="off" spellcheck="false">';
    return '<div class="copy-field"><label for="'.$id.'">'.e($label).'</label><div class="copy-value">'.$field.'<button type="button" class="button subtle copy-button" data-copy-target="'.$id.'" aria-label="Copier '.e($label).'">'.icon('copy').'<span>Copier</span></button></div></div>';
}
