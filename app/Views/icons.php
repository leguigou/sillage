<?php
function icon(string $name, string $class=''): string {
    $paths=[
        'panel'=>'<rect x="3" y="4" width="18" height="16" rx="3"/><path d="M9 4v16m6-11-3 3 3 3"/>',
        'grid'=>'<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
        'list'=>'<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'board'=>'<rect x="3" y="4" width="18" height="16" rx="3"/><path d="M9 4v16M15 4v16M5 8h2M11 8h2M17 8h2"/>',
        'timeline'=>'<path d="M4 3v18M4 6h12M8 11h12M6 16h9M4 21h17"/>',
        'form'=>'<rect x="5" y="3" width="14" height="18" rx="3"/><path d="M9 8h6M9 12h6M9 16h3"/>',
        'code'=>'<path d="m8 6-6 6 6 6m8-12 6 6-6 6m-3-15-2 18"/>',
        'chart'=>'<path d="M3 3v18h18M8 16v-5M13 16V6M18 16V9"/>',
        'settings'=>'<path d="m10 3-1 3-3 1-2 3 2 2v3l3 1 1 3h4l1-3 3-1v-3l2-2-2-3-3-1-1-3z"/><circle cx="12" cy="11" r="3"/>',
        'search'=>'<circle cx="10.5" cy="10.5" r="7"/><path d="m16 16 5 5"/>',
        'plus'=>'<path d="M12 5v14M5 12h14"/>',
        'chevron'=>'<path d="m9 5 7 7-7 7"/>',
        'down'=>'<path d="m6 9 6 6 6-6"/>',
        'more'=>'<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
        'filter'=>'<path d="M4 6h16M7 12h10M10 18h4"/><circle cx="9" cy="6" r="2"/><circle cx="15" cy="12" r="2"/>',
        'check'=>'<path d="m5 12 4 4L19 6"/>',
        'copy'=>'<rect x="8" y="8" width="12" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/>',
        'close'=>'<path d="m6 6 12 12M6 18 18 6"/>',
        'sun'=>'<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1 1M18 18l1 1M5 19l1-1M18 6l1-1"/>',
        'moon'=>'<path d="M20 14A9 9 0 0 1 10 3a9 9 0 1 0 10 11z"/>',
        'arrow'=>'<path d="M5 12h14m-5-5 5 5-5 5"/>',
        'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'user'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
        'lock'=>'<rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'bell'=>'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
        'download'=>'<path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/>',
        'upload'=>'<path d="M12 16V4m-5 5 5-5 5 5M4 16v5h16v-5"/>',
        'flag'=>'<path d="M5 22V3m0 1c5-4 9 4 14 0v10c-5 4-9-4-14 0"/>',
        'spark'=>'<path d="m12 2 3 7 7 3-7 3-3 7-3-7-7-3 7-3z"/>',
        'external'=>'<path d="M14 3h7v7m0-7L10 14M10 3H4v17h17v-6"/>',
        'mail'=>'<rect x="3" y="5" width="18" height="14" rx="3"/><path d="m3 6 9 7 9-7"/>',
        'link'=>'<path d="m10 13 4-4M8 16l-2 2a4 4 0 0 1-6-6l5-5a4 4 0 0 1 6 0m2 1 2-2a4 4 0 0 1 6 6l-5 5a4 4 0 0 1-6 0"/>',
    ];
    return '<svg class="icon '.e($class).'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name]??$paths['grid']).'</svg>';
}
function avatar(?string $name, string $extra='', ?int $userId=null): string { $image=\App\Models\UserAvatar::url($userId); $parts=preg_split('/\s+/u',trim($name??'')); $initials=$name?mb_substr($parts[0],0,1).(count($parts)>1?mb_substr(end($parts),0,1):''):'–'; return '<span class="avatar '.e($extra).'" title="'.e($name??'Non assigné').'">'.($image?'<img class="avatar-image" src="'.e($image).'" alt="'.e($name).'">':e($initials)).'</span>'; }
