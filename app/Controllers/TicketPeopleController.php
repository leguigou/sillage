<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;
use App\Models\{Ticket,TicketPeople,Project,Team};
final class TicketPeopleController
{
    public function show(string $id): void {Auth::requireUser();$this->response(Ticket::find((int)$id));}
    public function save(string $id): void {$user=Auth::editor();$this->response(TicketPeople::save((int)$id,$_POST,(int)$user['id'],(int)($_POST['version']??0)));}
    private function response(array $t): void
    {
        $user=Auth::requireUser();$people=TicketPeople::forTickets([(int)$t['id']])[$t['id']]??[];
        $members=Project::members();\App\Models\UserAvatar::prime(array_column($members,'id'));$teams=Team::forProject((int)$t['project_id']);
        require_once ROOT.'/app/Views/icons.php';
        ob_start();require ROOT.'/app/Views/partials/ticket-people-fields.php';$html=ob_get_clean();
        ob_start();require ROOT.'/app/Views/partials/ticket-people-button.php';$button=ob_get_clean();
        header('Cache-Control: no-store');json_response(['html'=>$html,'button'=>$button,'version'=>(int)$t['version'],'key'=>$t['key']]);
    }
}
