<?php
use App\Models\CompanyBranding;
use App\Core\Company;

$brandingInput = ['company_name'=>'Atelier & Équipe','company_subtitle'=>'Notre espace partagé','company_icon'=>'⚓','version'=>0];
CompanyBranding::save($brandingInput);
check(Company::name() === 'Atelier & Équipe', 'nom personnalisé partagé par la configuration société');
check(Company::subtitle() === 'Notre espace partagé' && str_contains(Company::mark(), '⚓'), 'sous-titre et symbole personnalisés');
rejects(fn() => CompanyBranding::save($brandingInput), 'écriture concurrente de l’identité refusée');
foreach (['',"Entreprise\nAutre",str_repeat('x',121)] as $badName) {
    rejects(fn() => CompanyBranding::save(array_replace($brandingInput,['company_name'=>$badName,'version'=>1])), 'nom entreprise invalide refusé');
}
rejects(fn() => CompanyBranding::save(array_replace($brandingInput,['company_icon'=>'ABCDE','version'=>1])), 'symbole trop long refusé');
$path = tempnam(sys_get_temp_dir(),'sillage-logo-');
try {
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    $logo = \App\Services\ImageUpload::validate($path);
    CompanyBranding::save(array_replace($brandingInput,['version'=>1]),$logo);
    $record = CompanyBranding::get();
    check($record['logo_digest'] === hash('sha256',$logo['data']) && str_contains(Company::mark(),'/company/logo/'), 'logo image sauvegardé avec empreinte en base');
    rejects(fn() => CompanyBranding::save(array_replace($brandingInput,['remove_logo'=>1,'version'=>2]),$logo), 'retrait et remplacement simultanés refusés');
    CompanyBranding::save(array_replace($brandingInput,['remove_logo'=>1,'version'=>2]));
    check(CompanyBranding::get()['logo_digest'] === null && str_contains(Company::mark(),'⚓'), 'retrait du logo restaure le symbole');
    file_put_contents($path,'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    rejects(fn() => \App\Services\ImageUpload::validate($path), 'logo SVG actif refusé');
    file_put_contents($path,str_repeat('x',2*1024*1024+1));
    rejects(fn() => \App\Services\ImageUpload::validate($path), 'logo dépassant 2 Mo refusé');
} finally {
    unlink($path);
}
