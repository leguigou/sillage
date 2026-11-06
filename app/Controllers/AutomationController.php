<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;
use App\Models\Automation;

/**
 * Automation rules ("when <event> on a ticket matching <conditions>, apply
 * <actions>"), managed from the /automation screen.
 *
 * - POST /automation: create or update a rule.
 * - POST /automation/{id}/toggle: enable/disable a rule.
 * - POST /automation/{id}/delete.
 * Admin only. The controller only maps form fields to arrays; validation,
 * optimistic locking ("version") and storage are in App\Models\Automation.
 */
final class AutomationController
{
    /**
     * Creates or updates a rule (id=0 means create). Empty form fields are
     * skipped; "none" for assignee means "unassigned" (null).
     */
    public function save(): void
    {
        $u = Auth::admin();
        $conditions = [];
        $actions = [];
        foreach (Automation::CONDITIONS as $field) {
            $value = (string) ($_POST['condition_' . $field] ?? '');
            if ($value === '') {
                continue;
            }
            $conditions[$field] =
                $field === 'assignee_id'
                    ? ($value === 'none'
                        ? null
                        : (int) $value)
                    : ($field === 'flagged'
                        ? $value === '1'
                        : $value);
        }
        foreach (
            ['status', 'priority', 'assignee_id', 'flagged', 'estimated_hours', 'comment']
            as $field
        ) {
            $value = trim((string) ($_POST['action_' . $field] ?? ''));
            if ($value === '') {
                continue;
            }
            $actions[$field] =
                $field === 'assignee_id'
                    ? ($value === 'none'
                        ? null
                        : (int) $value)
                    : ($field === 'flagged'
                        ? $value === '1'
                        : $value);
        }
        // Explicit checkboxes are needed to clear the estimate or replace labels,
        // since an empty field above means "do not touch this field".
        if (!empty($_POST['clear_hours'])) {
            $actions['estimated_hours'] = null;
        }
        if (!empty($_POST['replace_labels'])) {
            $actions['labels'] = array_values(
                array_filter(
                    array_map('trim', explode(',', (string) ($_POST['action_labels'] ?? ''))),
                ),
            );
        }
        $r = Automation::save(
            [
                'id' => (int) ($_POST['id'] ?? 0),
                'version' => (int) ($_POST['version'] ?? 0),
                'project_id' => (int) ($_POST['project_id'] ?? 0),
                'name' => (string) ($_POST['name'] ?? ''),
                'event' => (string) ($_POST['event'] ?? ''),
                'enabled' => !empty($_POST['enabled']),
                'conditions' => $conditions,
                'actions' => $actions,
            ],
            (int) $u['id'],
        );
        flash('Automatisation enregistrée.');
        redirect('/automation?project=' . $r['project_id']);
    }
    public function toggle(string $id): void
    {
        Auth::admin();
        $r = Automation::find((int) $id);
        Automation::enabled((int) $id, !$r['enabled'], (int) ($_POST['version'] ?? 0));
        flash('Automatisation mise à jour.');
        redirect('/automation?project=' . $r['project_id']);
    }
    public function delete(string $id): void
    {
        Auth::admin();
        $r = Automation::find((int) $id);
        Automation::delete((int) $id, (int) ($_POST['version'] ?? 0));
        flash('Automatisation supprimée.');
        redirect('/automation?project=' . $r['project_id']);
    }
}
