<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, DB};
use App\Models\{Team, User};

/**
 * Team and user administration (the "teams" and "users" sections of /settings).
 *
 * - POST /teams: create or rename a team (team_id=0 creates).
 * - POST /teams/{id}/members, /teams/{id}/projects: add/remove a user or a
 *   project to/from the team.
 * - POST /teams/{id}/delete: delete a team (accounts and tickets are kept).
 * - POST /users/{id}: change a user's role and active flag.
 * Admin only. Rules (e.g. always keeping one active admin) are enforced in
 * App\Models\Team and App\Models\User.
 */
final class TeamController
{
    public function save(): void
    {
        Auth::admin();
        Team::save((int) ($_POST['team_id'] ?? 0) ?: null, (string) ($_POST['name'] ?? ''));
        flash('Équipe enregistrée.');
        redirect('/settings#teams');
    }

    public function membership(string $id): void
    {
        Auth::admin();
        $action = (string) ($_POST['action'] ?? '');
        if (!in_array($action, ['add', 'remove'], true)) {
            throw new \InvalidArgumentException('Action d’équipe invalide.');
        }
        Team::membership((int) $id, (int) ($_POST['user_id'] ?? 0), $action === 'add');
        flash(
            $action === 'add'
                ? 'Utilisateur ajouté à l’équipe.'
                : 'Utilisateur retiré de l’équipe.',
        );
        redirect('/settings#teams');
    }

    public function project(string $id): void
    {
        Auth::admin();
        $action=(string)($_POST['action']??'');
        if (!in_array($action,['add','remove'],true)) throw new \InvalidArgumentException('Action invalide.');
        Team::projectMembership((int)$id,(int)($_POST['project_id']??0),$action==='add');
        flash('Projets de l’équipe mis à jour.');
        redirect('/settings#teams');
    }

    public function delete(string $id): void
    {
        $user=Auth::admin();
        Team::delete((int) $id,(int)$user['id']);
        flash('Équipe supprimée. Les comptes et les tickets sont conservés.');
        redirect('/settings#teams');
    }

    /** Sets role and active state of user {id}; the affected user's sessions are closed. */
    public function user(string $id): void
    {
        $actor = Auth::admin();
        $active = (string) ($_POST['active'] ?? '');
        if (!in_array($active, ['0', '1'], true)) {
            throw new \InvalidArgumentException('État du compte invalide.');
        }
        User::manage(
            (int) $id,
            (string) ($_POST['role'] ?? ''),
            $active === '1',
            (int) $actor['id'],
        );
        flash('Accès mis à jour. Les sessions concernées ont été fermées.');
        redirect('/settings#users');
    }
}
