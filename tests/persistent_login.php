<?php
use App\Core\{DB,Security,PersistentLogin};
$rememberUser=DB::insert("INSERT INTO users(email,name,password_hash,role,verified_at) VALUES(?,?,?,'member',UTC_TIMESTAMP())",['remember.'.bin2hex(random_bytes(4)).'@example.com','Connexion persistante',Security::password('Persistent-Test-2026!')]);
$account=DB::one('SELECT * FROM users WHERE id=?',[$rememberUser]);
$deadline=time()+PersistentLogin::LIFETIME;
$first=PersistentLogin::issue($account,$deadline);$second=PersistentLogin::issue($account,$deadline);
check($first['expires']===time()+30*86400 || abs($first['expires']-(time()+30*86400))<3,'connexion persistante limitée à trente jours');
$stored=DB::one('SELECT * FROM persistent_logins WHERE selector=?',[$first['selector']]);
check(!str_contains(json_encode($stored),explode(':',$first['cookie'])[1]) && strlen($stored['validator_hash'])===64,'base conserve uniquement l’empreinte du secret de connexion');
$restored=PersistentLogin::restore($first['cookie']);
check($restored['user']['id']===$rememberUser && $restored['expires']===$deadline,'restauration depuis MySQL sans dépendre de la session PHP');
$rememberOriginalUrl=getenv('APP_URL');
try {
    putenv('APP_URL=https://other-installation.example');
    check(PersistentLogin::restore($first['cookie'])===null,'cookie persistant lié cryptographiquement à son installation');
} finally { putenv($rememberOriginalUrl===false?'APP_URL':'APP_URL='.$rememberOriginalUrl); }
check(PersistentLogin::restore($first['cookie'])['expires']===$deadline,'restaurer une session ne prolonge pas la limite de trente jours');
check(PersistentLogin::restore($first['selector'].':'.str_repeat('0',64))===null && PersistentLogin::restore($first['cookie'])!==null,'cookie falsifié refusé sans révoquer un appareil légitime');
foreach(['bad',$first['cookie'].':extra',str_repeat('a',1000)] as $invalid) check(PersistentLogin::restore($invalid)===null,'format de cookie persistant invalide refusé');
PersistentLogin::revoke($first['cookie']);
check(PersistentLogin::restore($first['cookie'])===null && PersistentLogin::restore($second['cookie'])!==null,'déconnexion révoque cet appareil en conservant les autres');
DB::run('UPDATE persistent_logins SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE selector=?',[$second['selector']]);
check(PersistentLogin::restore($second['cookie'])===null,'cookie expiré inutilisable même si conservé par le navigateur');
$third=PersistentLogin::issue($account,$deadline);
DB::run('UPDATE users SET auth_version=auth_version+1 WHERE id=?',[$rememberUser]);
check(PersistentLogin::restore($third['cookie'])===null,'changement de mot de passe ou de droits invalide la connexion mémorisée');
check(PersistentLogin::issue($account,$deadline)===null,'ancienne version du compte ne peut pas créer un nouveau cookie');
$account=DB::one('SELECT * FROM users WHERE id=?',[$rememberUser]);$fourth=PersistentLogin::issue($account,$deadline);
DB::run('UPDATE users SET active=0 WHERE id=?',[$rememberUser]);
check(PersistentLogin::restore($fourth['cookie'])===null,'compte désactivé ne peut pas être restauré');
DB::run('UPDATE users SET active=1,verified_at=NULL WHERE id=?',[$rememberUser]);
check(PersistentLogin::issue(DB::one('SELECT * FROM users WHERE id=?',[$rememberUser]),$deadline)===null,'compte non vérifié ne reçoit pas de connexion persistante');
DB::run('UPDATE users SET verified_at=UTC_TIMESTAMP() WHERE id=?',[$rememberUser]);
$fifth=PersistentLogin::issue(DB::one('SELECT * FROM users WHERE id=?',[$rememberUser]),$deadline);
PersistentLogin::revokeAll($rememberUser);
check(PersistentLogin::restore($fifth['cookie'])===null,'révocation complète des connexions persistantes du compte');
