<?php
declare(strict_types=1);
namespace App\Services;

/** Shipped release notes describe this copy of the application, without requiring Git in production. */
final class ApplicationHistory
{
    public const CATEGORIES=['features'=>'Fonctionnalités','fixes'=>'Corrections','interface'=>'Interface','deployment'=>'Installation et déploiement','docs'=>'Documentation et validation'];
    private static ?array $catalog=null;
    public static function catalog(): array
    {
        if (self::$catalog!==null) return self::$catalog;
        $contents=file_get_contents(ROOT.'/resources/application-history.json');
        if ($contents===false) throw new \RuntimeException('Historique des versions indisponible.');
        try {$document=json_decode($contents,true,64,JSON_THROW_ON_ERROR);} catch (\JsonException $error) {throw new \RuntimeException('Historique des versions : JSON invalide.',0,$error);}
        if (!is_array($document)) throw new \RuntimeException('Historique des versions invalide.');
        return self::$catalog=self::validate($document);
    }
    public static function validate(array $document): array
    {
        if (($document['schema']??null)!==1 || !self::version($document['current_version']??null) || !is_array($document['entries']??null) || !array_is_list($document['entries']) || !$document['entries']) throw new \InvalidArgumentException('Version courante ou format de l’historique invalide.');
        $ids=[];$entries=[];$previousVersion=null;
        foreach($document['entries'] as $entry) {
            if (!is_array($entry)) throw new \InvalidArgumentException('Entrée d’historique invalide.');
            $version=$entry['version']??null;$revision=$entry['revision']??null;
            if (($version===null)===($revision===null) || ($version!==null && !self::version($version)) || ($revision!==null && (!is_string($revision) || !preg_match('/^[a-f0-9]{40}$/D',$revision)))) throw new \InvalidArgumentException('Chaque entrée doit porter une version numérotée ou une révision Git complète.');
            $id=$version!==null?'version-'.$version:'revision-'.$revision;
            if (isset($ids[$id])) throw new \InvalidArgumentException('Version ou révision dupliquée.');
            $ids[$id]=true;
            if ($version!==null) {
                if ($previousVersion!==null && !version_compare($previousVersion,$version,'>')) throw new \InvalidArgumentException('Les versions doivent être classées de la plus récente à la plus ancienne.');
                $previousVersion=$version;
            }
            foreach(['title'=>160,'summary'=>600] as $field=>$limit) if (!is_string($entry[$field]??null) || trim($entry[$field])==='' || mb_strlen($entry[$field])>$limit) throw new \InvalidArgumentException('Titre ou résumé d’historique invalide.');
            if (!is_string($entry['category']??null) || !isset(self::CATEGORIES[$entry['category']])) throw new \InvalidArgumentException('Catégorie d’historique invalide.');
            $rawDate=$entry['date']??null;
            $format=is_string($rawDate) && strlen($rawDate)===10?'!Y-m-d':\DateTimeInterface::ATOM;
            $date=is_string($rawDate)?\DateTimeImmutable::createFromFormat($format,$rawDate,new \DateTimeZone('Europe/Paris')):false;
            $errors=\DateTimeImmutable::getLastErrors();
            if (!$date || ($errors!==false && ($errors['warning_count'] || $errors['error_count']))) throw new \InvalidArgumentException('Date d’historique invalide.');
            if (!is_array($entry['changes']??null) || !array_is_list($entry['changes']) || !$entry['changes']) throw new \InvalidArgumentException('Décrivez les améliorations de chaque version.');
            foreach($entry['changes'] as $change) if (!is_string($change) || trim($change)==='' || mb_strlen($change)>1000) throw new \InvalidArgumentException('Description d’amélioration invalide.');
            $local=$date->setTimezone(new \DateTimeZone('Europe/Paris'));
            $entries[]=array_replace($entry,['id'=>$id,'day'=>$local->format('Y-m-d'),'date_label'=>$local->format('d/m/Y'),'label'=>$version!==null?'Version '.$version:'Révision '.substr($revision,0,7)]);
        }
        if (($entries[0]['version']??null)!==$document['current_version']) throw new \InvalidArgumentException('La première entrée doit décrire la version courante.');
        return ['current_version'=>$document['current_version'],'entries'=>$entries];
    }
    private static function version(mixed $value): bool
    {
        return is_string($value) && strlen($value)<=80 && (bool)preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?(?:\+[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?$/D',$value);
    }
}
