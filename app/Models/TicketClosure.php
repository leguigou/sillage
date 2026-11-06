<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;

final class TicketClosure
{
    public const RESOLUTIONS=['completed'=>'Réalisé','fixed'=>'Corrigé','duplicate'=>'Doublon','rejected'=>'Rejeté','cannot_reproduce'=>'Non reproductible'];
    public const VALIDATIONS=['pending'=>'À valider','approved'=>'Validé','rejected'=>'À reprendre'];
    public static function resolution(array $input,int $project,string $status): ?string
    {
        $resolution=$input['resolution']??null;
        if ($resolution==='' || $resolution===null) $resolution=null;
        if ($resolution!==null && (!is_string($resolution) || !isset(self::RESOLUTIONS[$resolution]))) throw new \InvalidArgumentException('Résolution de clôture invalide.');
        return Workflow::category('status',$project,$status)==='done'?($resolution??'completed'):null;
    }
    /** Content changes invalidate approval; status-only transitions preserve it. */
    public static function prepare(?array $old,array $data,int $project): array
    {
        $state=$old['validation_state']??'pending';$by=$old['validated_by']??null;$at=$old['validated_at']??null;$note=$old['validation_note']??null;
        $changed=$old?array_column(TicketHistory::changes($old,$data+['project_id'=>$project]),'field'):[];
        $changed=array_diff($changed,['status','resolution','validation_state','validated_by','validated_at','validation_note','archived']);
        $reopened=$old && Workflow::category('status',$project,$old['status'])==='done' && Workflow::category('status',$project,$data['status'])!=='done';
        if ($changed || $reopened) {$state='pending';$by=null;$at=null;$note=null;}
        if (Workflow::category('status',$project,$data['status'])==='done' && Project::find($project)['require_ticket_validation'] && $state!=='approved') throw new \InvalidArgumentException('Ce projet exige une validation avant la clôture. Enregistrez les modifications, faites valider le ticket, puis terminez-le.');
        return ['validation_state'=>$state,'validated_by'=>$by,'validated_at'=>$at,'validation_note'=>$note];
    }
    public static function review(int $ticket,bool $approved,string $note,int $actor,int $version): array
    {
        return DB::transaction(function() use($ticket,$approved,$note,$actor,$version) {
            $old=Ticket::lock($ticket,$version);
            $person=DB::one('SELECT role FROM users WHERE id=? AND active=1 AND verified_at IS NOT NULL',[$actor]);
            if (!$person || $person['role']==='viewer') throw new \InvalidArgumentException('La validation est réservée aux collaborateurs.');
            $note=trim($note);
            if (mb_strlen($note)>2000 || (!$approved && $note==='')) throw new \InvalidArgumentException('Précisez la raison de la reprise (2 000 caractères maximum).');
            if ($old['archived'] || in_array($old['status_category'],['done','paused'],true)) throw new \InvalidArgumentException('Le ticket doit être actif et ouvert pour être validé.');
            DB::run('UPDATE tickets
                SET validation_state=?,validated_by=?,validated_at=UTC_TIMESTAMP(),validation_note=?,version=version+1,updated_at=UTC_TIMESTAMP()
                WHERE id=?',[$approved?'approved':'rejected',$actor,$note,$ticket]);
            $after=Ticket::find($ticket);
            TicketHistory::record($old,$after,$actor,$approved?'validation.approved':'validation.rejected');
            Ticket::activity((int)$old['project_id'],$ticket,$actor,$approved?'a validé le ticket':'a demandé une reprise du ticket');
            \App\Services\Notifications::changed($old,$after,$actor);
            return $after;
        });
    }
}
