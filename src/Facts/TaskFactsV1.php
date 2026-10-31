<?php
declare(strict_types=1);

namespace Local\AcgnDatabase\Facts;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Local\AcgnDatabase\Input;

/** Internal PHP boundary: the caller supplies the trusted task owner's User. */
final class TaskFactsV1
{
    private const FIELDS = [
        'subject.id' => ['s.id', 'integer'], 'subject.type' => ['s.type', 'string'],
        'subject.release_date' => ['s.release_date', 'date'], 'record.status' => ['r.status', 'string'],
        'record.score' => ['r.score', 'integer'], 'record.progress' => ['r.progress', 'integer'],
        'record.favorite' => ['r.favorite', 'boolean'], 'completion.observed_at' => ['c.observed_at', 'datetime'],
        'completion.origin' => ['c.origin', 'string'],
    ];

    public function __construct(private ConnectionInterface $db) {}

    public function capabilities(): array
    {
        return ['version' => 1, 'available' => $this->db->getSchemaBuilder()->hasTable('acgn_first_completions'),
            'max_batch' => 200, 'fields' => array_map(fn ($field) => $field[1], self::FIELDS),
            'operators' => ['types' => ['in'], 'ids' => ['in'], 'statuses' => ['in'], 'from' => ['>='], 'until' => ['<']],
            'comparison_owner' => 'consumer',
            'filters' => ['mode', 'types', 'ids', 'statuses', 'from', 'until'],
            'ordering' => ['records' => ['subject_id'], 'links' => ['discussion_id', 'subject_id']],
            'sources' => ['catalog' => ['key' => 'acgn-catalog', 'user_id' => 0], 'records' => ['key' => 'acgn-records', 'user_id' => 'owner']]];
    }

    public function recordsPage(User $owner, array $filter = [], array $fields = [], ?int $after = null, int $limit = 200, ?\PDO $transaction = null): array
    {
        $this->authorize($owner, $transaction); $this->fields($fields); $this->limit($limit);
        if ($after !== null && $after < 0) throw new \InvalidArgumentException('Invalid subject cursor.');
        $query = $this->base((int) $owner->id)->whereNotNull('r.subject_id');
        $this->filter($query, $filter);
        if ($after !== null) $query->where('s.id', '>', $after);
        $rows = $this->read($query->select($this->columns($fields))->orderBy('s.id')->limit($limit + 1), $transaction)->get()->all();
        $more = count($rows) > $limit; $rows = array_slice($rows, 0, $limit);
        $items = array_map(fn ($row) => $this->item($row, $fields), $rows);
        return ['version' => 1, 'items' => $items, 'next_after' => $more ? end($items)['subject_id'] : null, 'complete' => !$more];
    }

    /** Trusted background calibration only; no private values or HTTP endpoint. */
    public function affectedUsersPage(array $subjectIds = [], array $discussionIds = [], ?int $after = null, int $limit = 200): array
    {
        if (!$this->capabilities()['available']) throw new SourceUnavailableV1('ACGN task facts are unavailable.');
        $subjects = $this->ids($subjectIds); $discussions = $this->ids($discussionIds); $this->limit($limit);
        if ($after !== null && $after < 0) throw new \InvalidArgumentException('Invalid user cursor.');
        $scoped = $subjects || $discussions;
        if (!$subjects && $discussions) {
            $linked = $this->db->table('acgn_discussions')->whereIn('discussion_id', $discussions)->distinct()->useWritePdo()->count('discussion_id');
            // A deleted discussion/edge no longer reveals its former works. Do not omit their record owners.
            if ($linked < count($discussions)) $scoped = false;
        }
        $parts = [];
        foreach (['acgn_records', 'acgn_first_completions'] as $index => $table) {
            $query = $this->db->table($table)->select('user_id')->distinct()->where('user_id', '>', $after ?? 0);
            if ($scoped) $query->where(fn ($q) => $q->whereIn('subject_id', $subjects)->orWhereIn('subject_id',
                $this->db->table('acgn_discussions')->select('subject_id')->whereIn('discussion_id', $discussions)));
            $parts[] = $this->db->query()->fromSub($query->orderBy('user_id')->limit($limit + 1), 'source_users_'.$index)->select('user_id');
        }
        $posts = $this->db->table('posts')->select('user_id')->distinct()->where('user_id', '>', $after ?? 0);
        if ($scoped) $posts->where(fn ($q) => $q->whereIn('discussion_id', $discussions)->orWhereIn('discussion_id',
            $this->db->table('acgn_discussions')->select('discussion_id')->whereIn('subject_id', $subjects)));
        else $posts->where(fn ($q) => $q->whereIn('discussion_id', $discussions)
            ->orWhereIn('discussion_id', $this->db->table('acgn_discussions')->select('discussion_id')));
        $parts[] = $this->db->query()->fromSub($posts->orderBy('user_id')->limit($limit + 1), 'source_users_posts')->select('user_id');
        $union = array_shift($parts);
        foreach ($parts as $part) $union->unionAll($part);
        $ids = $this->db->query()->fromSub($union, 'affected_users')->select('user_id')->distinct()->orderBy('user_id')->limit($limit + 1)->useWritePdo()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $more = count($ids) > $limit; $ids = array_slice($ids, 0, $limit);
        return ['version' => 1, 'user_ids' => $ids, 'next_after' => $more ? end($ids) : null, 'complete' => !$more];
    }

    public function filterSubjects(User $owner, array $subjectIds, array $filter = [], array $fields = [], ?\PDO $transaction = null): array
    {
        $this->authorize($owner, $transaction); $this->fields($fields);
        $ids = $this->ids($subjectIds);
        $query = $this->base((int) $owner->id)->whereIn('s.id', $ids);
        $this->filter($query, $filter);
        $rows = $this->read($query->select($this->columns($fields))->orderBy('s.id'), $transaction)->get();
        return ['version' => 1, 'items' => $rows->map(fn ($row) => $this->item($row, $fields))->all(), 'complete' => true];
    }

    public function linksForDiscussions(User $owner, array $discussionIds, array $fields = [], ?array $after = null, int $limit = 200, ?\PDO $transaction = null): array
    {
        $this->authorize($owner, $transaction); $this->fields($fields); $this->limit($limit);
        $ids = $this->ids($discussionIds);
        $edges = $this->db->table('acgn_discussions as d')->whereIn('d.discussion_id', $ids);
        $counts = $this->read((clone $edges)->selectRaw('d.discussion_id, COUNT(*) as total')->groupBy('d.discussion_id'), $transaction)->get();
        $totals = array_fill_keys($ids, 0);
        foreach ($counts as $row) $totals[(int) $row->discussion_id] = (int) $row->total;
        if ($after !== null) {
            if (array_diff(array_keys($after), ['discussion_id', 'subject_id']) || count($after) !== 2
                || !is_int($after['discussion_id'] ?? null) || !in_array($after['discussion_id'], $ids, true)
                || !is_int($after['subject_id'] ?? null) || $after['subject_id'] < 1) throw new \InvalidArgumentException('Invalid association cursor.');
            $edges->where(fn ($q) => $q->where('d.discussion_id', '>', $after['discussion_id'])
                ->orWhere(fn ($q) => $q->where('d.discussion_id', $after['discussion_id'])->where('d.subject_id', '>', $after['subject_id'])));
        }
        $query = $edges->join('acgn_subjects as s', 's.id', '=', 'd.subject_id');
        $this->joinRecords($query, (int) $owner->id);
        $rows = $this->read($query->select(array_merge($this->columns($fields), ['d.discussion_id', 's.archived']))
            ->orderBy('d.discussion_id')->orderBy('d.subject_id')->limit($limit + 1), $transaction)->get()->all();
        $more = count($rows) > $limit; $items = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $items[] = ['discussion_id' => (int) $row->discussion_id, 'subject_id' => (int) $row->subject_id,
                'eligible' => !(bool) $row->archived, 'values' => $row->archived ? [] : $this->item($row, $fields)['values']];
        }
        $last = $items ? end($items) : null;
        return ['version' => 1, 'items' => $items, 'totals' => $totals,
            'next_after' => $more ? ['discussion_id' => $last['discussion_id'], 'subject_id' => $last['subject_id']] : null, 'complete' => !$more];
    }

    private function authorize(User $owner, ?\PDO $transaction): void
    {
        if ($owner->isGuest() || !$owner->can('viewForum') || !$owner->can('acgn.view') || !$this->capabilities()['available']) {
            throw new SourceUnavailableV1('ACGN task facts are unavailable.');
        }
        if ($transaction !== null && ($transaction !== $this->db->getPdo() || !$transaction->inTransaction())) {
            throw new \InvalidArgumentException('Current reads require the active source PDO transaction.');
        }
        if (!$this->read($this->db->table('users')->where('id', $owner->id), $transaction)->first(['id'])) throw new SourceUnavailableV1('ACGN task owner is unavailable.');
    }

    private function base(int $user): Builder
    {
        $query = $this->db->table('acgn_subjects as s')->where('s.archived', false);
        $this->joinRecords($query, $user);
        return $query;
    }

    private function joinRecords(Builder $query, int $user): void
    {
        $query->leftJoin('acgn_records as r', fn ($join) => $join->on('r.subject_id', '=', 's.id')->where('r.user_id', $user));
        $query->leftJoin('acgn_first_completions as c', fn ($join) => $join->on('c.subject_id', '=', 's.id')->where('c.user_id', $user));
    }

    private function filter(Builder $query, array $filter): void
    {
        if (array_diff(array_keys($filter), ['mode', 'types', 'ids', 'statuses', 'from', 'until'])) throw new \InvalidArgumentException('Unsupported facts filter.');
        $mode = $filter['mode'] ?? 'records';
        if (!in_array($mode, ['records', 'first_completed'], true)) throw new \InvalidArgumentException('Unsupported facts mode.');
        if (array_key_exists('ids', $filter)) $query->whereIn('s.id', $this->ids($filter['ids']));
        foreach (['types' => ['s.type', Input::TYPES], 'statuses' => ['r.status', Input::STATUSES]] as $key => [$column, $allowed]) {
            if (!array_key_exists($key, $filter)) continue;
            $values = $filter[$key];
            if (!is_array($values) || !array_is_list($values) || count($values) > 200) throw new \InvalidArgumentException('Invalid facts choices.');
            foreach ($values as $value) if (!is_string($value) || !in_array($value, $allowed, true)) throw new \InvalidArgumentException('Invalid facts choices.');
            $query->whereIn($column, array_values(array_unique($values)));
        }
        if ($mode === 'first_completed') $query->where('r.status', 'completed')->where('c.origin', 'self');
        if ($mode !== 'first_completed' && (array_key_exists('from', $filter) || array_key_exists('until', $filter))) throw new \InvalidArgumentException('Current records have no behavior window.');
        $from = isset($filter['from']) ? $this->time($filter['from']) : null;
        $until = isset($filter['until']) ? $this->time($filter['until']) : null;
        if ($from !== null && $until !== null && $from >= $until) throw new \InvalidArgumentException('Invalid facts window.');
        if ($from !== null) $query->where('c.observed_at', '>=', $from);
        if ($until !== null) $query->where('c.observed_at', '<', $until);
    }

    private function read(Builder $query, ?\PDO $transaction): Builder
    {
        $query->useWritePdo();
        if ($transaction !== null) $query->sharedLock();
        return $query;
    }

    private function columns(array $fields): array
    {
        $columns = ['s.id as subject_id'];
        foreach (array_values($fields) as $index => $field) $columns[] = self::FIELDS[$field][0].' as fact_'.$index;
        return $columns;
    }

    private function item(object $row, array $fields): array
    {
        $values = [];
        foreach (array_values($fields) as $index => $field) {
            $value = $row->{'fact_'.$index};
            if ($value === '' && $field === 'subject.release_date') $value = null;
            $values[$field] = $value === null ? null : match (self::FIELDS[$field][1]) {
                'integer' => (int) $value, 'boolean' => (bool) $value, default => (string) $value,
            };
        }
        return ['subject_id' => (int) $row->subject_id, 'values' => $values];
    }

    private function fields(array $fields): void
    {
        if (!array_is_list($fields) || count($fields) > count(self::FIELDS)) throw new \InvalidArgumentException('Unsupported facts projection.');
        foreach ($fields as $field) if (!is_string($field) || !isset(self::FIELDS[$field])) throw new \InvalidArgumentException('Unsupported facts projection.');
    }

    private function ids(mixed $ids): array
    {
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 200) throw new \InvalidArgumentException('Facts IDs require a batch of at most 200.');
        foreach ($ids as $id) if (!is_int($id) || $id < 1) throw new \InvalidArgumentException('Facts IDs must be positive integers.');
        $ids = array_values(array_unique($ids)); sort($ids, SORT_NUMERIC); return $ids;
    }

    private function limit(int $limit): void
    {
        if ($limit < 1 || $limit > 200) throw new \InvalidArgumentException('Facts page limit is 1 through 200.');
    }

    private function time(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D', $value)) throw new \InvalidArgumentException('Facts times must be UTC datetimes.');
        $normalized = str_contains($value, '.') ? $value.str_repeat('0', 26 - strlen($value)) : $value.'.000000';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $normalized, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d H:i:s.u') !== $normalized) throw new \InvalidArgumentException('Invalid facts datetime.');
        return $normalized;
    }
}
