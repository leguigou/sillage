<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, DB};
use App\Models\{Team, User};

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

    public function delete(string $id): void
    {
        Auth::admin();
        Team::delete((int) $id);
        flash('Équipe supprimée. Les comptes et les tickets sont conservés.');
        redirect('/settings#teams');
    }

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
