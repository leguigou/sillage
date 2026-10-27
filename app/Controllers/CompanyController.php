<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, DB};
use App\Models\CompanyBranding;

final class CompanyController
{
    public function save(): void
    {
        Auth::admin();
        CompanyBranding::save($_POST, \App\Services\ImageUpload::fromUpload($_FILES['company_logo'] ?? [], 'Le logo'));
        flash('Les informations de l’entreprise ont été enregistrées.');
        redirect('/settings#company');
    }

    /** Branding is intentionally public so the same logo appears before sign-in. */
    public function logo(string $digest): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $digest)) {
            http_response_code(404);
            return;
        }
        $logo = DB::one('SELECT logo_data,logo_mime FROM company_branding WHERE id=1 AND logo_digest=?',[$digest]);
        if (!$logo || !$logo['logo_data'] || !in_array($logo['logo_mime'],['image/png','image/jpeg','image/webp'],true)) {
            http_response_code(404);
            return;
        }
        header('Content-Type: ' . $logo['logo_mime']);
        header('Content-Disposition: inline; filename="company-logo"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($logo['logo_data']));
        echo $logo['logo_data'];
    }
}
