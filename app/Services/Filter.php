<?php
declare(strict_types=1);
namespace App\Services;
use App\Models\Ticket;
final class Filter
{
    private array $tokens = [];
    private int $pos = 0;
    private int $depth = 0;
    public const FIELDS = [
        'title' => 't.title',
        'status' => 't.status',
        'type' => 't.type',
        'priority' => 't.priority',
        'assignee' => 'a.name',
        'team' => 'tm.name',
        'reporter' => 'u.name',
        'due' => 't.due_date',
        'start' => 't.start_date',
        'created' => 't.created_at',
        'updated' => 't.updated_at',
        'parent' => 't.parent_id',
        'flagged' => 't.flagged',
        'key' => "CONCAT(p.project_key,'-',t.number)",
        'labels' => 't.labels',
    ];
    public static function active(array $input): bool
    {
        // Project selection and display options do not restrict the result set.
        return self::where(0, $input)[0] !== self::where(0, [])[0];
    }
    public static function where(int $project, array $input): array
    {
        $sql = ['t.archived=0'];
        $params = [];
        if ($project) {
            $sql[] = 't.project_id=?';
            $params[] = $project;
        }
        $q = trim((string) ($input['q'] ?? ''));
        if ($q !== '') {
            $sql[] =
                "(t.title LIKE ? OR t.description LIKE ? OR CONCAT(p.project_key,'-',t.number) LIKE ?)";
            array_push($params, ...array_fill(0, 3, '%' . $q . '%'));
        }
        foreach (['status', 'type', 'priority'] as $field) {
            if (!empty($input[$field])) {
                $values = is_array($input[$field])
                    ? $input[$field]
                    : explode(',', (string) $input[$field]);
                $allowed = match ($field) {
                    'status' => Ticket::STATUSES,
                    'type' => Ticket::TYPES,
                    default => Ticket::PRIORITIES,
                };
                if (array_diff($values, array_keys($allowed))) {
                    throw new \InvalidArgumentException('Filtre invalide : ' . $field);
                }
                $sql[] =
                    't.' .
                    $field .
                    ' IN (' .
                    implode(',', array_fill(0, count($values), '?')) .
                    ')';
                array_push($params, ...$values);
            }
        }
        if (($input['assignee'] ?? '') !== '') {
            if ($input['assignee'] === 'none') {
                $sql[] = 't.assignee_id IS NULL';
            } else {
                $sql[] = 't.assignee_id=?';
                $params[] = (int) $input['assignee'];
            }
        }
        if (($input['team']??'')!=='') {
            if ($input['team']==='none') $sql[]='t.team_id IS NULL';
            else {
                $id=$input['team'];
                if ((!is_int($id) && !is_string($id)) || !ctype_digit((string)$id) || (int)$id<1) throw new \InvalidArgumentException('Filtre équipe invalide.');
                $sql[]='t.team_id=?'; $params[]=(int)$id;
            }
        }
        if (!empty($input['hide_done'])) {
            $sql[] = "t.status<>'done'";
        }
        if (!empty($input['flagged'])) {
            $sql[] = 't.flagged=1';
        }
        if (!empty($input['label'])) {
            $sql[] = 'JSON_CONTAINS(t.labels, ?)';
            $params[] = json_encode((string) $input['label'], JSON_THROW_ON_ERROR);
        }
        if (!empty($input['due_before'])) {
            $sql[] = 't.due_date<=?';
            $params[] = \App\Core\Security::date($input['due_before']);
        }
        if (!empty($input['jql'])) {
            [$extra, $args] = (new self())->parse((string) $input['jql']);
            $sql[] = $extra;
            array_push($params, ...$args);
        }
        return [implode(' AND ', $sql), $params];
    }
    public function parse(string $query): array
    {
        if (strlen($query) > 3000) {
            throw new \InvalidArgumentException('La requête est trop longue.');
        }
        $this->tokens = [];
        $this->pos = 0;
        $this->depth = 0;
        $offset = 0;
        while ($offset < strlen($query)) {
            if (
                !preg_match(
                    '/\G\s*(>=|<=|!=|=|~|>|<|\(|\)|,|"(?:[^"\\\\]|\\\\.)*"|\x27(?:[^\x27\\\\]|\\\\.)*\x27|[\pL\pN_@.\-]+)/u',
                    $query,
                    $m,
                    0,
                    $offset,
                )
            ) {
                if (trim(substr($query, $offset)) === '') {
                    break;
                }
                throw new \InvalidArgumentException(
                    'Syntaxe de requête non prise en charge près de : ' .
                        mb_substr(substr($query, $offset), 0, 30),
                );
            }
            $this->tokens[] = $m[1];
            $offset += strlen($m[0]);
        }
        if (!$this->tokens) {
            throw new \InvalidArgumentException('La requête est vide.');
        }
        $result = $this->expression();
        if ($this->pos !== count($this->tokens)) {
            throw new \InvalidArgumentException(
                'La requête contient une expression non prise en charge. Le tri se règle avec les en-têtes.',
            );
        }
        return $result;
    }
    private function take(): string
    {
        return $this->tokens[$this->pos++] ??
            throw new \InvalidArgumentException('Requête incomplète.');
    }
    private function peek(): string
    {
        return strtoupper($this->tokens[$this->pos] ?? '');
    }
    private function expression(): array
    {
        [$sql, $args] = $this->conjunction();
        while ($this->peek() === 'OR') {
            $this->take();
            [$s, $p] = $this->conjunction();
            $sql = "($sql OR $s)";
            array_push($args, ...$p);
        }
        return [$sql, $args];
    }
    private function conjunction(): array
    {
        [$sql, $args] = $this->term();
        while ($this->peek() === 'AND') {
            $this->take();
            [$s, $p] = $this->term();
            $sql = "($sql AND $s)";
            array_push($args, ...$p);
        }
        return [$sql, $args];
    }
    private function term(): array
    {
        if ($this->peek() === '(') {
            if (++$this->depth > 20) {
                throw new \InvalidArgumentException(
                    'La requête contient trop de niveaux de parenthèses.',
                );
            }
            $this->take();
            $r = $this->expression();
            if ($this->take() !== ')') {
                throw new \InvalidArgumentException('Parenthèse manquante.');
            }
            $this->depth--;
            return $r;
        }
        $field = strtolower($this->take());
        if (!isset(self::FIELDS[$field])) {
            throw new \InvalidArgumentException('Champ de requête inconnu : ' . $field);
        }
        $column = self::FIELDS[$field];
        $op = strtoupper($this->take());
        if ($op === 'IS') {
            $not = $this->peek() === 'NOT';
            if ($not) {
                $this->take();
            }
            if (strtoupper($this->take()) !== 'EMPTY') {
                throw new \InvalidArgumentException('Utilisez IS EMPTY ou IS NOT EMPTY.');
            }
            return ["$column IS " . ($not ? 'NOT ' : '') . 'NULL', []];
        }
        if ($op === 'IN') {
            if ($this->take() !== '(') {
                throw new \InvalidArgumentException('IN attend une liste entre parenthèses.');
            }
            $values = [];
            do {
                if ($values) {
                    $this->take();
                }
                $values[] = $this->value($field, $this->take());
            } while ($this->peek() === ',');
            if ($this->take() !== ')') {
                throw new \InvalidArgumentException('Liste IN invalide.');
            }
            return [
                "$column IN (" . implode(',', array_fill(0, count($values), '?')) . ')',
                $values,
            ];
        }
        if (!in_array($op, ['=', '!=', '>', '<', '>=', '<=', '~'], true)) {
            throw new \InvalidArgumentException('Opérateur non pris en charge.');
        }
        $value = $this->value($field, $this->take());
        if ($field === 'labels') {
            if (!in_array($op, ['=', '!='], true)) {
                throw new \InvalidArgumentException('Les étiquettes acceptent = ou !=.');
            }
            return [
                ($op === '!=' ? 'NOT ' : '') . 'JSON_CONTAINS(t.labels, ?)',
                [json_encode($value, JSON_THROW_ON_ERROR)],
            ];
        }
        return [
            "$column " . ($op === '~' ? 'LIKE' : $op) . ' ?',
            [$op === '~' ? '%' . $value . '%' : $value],
        ];
    }
    private function value(string $field, string $raw): string
    {
        if (in_array($raw[0] ?? '', ['"', "'"], true)) {
            $raw = stripcslashes(substr($raw, 1, -1));
        }
        $options = match ($field) {
            'status' => Ticket::STATUSES,
            'type' => Ticket::TYPES,
            'priority' => Ticket::PRIORITIES,
            default => [],
        };
        foreach ($options as $key => $label) {
            if (mb_strtolower($raw) === mb_strtolower($label) || $raw === $key) {
                return $key;
            }
        }
        return $raw;
    }
}
