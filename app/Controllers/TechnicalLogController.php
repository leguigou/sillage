<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;
use App\Services\TechnicalLog;
final class TechnicalLogController
{
    public function index(): void
    {
        Auth::admin();
        header('Cache-Control: no-store');
        json_response(TechnicalLog::page($_GET));
    }
}
