<?php
declare(strict_types=1);
namespace App\Services;
use App\Models\Ticket;
/**
 * Turns ticket list filters (form/query-string values and the JQL-like "jql" text) into a SQL WHERE fragment.
 * where() returns [sql, params]: user values are only ever bound as "?" parameters; column names come from
 * the FIELDS whitelist or hard-coded strings, never from input. The fragment uses the table aliases of
 * Query::JOIN (t, p, a, u, tm), so it must be combined with that join.
 * Used by Query (lists, counts, aggregates), ListLayout (saved-report validation) and the workspace views.
 * Invalid input raises InvalidArgumentException with a French message meant for the user.
 */
final class Filter
{
    private array $tokens = [];
    private int $pos = 0;
    private int $depth = 0;
    private bool $mentionsPaused = false;
    private int $project = 0;
    /** Query field name => SQL expression. The only column names the JQL parser can emit. */
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
    /** True when the input narrows the ticket set compared with an empty filter. */
    public static function active(array $input): bool
    {
        // Project selection and display options do not restrict the result set.
        return self::where(0, $input)[0] !== self::where(0, [])[0];
    }
    /**
     * Builds [where SQL joined with AND, bound parameters] for non-archived tickets of $project (0 = all active projects).
     * Status/type/priority values are checked against the project workflow before being bound.
     */
    public static function where(int $project, array $input): array
    {
        $sql = ['t.archived=0'];
        $params = [];
        if ($project) {
            $sql[] = 't.project_id=?';
            $params[] = $project;
        } else {
            // "All projects" covers active projects only; an archived project stays reachable on its own.
            $sql[] = 'p.archived_at IS NULL';
        }
        $q = trim((string) ($input['q'] ?? ''));
        if ($q !== '') {
            $sql[] =
                "(t.title LIKE ? OR t.description LIKE ? OR CONCAT(p.project_key,'-',t.number) LIKE ?)";
            array_push($params, ...array_fill(0, 3, '%' . $q . '%'));
        }
        $statuses = self::selectedStatuses($input);
        // Every status unchecked means "match nothing", not "no status filter".
        if ($statuses === []) $sql[] = '1=0';
        elseif ($statuses !== null) $input['status'] = $statuses;
        foreach (['status', 'type', 'priority'] as $field) {
            if ($field === 'status' && !$statuses) continue;
            if (!empty($input[$field])) {
                $values = is_array($input[$field])
                    ? $input[$field]
                    : explode(',', (string) $input[$field]);
                if(!array_is_list($values) || array_filter($values,static fn($value)=>!is_string($value))) throw new \InvalidArgumentException('Liste de filtre invalide : '.$field);
                $allowed = match ($field) {
                    'status' => \App\Models\Workflow::labels('status',$project),
                    'type' => \App\Models\Workflow::labels('type',$project),
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
        // Assignee and team accept several values (ids and/or "none"). Each list is an OR inside itself;
        // assignment_match=any joins the two lists with OR instead of AND ("my tickets or those without team").
        $assignment=[];
        foreach (['assignee'=>'t.assignee_id','team'=>'t.team_id'] as $field=>$column) {
            $values=self::selectedIds($input,$field);
            if ($values===[]) continue;
            $ids=array_values(array_filter($values,static fn($value)=>$value!=='none'));
            $parts=[];
            if ($ids) {$parts[]=$column.' IN ('.implode(',',array_fill(0,count($ids),'?')).')';array_push($params,...array_map('intval',$ids));}
            if (in_array('none',$values,true)) $parts[]=$column.' IS NULL';
            $assignment[]=count($parts)>1?'('.implode(' OR ',$parts).')':$parts[0];
        }
        $match=$input['assignment_match']??'';
        if (!is_string($match) || !in_array($match,['','all','any'],true)) throw new \InvalidArgumentException('Combinaison personne / équipe invalide.');
        if ($match==='any' && count($assignment)>1) $sql[]='('.implode(' OR ',$assignment).')';
        else array_push($sql,...$assignment);
        if (!empty($input['hide_done'])) {
            $sql[] = \App\Models\Workflow::categorySql()."<>'done'";
        }
        if (($input['release']??'')!=='') {
            $release=$input['release'];
            if ($release==='none') $sql[]='t.release_id IS NULL';
            else {
                if ((!is_int($release) && !is_string($release)) || !ctype_digit((string)$release) || (int)$release<1) throw new \InvalidArgumentException('Filtre version invalide.');
                $sql[]='t.release_id=?';$params[]=(int)$release;
            }
        }
        foreach(['resolution'=>\App\Models\TicketClosure::RESOLUTIONS,'validation'=>\App\Models\TicketClosure::VALIDATIONS] as $filter=>$options) if (($input[$filter]??'')!=='') {
            $value=$input[$filter];
            if (!is_string($value) || !isset($options[$value])) throw new \InvalidArgumentException('Filtre de clôture invalide.');
            $sql[]=$filter==='resolution'?'t.resolution=?':'t.validation_state=?';$params[]=$value;
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
        $jqlPaused=false;
        // The JQL text is parsed into its own parenthesised fragment and ANDed with the other filters.
        if (!empty($input['jql'])) {
            $parser=new self();$parser->project=$project;
            [$extra, $args] = $parser->parse((string) $input['jql']);
            $jqlPaused=$parser->mentionsPaused;
            $sql[] = $extra;
            array_push($params, ...$args);
        }
        $pause=self::resolvePauseMode($input,$jqlPaused);
        if ($pause==='only') $sql[]="t.status='paused'";
        elseif ($pause==='active') $sql[]="t.status<>'paused'";
        return [implode(' AND ', $sql), $params];
    }
    /**
     * Paused tickets are hidden by default ('active'). An explicit "pause" value wins; otherwise selecting
     * the paused status, or naming it in JQL, switches to 'all' so the user's own criterion is not overridden.
     */
    private static function resolvePauseMode(array $input,bool $jqlPaused): string
    {
        $mode=$input['pause']??'';
        if (!is_string($mode) || !in_array($mode,['','active','only','all'],true)) throw new \InvalidArgumentException('Filtre de pause invalide.');
        if ($mode!=='') return $mode;
        return in_array('paused',self::selectedStatuses($input)??[],true)||$jqlPaused?'all':'active';
    }
    public static function pauseMode(array $input,int $project=0): string
    {
        $parser=new self();$parser->project=$project;
        if (!empty($input['jql'])) $parser->parse((string)$input['jql']);
        return self::resolvePauseMode($input,$parser->mentionsPaused);
    }
    /**
     * Selected assignee or team values as a list of "none" and/or positive id strings, without duplicates.
     * Accepts a scalar (single id, "none" or a comma-separated list) or a list; empty entries are ignored.
     */
    public static function selectedIds(array $input,string $field): array
    {
        $value=$input[$field]??'';
        if (is_int($value)) $value=(string)$value;
        $values=is_string($value)?explode(',',$value):$value;
        $label=$field==='team'?'équipe':'personne assignée';
        if (!is_array($values) || !array_is_list($values) || count($values)>500) throw new \InvalidArgumentException('Filtre '.$label.' invalide.');
        $result=[];
        foreach ($values as $item) {
            if (is_int($item)) $item=(string)$item;
            if (!is_string($item)) throw new \InvalidArgumentException('Filtre '.$label.' invalide.');
            $item=trim($item);
            if ($item==='') continue;
            if ($item!=='none' && (!ctype_digit($item) || (int)$item<1)) throw new \InvalidArgumentException('Filtre '.$label.' invalide.');
            $result[$item==='none'?'none':(string)(int)$item]=true;
        }
        return array_map('strval',array_keys($result));
    }
    /** Null means no explicit selection. An empty list means every state was unchecked. */
    public static function selectedStatuses(array $input): ?array
    {
        $value = $input['status'] ?? '';
        if ($value === '' || $value === []) return null;
        $values = is_string($value) ? explode(',', $value) : $value;
        if (!is_array($values) || !array_is_list($values) || array_filter($values, static fn($item) => !is_string($item))) {
            throw new \InvalidArgumentException('Liste de filtre invalide : status');
        }
        // The empty form value survives a GET with no checked boxes.
        return array_values(array_unique(array_filter($values, static fn($item) => $item !== '')));
    }
    /** Status columns/labels that can appear under the current filters (pause mode, hide_done, selection). */
    public static function visibleStatuses(array $input,int $project=0): array
    {
        $statuses=\App\Models\Workflow::labels('status',$project,true);$mode=self::pauseMode($input,$project);
        if ($mode==='active') unset($statuses['paused']);
        elseif ($mode==='only') $statuses=['paused'=>$statuses['paused']];
        if (!empty($input['hide_done'])) $statuses=array_diff_key($statuses,\App\Models\Workflow::categoryLabels('status',$project,'done'));
        $selected=self::selectedStatuses($input);
        return $selected===null?$statuses:array_intersect_key($statuses,array_flip($selected));
    }
    /**
     * Parses a JQL-like query into [sql, params].
     * Grammar (recursive descent, AND binds tighter than OR):
     *   expression  := conjunction ("OR" conjunction)*
     *   conjunction := term ("AND" term)*
     *   term        := "(" expression ")" | field op value | field "IN" "(" value ("," value)* ")"
     *                | field "IS" ["NOT"] "EMPTY"
     * Sorting clauses (ORDER BY) are not supported and are rejected as trailing tokens.
     */
    public function parse(string $query): array
    {
        if (strlen($query) > 3000) {
            throw new \InvalidArgumentException('La requête est trop longue.');
        }
        $this->tokens = [];
        $this->pos = 0;
        $this->depth = 0;
        $this->mentionsPaused = false;
        $offset = 0;
        // Tokenizer: anchored (\G) at the current offset, it reads one operator, parenthesis, comma,
        // double- or single-quoted string (backslash escapes allowed) or bare word per iteration.
        // Any other character stops parsing with an error instead of being skipped.
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
        // Only whitelisted SQL expressions are interpolated; every value below is a bound parameter.
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
        // Labels are stored as a JSON array, so equality is a JSON_CONTAINS test on the encoded value.
        if ($field === 'labels') {
            if (!in_array($op, ['=', '!='], true)) {
                throw new \InvalidArgumentException('Les étiquettes acceptent = ou !=.');
            }
            return [
                ($op === '!=' ? 'NOT ' : '') . 'JSON_CONTAINS(t.labels, ?)',
                [json_encode($value, JSON_THROW_ON_ERROR)],
            ];
        }
        // "~" is a substring match; % and _ typed by the user are not escaped and act as LIKE wildcards.
        return [
            "$column " . ($op === '~' ? 'LIKE' : $op) . ' ?',
            [$op === '~' ? '%' . $value . '%' : $value],
        ];
    }
    /**
     * Unquotes a value token and, for status/type/priority, maps a key or (case-insensitive) display label
     * to the stored key. Any other value is returned as typed. Also records whether the paused status was named.
     */
    private function value(string $field, string $raw): string
    {
        if (in_array($raw[0] ?? '', ['"', "'"], true)) {
            $raw = stripcslashes(substr($raw, 1, -1));
        }
        $options = match ($field) {
            'status' => \App\Models\Workflow::labels('status',$this->project),
            'type' => \App\Models\Workflow::labels('type',$this->project),
            'priority' => Ticket::PRIORITIES,
            default => [],
        };
        foreach ($options as $key => $label) {
            if (mb_strtolower($raw) === mb_strtolower($label) || mb_strtolower($raw) === $key) {
                if ($field==='status' && $key==='paused') $this->mentionsPaused=true;
                return $key;
            }
        }
        return $raw;
    }
}
