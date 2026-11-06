<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\{DB,Security};
use App\Services\{LlmProtocol,LlmClient};

/**
 * Connection profiles for external LLM providers (table llm_profiles) and the installation default
 * (llm_settings row id=1, default_profile_id).
 *
 * API keys are stored encrypted (Security::encrypt) and never returned by the listing queries
 * (PUBLIC_FIELDS). Saving is two-step: candidate() builds the encrypted configuration so the caller
 * can test the connection and list models, then saveVerified() stores it with one of those models.
 * Every change is version-checked and serialized by locking the llm_settings row. generate() refuses
 * to run if the profile changed since the caller loaded it. Protocol details live in LlmProtocol/LlmClient.
 */
final class LlmProfile
{
    private const PUBLIC_FIELDS='p.id,p.name,p.provider,p.base_url,p.model,p.enabled,p.version,p.verified_at,p.last_used_at';
    public static function all(): array
    {
        return DB::all('SELECT '.self::PUBLIC_FIELDS.',(s.default_profile_id=p.id) is_default
            FROM llm_profiles p CROSS JOIN llm_settings s
            ORDER BY p.name,p.id');
    }
    /** The default profile, or null when none is set or it is disabled. */
    public static function current(): ?array
    {
        return DB::one('SELECT '.self::PUBLIC_FIELDS.' FROM llm_profiles p JOIN llm_settings s ON s.default_profile_id=p.id WHERE s.id=1 AND p.enabled=1');
    }
    /**
     * Validates provider/URL/key input into an unsaved configuration (key encrypted). An empty key
     * reuses the stored one only if provider and URL are unchanged, so a saved key is never sent to a new endpoint.
     */
    public static function candidate(array $input): array
    {
        $id=(int)($input['id']??0);
        $old=$id?DB::one('SELECT * FROM llm_profiles WHERE id=?',[$id]):null;
        if ($id && (!$old || (int)$old['version']!==(int)($input['version']??0))) throw new \InvalidArgumentException('Ce profil a changé. Rechargez sa configuration.');
        $provider=(string)($input['provider']??'');$url=rtrim(trim((string)($input['base_url']??'')),'/');
        $token=trim((string)($input['token']??''));
        if ($token==='' && $old && $old['provider']===$provider && $old['base_url']===$url) $token=Security::decrypt($old['secret_cipher']);
        elseif ($token==='' && $old && Security::decrypt($old['secret_cipher'])!=='') throw new \InvalidArgumentException('Renseignez une nouvelle clé lorsque le fournisseur ou l’URL change.');
        $config=LlmProtocol::configuration($provider,$url,$token);
        return ['id'=>$id,'version'=>(int)($old['version']??0),'provider'=>$provider,'base_url'=>$config['base_url'],'secret_cipher'=>Security::encrypt($config['token'])];
    }
    /** Stores a tested candidate; $candidate['models'] must hold the models returned by the connection test. */
    public static function saveVerified(array $candidate,array $input): int
    {
        $name=trim((string)($input['name']??''));$model=(string)($input['model']??'');
        if ($name==='' || mb_strlen($name)>120 || preg_match('/[\x00-\x1f\x7f]/',$name)) throw new \InvalidArgumentException('Donnez un nom au profil (120 caractères maximum).');
        if (!in_array($model,array_column($candidate['models'],'id'),true)) throw new \InvalidArgumentException('Choisissez un modèle retourné par la connexion testée.');
        $enabled=!empty($input['enabled']);$default=!empty($input['make_default']);
        if ($default && !$enabled) throw new \InvalidArgumentException('Un profil par défaut doit être activé.');
        return DB::transaction(function() use($candidate,$name,$model,$enabled,$default) {
            DB::one('SELECT id FROM llm_settings WHERE id=1 FOR UPDATE');
            $id=(int)$candidate['id'];
            if ($id) {
                $old=DB::one('SELECT version FROM llm_profiles WHERE id=? FOR UPDATE',[$id]);
                if (!$old || (int)$old['version']!==(int)$candidate['version']) throw new \InvalidArgumentException('Ce profil a changé. Testez à nouveau sa connexion.');
                DB::run('UPDATE llm_profiles
                    SET name=?,provider=?,base_url=?,secret_cipher=?,model=?,enabled=?,verified_at=UTC_TIMESTAMP(),version=version+1
                    WHERE id=?',[$name,$candidate['provider'],$candidate['base_url'],$candidate['secret_cipher'],$model,(int)$enabled,$id]);
            } else $id=DB::insert('INSERT INTO llm_profiles(name,provider,base_url,secret_cipher,model,enabled,verified_at)
                VALUES(?,?,?,?,?,?,UTC_TIMESTAMP())',[$name,$candidate['provider'],$candidate['base_url'],$candidate['secret_cipher'],$model,(int)$enabled]);
            if ($default) DB::run('UPDATE llm_settings SET default_profile_id=? WHERE id=1',[$id]);
            else DB::run('UPDATE llm_settings SET default_profile_id=NULL WHERE id=1 AND default_profile_id=?',[$id]);
            return $id;
        });
    }
    /** 'default' enables the profile and makes it the default; 'disable' also clears it as default; 'delete' removes it. */
    public static function action(int $id,int $version,string $action): void
    {
        if (!in_array($action,['default','disable','delete'],true)) throw new \InvalidArgumentException('Action LLM invalide.');
        DB::transaction(function() use($id,$version,$action) {
            DB::one('SELECT id FROM llm_settings WHERE id=1 FOR UPDATE');
            $row=DB::one('SELECT version FROM llm_profiles WHERE id=? FOR UPDATE',[$id]);
            if (!$row || (int)$row['version']!==$version) throw new \InvalidArgumentException('Ce profil a changé. Rechargez la page.');
            if ($action==='delete') DB::run('DELETE FROM llm_profiles WHERE id=?',[$id]);
            elseif ($action==='default') {
                DB::run('UPDATE llm_profiles SET enabled=1,version=version+1 WHERE id=?',[$id]);
                DB::run('UPDATE llm_settings SET default_profile_id=? WHERE id=1',[$id]);
            } else {
                DB::run('UPDATE llm_profiles SET enabled=0,version=version+1 WHERE id=?',[$id]);
                DB::run('UPDATE llm_settings SET default_profile_id=NULL WHERE id=1 AND default_profile_id=?',[$id]);
            }
        });
    }
    /** Sends one prompt with the enabled profile matching $profile's id and version; returns the generated text. */
    public static function generate(array $profile,string $system,string $input): string
    {
        $row=DB::one('SELECT provider,base_url,secret_cipher,model,version FROM llm_profiles WHERE id=? AND enabled=1',[$profile['id']]);
        if (!$row || (int)$row['version']!==(int)$profile['version']) throw new \InvalidArgumentException('La configuration LLM a changé. Rechargez la page.');
        $config=LlmProtocol::configuration($row['provider'],$row['base_url'],Security::decrypt($row['secret_cipher']));
        $text=LlmClient::generate($config,$row['model'],$system,$input);
        DB::run('UPDATE llm_profiles SET last_used_at=UTC_TIMESTAMP() WHERE id=?',[$profile['id']]);
        return $text;
    }
}
