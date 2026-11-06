<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;
use App\Services\TechnicalLog;
/**
 * JSON feed of the technical log (incoming/outgoing HTTP calls, integrations)
 * shown in the administration screen (GET /administration/logs).
 *
 * Admin only. Query-string filters are validated by TechnicalLog::page(), which
 * throws InvalidArgumentException (turned into a 422 by the router) on bad values.
 */
final class TechnicalLogController
{
    public function index(): void
    {
        Auth::admin();
        header('Cache-Control: no-store');
        json_response(TechnicalLog::page($_GET));
    }
}
