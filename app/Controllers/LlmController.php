<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth,Security};
use App\Models\{LlmProfile,Project};
use App\Services\{LlmClient,TicketAssistant};

final class LlmController
{
    public function probe(): void
    {
        $user=Auth::admin();Security::limit('llm-probe-'.$user['id'],20,600);
        unset($_SESSION['llm_verified']);
        $candidate=LlmProfile::candidate($_POST);
        $config=['provider'=>$candidate['provider'],'base_url'=>$candidate['base_url'],'token'=>Security::decrypt($candidate['secret_cipher'])];
        $models=LlmClient::discover($config);
        if (!$models) throw new \InvalidArgumentException('Connexion réussie, mais aucun modèle de texte disponible. Installez ou autorisez un modèle puis réessayez.');
        $proof=bin2hex(random_bytes(24));
        $_SESSION['llm_verified']=$candidate+['proof'=>$proof,'expires'=>time()+600,'models'=>$models];
        json_response(['ok'=>true,'proof'=>$proof,'models'=>$models,'message'=>'Connexion vérifiée. Choisissez un modèle puis enregistrez le profil.']);
    }
    public function save(): void
    {
        Auth::admin();$candidate=$_SESSION['llm_verified']??null;
        if (!$candidate || $candidate['expires']<time() || !hash_equals($candidate['proof'],(string)($_POST['proof']??''))) throw new \InvalidArgumentException('Test de connexion absent ou expiré. Testez à nouveau.');
        $id=LlmProfile::saveVerified($candidate,$_POST);
        unset($_SESSION['llm_verified']);
        json_response(['ok'=>true,'id'=>$id]);
    }
    public function action(string $id): void
    {
        Auth::admin();LlmProfile::action((int)$id,(int)($_POST['version']??0),(string)($_POST['action']??''));
        flash('Configuration LLM mise à jour.');redirect('/settings#llm');
    }
    public function assist(): void
    {
        $user=Auth::requireUser();
        $mode=(string)($_POST['mode']??'');
        if (!in_array($mode,['draft','rewrite','summarize','search'],true)) throw new \InvalidArgumentException('Aide LLM inconnue.');
        if ($mode!=='search') Auth::editor();
        Security::limit('llm-assist-'.$user['id'],30,600);
        $profile=LlmProfile::current()??throw new \InvalidArgumentException('Aucun fournisseur LLM par défaut activé.');
        // Bind the displayed recipient to this request, so a default change cannot silently redirect ticket content.
        if ((int)($_POST['profile_id']??0)!==(int)$profile['id'] || (int)($_POST['profile_version']??0)!==(int)$profile['version']) throw new \InvalidArgumentException('Le fournisseur par défaut a changé. Rechargez la page avant de réessayer.');
        $text=trim((string)($_POST['text']??''));
        if ($text==='' || mb_strlen($text)>6000) throw new \InvalidArgumentException('Saisissez entre 1 et 6 000 caractères.');
        $project=(int)($_POST['project_id']??0);
        if ($project) Project::find($project);
        $sources=[];$total=0;
        if ($mode==='search') {
            $found=TicketAssistant::retrieve($project,$text);$total=$found['total'];
            foreach($found['tickets'] as $ticket) $sources[]=['id'=>$ticket['id'],'key'=>$ticket['ticket_key'],'title'=>$ticket['title']];
            if (!$sources) json_response(['ok'=>true,'text'=>'Aucun ticket ne correspond aux mots recherchés. Essayez un sujet plus précis ou une clé de ticket.','sources'=>[],'total'=>0,'sent'=>false]);
            $input="Question : ".$text."\nTickets retrouvés par recherche lexicale (extraits partiels, limite 30) :\n".json_encode($found['tickets'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $instruction='Réponds uniquement à partir des tickets fournis. Cite leurs clés dans ton texte et signale les informations manquantes. N’invente pas de ticket ou de conclusion sur des tickets non examinés.';
        } else {
            $instruction=match($mode) { 'draft'=>'Rédige une description de ticket structurée : contexte, résultat attendu, critères de validation. N’invente pas les détails absents ; indique les points à préciser.', 'rewrite'=>'Reformule le texte pour le rendre clair et concis en conservant les faits et le sens.',default=>'Résume le texte en mettant en évidence le besoin, les faits et les questions ouvertes.' };
            $input=$text;
        }
        $answer=LlmProfile::generate($profile,TicketAssistant::SYSTEM.' '.$instruction,$input);
        json_response(['ok'=>true,'text'=>$answer,'sources'=>$sources,'total'=>$total,'sent'=>true]);
    }
}
