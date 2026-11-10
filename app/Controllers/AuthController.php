<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, Company, DB, Mailer, Security, View};
/**
 * Account authentication screens and actions (no role required, except
 * password change which needs a signed-in user).
 *
 * - GET/POST /login, /register, /forgot, /reset: the "auth" view in its
 *   different modes and the matching form handlers.
 * - GET /verify?token=...: confirms an e-mail address.
 * - POST /logout, POST /account/password.
 * Sensitive POSTs are rate-limited per client IP via Security::limit().
 * One-time e-mail tokens are stored only as SHA-256 hashes in auth_tokens.
 * Self-registered accounts get the database default role ("member").
 */
final class AuthController
{
    /** Renders the login/register/forgot/reset form; signed-in users are sent to the dashboard. */
    public function page(string $mode = 'login'): void
    {
        if (Auth::user()) {
            redirect('/');
        }
        View::render('auth', [
            'guest' => true,
            'mode' => $mode,
            'title' => [
                'login' => 'Heureux de vous retrouver.',
                'register' => 'Votre prochain chapitre.',
                'forgot' => 'Retrouvons votre accès.',
                'reset' => 'Un nouveau départ.',
            ][$mode],
        ]);
    }
    public function login(): void
    {
        // Max 10 attempts per IP per 15 min (default window).
        Security::limit('login', 10);
        $email = Security::email((string) ($_POST['email'] ?? ''), false);
        $u = DB::one('SELECT * FROM users WHERE email=?', [$email]);
        // A dummy hash is checked when the e-mail is unknown, so the response time
        // does not reveal whether an account exists.
        $valid = password_verify(
            (string) ($_POST['password'] ?? ''),
            $u['password_hash'] ?? '$2y$12$K2JwJHa5W5XJ9quqeFf04OiLaKwcRwl0Hso4xA7V2P4my7BRRxSZe',
        );
        if (!$u || !$valid || !$u['active']) {
            throw new \InvalidArgumentException('Adresse ou mot de passe incorrect.');
        }
        if (!$u['verified_at']) {
            throw new \InvalidArgumentException(
                'Validez votre adresse grâce au lien reçu par e-mail.',
            );
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            DB::run('UPDATE users SET password_hash=? WHERE id=?', [
                Security::password((string) $_POST['password']),
                $u['id'],
            ]);
        }
        // If login was triggered by an OAuth authorization request, resume it.
        // Only /oauth/authorize?... paths are accepted, to avoid an open redirect.
        $return=$_SESSION['oauth_after_login']??'/';
        Auth::login($u);
        redirect(is_string($return) && str_starts_with($return,'/oauth/authorize?') && strlen($return)<=8192?$return:'/');
    }
    public function register(): void
    {
        Security::limit('register', 5, 3600);
        $email = Security::email((string) ($_POST['email'] ?? ''));
        $hash = Security::password((string) ($_POST['password'] ?? ''));
        // Same neutral message for an existing address: does not reveal registered e-mails.
        if (DB::one('SELECT id FROM users WHERE email=?', [$email])) {
            flash('Si votre adresse peut être inscrite, vous recevrez un lien de validation.');
            redirect('/login');
        }
        $name = trim((string) ($_POST['name'] ?? '')) ?: Security::nameFromEmail($email);
        if (mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Le nom est trop long.');
        }
        $id = DB::insert('INSERT INTO users(email,name,password_hash) VALUES(?,?,?)', [
            $email,
            $name,
            $hash,
        ]);
        $this->token($id, $email, 'verify');
        flash('Votre compte est créé. Consultez votre e-mail pour activer votre accès.');
        redirect('/login');
    }
    /**
     * Replaces any previous token of the same purpose ("verify": valid 24 h,
     * "reset": valid 1 h) and e-mails the link containing the raw token.
     */
    private function token(int $id, string $email, string $purpose): void
    {
        $token = bin2hex(random_bytes(32));
        DB::run('DELETE FROM auth_tokens WHERE user_id=? AND purpose=?', [$id, $purpose]);
        DB::run('INSERT INTO auth_tokens(user_id,token_hash,purpose,expires_at) VALUES(?,?,?,?)', [
            $id,
            hash('sha256', $token),
            $purpose,
            gmdate('Y-m-d H:i:s', time() + ($purpose === 'verify' ? 86400 : 3600)),
        ]);
        $link = url('/' . ($purpose === 'verify' ? 'verify' : 'reset') . '?token=' . $token);
        $name = (string) (DB::one('SELECT name FROM users WHERE id=?', [$id])['name'] ?? '');
        $greeting = $name === '' ? 'Bonjour,' : 'Bonjour ' . $name . ',';
        $message = $purpose === 'verify'
            ? [
                'preheader' => 'Confirmez votre adresse pour activer votre accès à ' . app_name() . '.',
                'eyebrow' => 'Bienvenue',
                'title' => 'Confirmez votre adresse e-mail',
                'greeting' => $greeting,
                'paragraphs' => [
                    'Votre compte ' . app_name() . ' est presque prêt. Il ne reste qu’à confirmer que cette adresse est bien la vôtre.',
                ],
                'action' => ['label' => 'Confirmer mon adresse', 'url' => $link],
                'note' => 'Ce lien est valable 24 heures et ne peut servir qu’une fois. Si vous n’êtes pas à l’origine de cette demande, ignorez simplement cet e-mail.',
            ]
            : [
                'preheader' => 'Choisissez un nouveau mot de passe — le lien est valable une heure.',
                'eyebrow' => 'Sécurité du compte',
                'title' => 'Réinitialisez votre mot de passe',
                'greeting' => $greeting,
                'paragraphs' => [
                    'Une demande de réinitialisation du mot de passe a été faite pour votre compte ' . app_name() . '.',
                    'Choisissez un nouveau mot de passe : vous serez ensuite connecté directement, et vos autres sessions seront fermées.',
                ],
                'action' => ['label' => 'Choisir un nouveau mot de passe', 'url' => $link],
                'note' => 'Ce lien est valable 1 heure et ne peut servir qu’une fois. Si vous n’avez rien demandé, ignorez cet e-mail : votre mot de passe actuel reste inchangé.',
            ];
        $mail = \App\Services\EmailTemplate::render($message);
        Mailer::send(
            $email,
            $purpose === 'verify'
                ? Company::name() . ' — validez votre adresse dans ' . app_name()
                : Company::name() . ' — votre lien de réinitialisation ' . app_name(),
            $mail['text'],
            $mail['html'],
        );
    }
    public function verify(): void
    {
        $token = (string) ($_GET['token'] ?? '');
        DB::transaction(function () use ($token) {
            $t = DB::one(
                "SELECT * FROM auth_tokens WHERE token_hash=? AND purpose='verify' AND expires_at>UTC_TIMESTAMP() FOR UPDATE",
                [hash('sha256', $token)],
            );
            if (!$t) {
                throw new \InvalidArgumentException(
                    'Ce lien de validation a expiré ou a déjà été utilisé.',
                );
            }
            DB::run('UPDATE users SET verified_at=UTC_TIMESTAMP() WHERE id=?', [$t['user_id']]);
            DB::run('DELETE FROM auth_tokens WHERE id=?', [$t['id']]);
        });
        flash('Adresse confirmée. Vous pouvez vous connecter.');
        redirect('/login');
    }
    public function forgot(): void
    {
        Security::limit('forgot', 5, 3600);
        $email = Security::email((string) ($_POST['email'] ?? ''), false);
        $u = DB::one('SELECT id,email,verified_at FROM users WHERE email=? AND active=1', [$email]);
        // Unverified accounts get a new verification link instead of a reset link.
        // The flash message is identical whether or not the account exists.
        if ($u) {
            $this->token((int) $u['id'], $u['email'], $u['verified_at'] ? 'reset' : 'verify');
        }
        flash(
            'Si un compte actif correspond, un lien pour retrouver ou activer votre accès vous a été envoyé.',
        );
        redirect('/login');
    }
    public function reset(): void
    {
        Security::limit('reset', 8);
        $hash = Security::password((string) ($_POST['password'] ?? ''));
        $userId = DB::transaction(function () use ($hash) {
            $t = DB::one(
                "SELECT * FROM auth_tokens WHERE token_hash=? AND purpose='reset' AND expires_at>UTC_TIMESTAMP() FOR UPDATE",
                [hash('sha256', (string) ($_POST['token'] ?? ''))],
            );
            if (!$t) {
                throw new \InvalidArgumentException('Ce lien a expiré ou a déjà été utilisé.');
            }
            // Bumping auth_version invalidates existing sessions; all tokens and
            // "remember me" logins of the user are revoked too.
            DB::run('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?', [
                $hash,
                $t['user_id'],
            ]);
            DB::run('DELETE FROM auth_tokens WHERE user_id=?', [$t['user_id']]);
            \App\Core\PersistentLogin::revokeAll((int)$t['user_id']);
            return (int) $t['user_id'];
        });
        // The single-use link proved control of the mailbox and the new password was just chosen:
        // open a fresh session (new auth_version, new remember-me cookie) instead of asking to sign
        // in again. Every other session and device was revoked above.
        Auth::logout();
        $user = DB::one('SELECT id,email,name,role,verified_at,active,auth_version FROM users WHERE id=?', [$userId]);
        if ($user && $user['active'] && $user['verified_at']) {
            try {
                Auth::login($user);
                flash('Mot de passe modifié. Vous êtes connecté ; vos autres sessions ont été fermées.');
                redirect('/');
            } catch (\InvalidArgumentException) {
                // The account changed concurrently: fall back to a normal sign-in.
            }
        }
        flash('Mot de passe modifié. Reconnectez-vous.');
        redirect('/login');
    }
    public function logout(): void
    {
        Auth::logout();
        redirect('/login');
    }
    /** Changes the signed-in user's password, then signs them out everywhere. */
    public function changePassword(): void
    {
        $u = Auth::requireUser();
        Security::limit('change-password', 5, 900);
        $record = DB::one('SELECT password_hash FROM users WHERE id=?', [$u['id']]);
        if (
            !password_verify((string) ($_POST['current_password'] ?? ''), $record['password_hash'])
        ) {
            throw new \InvalidArgumentException('Le mot de passe actuel est incorrect.');
        }
        $hash = Security::password((string) ($_POST['new_password'] ?? ''));
        DB::run('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?', [
            $hash,
            $u['id'],
        ]);
        \App\Core\PersistentLogin::revokeAll((int)$u['id']);
        Auth::logout();
        flash('Mot de passe modifié. Reconnectez-vous.');
        redirect('/login');
    }
}
