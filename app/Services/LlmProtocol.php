<?php
declare(strict_types=1);
namespace App\Services;

/** Provider wire formats are interpreted once here, independently of persistence/UI. */
final class LlmProtocol
{
    public const PROVIDERS = [
        'openai'=>['name'=>'OpenAI','url'=>'https://api.openai.com/v1','local'=>false],
        'lmstudio'=>['name'=>'LM Studio','url'=>'http://127.0.0.1:1234/v1','local'=>true],
        'ollama'=>['name'=>'Ollama','url'=>'http://127.0.0.1:11434','local'=>true],
        'deepseek'=>['name'=>'DeepSeek','url'=>'https://api.deepseek.com','local'=>false],
        'anthropic'=>['name'=>'Anthropic (Claude)','url'=>'https://api.anthropic.com/v1','local'=>false],
        'grok'=>['name'=>'Grok (xAI)','url'=>'https://api.x.ai/v1','local'=>false],
        'gemini'=>['name'=>'Gemini (Google)','url'=>'https://generativelanguage.googleapis.com/v1beta','local'=>false],
    ];
    public static function configuration(string $provider,string $url,string $token): array
    {
        $spec=self::PROVIDERS[$provider]??throw new \InvalidArgumentException('Fournisseur LLM inconnu.');
        $url=rtrim(trim($url),'/');
        $parts=parse_url($url);
        if (!$parts || strlen($url)>500 || !in_array($parts['scheme']??'',['https','http'],true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !preg_match('#^/[a-zA-Z0-9/_~.\-]*$#D',$parts['path']??'/') || str_contains($parts['path']??'','..')) throw new \InvalidArgumentException('URL de base invalide : sans identifiant, paramètres ni fragment.');
        if (!$spec['local'] && $parts['scheme']!=='https') throw new \InvalidArgumentException('HTTPS est obligatoire pour ce fournisseur distant.');
        $token=trim($token);
        if (strlen($token)>4096 || preg_match('/[\x00-\x20\x7f]/',$token)) throw new \InvalidArgumentException('Clé invalide : collez la clé seule, sans préfixe ni espace.');
        if (!$spec['local'] && $token==='') throw new \InvalidArgumentException('La clé API est requise pour ce fournisseur.');
        return ['provider'=>$provider,'base_url'=>$url,'token'=>$token];
    }
    public static function headers(array $config): array
    {
        $headers=['Accept: application/json','Content-Type: application/json'];
        if ($config['provider']==='anthropic') $headers[]='anthropic-version: 2023-06-01';
        if ($config['token']!=='') $headers[]=match($config['provider']) {
            'anthropic'=>'x-api-key: '.$config['token'],
            'gemini'=>'x-goog-api-key: '.$config['token'],
            default=>'Authorization: Bearer '.$config['token'],
        };
        return $headers;
    }
    public static function modelsPath(string $provider,string $cursor=''): string
    {
        return match($provider) {
            'ollama'=>'/api/tags',
            'anthropic'=>'/models?limit=100'.($cursor!==''?'&after_id='.rawurlencode($cursor):''),
            'gemini'=>'/models?pageSize=100'.($cursor!==''?'&pageToken='.rawurlencode($cursor):''),
            default=>'/models',
        };
    }
    public static function models(string $provider,array $data): array
    {
        $items=match($provider) { 'ollama','gemini'=>$data['models']??null, default=>$data['data']??null };
        if (!is_array($items) || !array_is_list($items) || count($items)>2000) throw new \InvalidArgumentException('Le fournisseur a renvoyé une liste de modèles invalide.');
        $models=[];
        foreach($items as $item) {
            if (!is_array($item)) throw new \InvalidArgumentException('Modèle invalide dans la réponse du fournisseur.');
            if ($provider==='gemini') {
                if (!is_array($item['supportedGenerationMethods']??null) || !is_string($item['name']??null)) throw new \InvalidArgumentException('Capacités de modèle Gemini invalides.');
                if (!in_array('generateContent',$item['supportedGenerationMethods'],true)) continue;
            }
            $id=match($provider) { 'ollama'=>$item['name']??null,'gemini'=>preg_replace('#^models/#','',$item['name']??''),default=>$item['id']??null };
            if (!is_string($id) || $id==='' || strlen($id)>200 || preg_match('/[\x00-\x20\x7f]/',$id)) throw new \InvalidArgumentException('Identifiant de modèle invalide.');
            $label=$item['displayName']??$item['display_name']??$id;
            if (!is_string($label)) throw new \InvalidArgumentException('Nom de modèle invalide.');
            $models[$id]=['id'=>$id,'name'=>mb_substr($label,0,200)];
        }
        $cursor=match($provider) { 'gemini'=>$data['nextPageToken']??'', 'anthropic'=>!empty($data['has_more'])?($data['last_id']??''):'',default=>'' };
        if (!is_string($cursor) || strlen($cursor)>2048 || ($provider==='anthropic' && !empty($data['has_more']) && $cursor==='')) throw new \InvalidArgumentException('Pagination des modèles invalide.');
        return ['models'=>array_values($models),'cursor'=>$cursor];
    }
    public static function generation(string $provider,string $model,string $system,string $input): array
    {
        $messages=[['role'=>'system','content'=>$system],['role'=>'user','content'=>$input]];
        return match($provider) {
            'openai'=>['/responses',['model'=>$model,'instructions'=>$system,'input'=>$input,'max_output_tokens'=>2000,'store'=>false]],
            'anthropic'=>['/messages',['model'=>$model,'system'=>$system,'messages'=>[['role'=>'user','content'=>$input]],'max_tokens'=>2000]],
            'gemini'=>['/models/'.rawurlencode($model).':generateContent',['systemInstruction'=>['parts'=>[['text'=>$system]]],'contents'=>[['role'=>'user','parts'=>[['text'=>$input]]]],'generationConfig'=>['maxOutputTokens'=>2000]]],
            'ollama'=>['/api/chat',['model'=>$model,'messages'=>$messages,'stream'=>false,'options'=>['num_predict'=>2000]]],
            default=>['/chat/completions',['model'=>$model,'messages'=>$messages,'stream'=>false,'max_tokens'=>2000]],
        };
    }
    public static function text(string $provider,array $data): string
    {
        $text='';
        if ($provider==='openai') {
            if (($data['status']??'completed')!=='completed') throw new \InvalidArgumentException('La génération est incomplète. Essayez une demande plus courte ou un autre modèle.');
            foreach($data['output']??[] as $item) if (($item['type']??'')==='message') foreach($item['content']??[] as $part) if (($part['type']??'')==='output_text' && is_string($part['text']??null)) $text.=$part['text'];
        } elseif ($provider==='anthropic') {
            if (($data['stop_reason']??'')==='max_tokens') throw new \InvalidArgumentException('La réponse a atteint la limite de sortie. Réduisez la demande.');
            foreach($data['content']??[] as $part) if (($part['type']??'')==='text' && is_string($part['text']??null)) $text.=$part['text'];
        } elseif ($provider==='gemini') {
            $candidate=$data['candidates'][0]??[];
            if (isset($candidate['finishReason']) && $candidate['finishReason']!=='STOP') throw new \InvalidArgumentException('Le modèle n’a pas terminé la réponse (limite ou contenu refusé).');
            foreach($candidate['content']['parts']??[] as $part) if (empty($part['thought']) && is_string($part['text']??null)) $text.=$part['text'];
        } elseif ($provider==='ollama') {
            if (($data['done_reason']??'')==='length') throw new \InvalidArgumentException('La réponse a atteint la limite de sortie. Réduisez la demande.');
            $text=$data['message']['content']??'';
        } else {
            if (($data['choices'][0]['finish_reason']??'')==='length') throw new \InvalidArgumentException('La réponse a atteint la limite de sortie. Réduisez la demande.');
            $text=$data['choices'][0]['message']['content']??'';
        }
        if (!is_string($text) || trim($text)==='' || mb_strlen($text)>16000) throw new \InvalidArgumentException('Le modèle n’a renvoyé aucun texte exploitable. Vérifiez sa compatibilité avec la génération de texte.');
        return trim($text);
    }
}
