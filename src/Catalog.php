<?php

declare(strict_types=1);

namespace Local\AcgnDatabase;

use Flarum\Discussion\DiscussionRepository;
use Flarum\Discussion\Discussion;
use Flarum\Http\SlugManager;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Local\AcgnDatabase\Facts\{FactsMutation, FirstCompletion};

final class Catalog
{
    public function __construct(private ConnectionInterface $db, private DiscussionRepository $discussions, private SlugManager $slugs) {}

    public function listing(array $params, User $actor, bool $people = false): array
    {
        $actor->assertCan('viewForum'); $actor->assertCan('acgn.view');
        $table = $people ? 'acgn_people' : 'acgn_subjects';
        $query = $this->db->table($table);
        $archived = ($params['archived'] ?? '') === '1';
        if ($archived) $actor->assertCan('acgn.delete');
        $query->where('archived', $archived);
        $q = Input::text($params, 'q', 100);
        if ($q !== '') {
            $title = $people ? 'name' : 'title';
            $original = $people ? 'original_name' : 'original_title';
            // Bound parameters prevent SQL injection. LIKE supports user-entered wildcards.
            $query->where(fn ($where) => $where->where($title, 'like', "%{$q}%")->orWhere($original, 'like', "%{$q}%")->orWhere('aliases', 'like', "%{$q}%"));
        }
        $filter = $people ? 'kind' : 'type';
        if (!empty($params[$filter])) $query->where($filter, Input::choice($params[$filter], $filter, $people ? Input::KINDS : Input::TYPES));
        if (!$people && !empty($params['mine'])) {
            $actor->assertRegistered();
            $query->whereIn('id', $this->db->table('acgn_records')->select('subject_id')->where('user_id', $actor->id));
        }
        $page = Input::integer($params['page'] ?? 1, 'page', 1, 500);
        $items = $query->orderByDesc('id')->offset(($page - 1) * 24)->limit(25)->get()->all();
        $more = count($items) > 24;
        $items = array_slice($items, 0, 24);
        return ['items' => $this->decorate($items, $actor, $people), 'page' => $page, 'more' => $more];
    }

    public function find(int $id, User $actor, bool $people = false, bool $lock = false): object
    {
        $actor->assertCan('viewForum'); $actor->assertCan('acgn.view');
        $query = $this->db->table($people ? 'acgn_people' : 'acgn_subjects')->where('id', $id);
        if (!$actor->can('acgn.delete')) $query->where('archived', false);
        if ($lock) $query->lockForUpdate();
        $row = $query->first();
        if (!$row) throw new ModelNotFoundException();
        return $row;
    }

    public function detail(int $id, User $actor, bool $people = false): array
    {
        $row = $this->find($id, $actor, $people);
        $result = $this->decorate([$row], $actor, $people)[0];
        $credits = $this->db->table('acgn_credits as c');
        if ($people) {
            $rows = $credits->join('acgn_subjects as s', 's.id', '=', 'c.subject_id')->where('c.person_id', $id)->where('s.archived', false)->select('s.*', 'c.role', 'c.id as credit_id')->orderBy('c.id')->limit(201)->get()->all();
            $result['credits_more'] = count($rows) > 200;
            $result['works'] = $this->decorate(array_slice($rows, 0, 200), $actor);
        } else {
            $rows = $credits->join('acgn_people as p', 'p.id', '=', 'c.person_id')->where('c.subject_id', $id)->where('p.archived', false)->select('p.*', 'c.role', 'c.id as credit_id')->orderBy('c.id')->limit(201)->get()->all();
            $result['credits_more'] = count($rows) > 200;
            $result['credits'] = $this->decorate(array_slice($rows, 0, 200), $actor, true);
            $result['discussions'] = $this->linkedDiscussions($id, $actor);
        }
        return $result;
    }

    public function save(array $data, User $actor, ?int $id = null, bool $people = false): array
    {
        $actor->assertRegistered();
        $actor->assertCan($id === null ? 'acgn.create' : 'acgn.update');
        $fields = Input::entity($data, $people);
        $table = $people ? 'acgn_people' : 'acgn_subjects';
        foreach (['aliases', 'tags'] as $key) if (isset($fields[$key])) $fields[$key] = json_encode($fields[$key], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $write = function () use ($fields, $table, $id, $data, $actor, $people): int {
            $now = gmdate('Y-m-d H:i:s');
            if ($id === null) return $this->db->table($table)->insertGetId($fields + ['created_at' => $now, 'updated_at' => $now]);
            $row = $this->find($id, $actor, $people, true);
            $this->assertRevision($data, $row);
            if ($people && $row->source !== null && $fields['kind'] !== $row->kind) Input::fail('kind', '已导入人物的类型不能更改。');
            if (!$people && $row->total_units !== $fields['total_units'] && $fields['total_units'] !== null) {
                $max = $this->db->table('acgn_records')->where('subject_id', $id)->max('progress');
                if ($max !== null && $max > $fields['total_units']) Input::fail('total_units', '总进度不能小于已有个人进度。');
            }
            $this->db->table($table)->where('id', $id)->update($fields + ['updated_at' => $now, 'revision' => $row->revision + 1]);
            return $id;
        };
        $id = $people ? $this->db->transaction($write, 3)
            : (new FactsMutation($this->db))->run('acgn-catalog', 0, $id === null ? [] : [$id], [], 'subject.save', $write);
        return $this->detail($id, $actor, $people);
    }

    public function archive(int $id, array $data, User $actor, bool $people = false): array
    {
        $actor->assertRegistered();
        $actor->assertCan('acgn.delete');
        $archived = Input::boolean($data['archived'] ?? null, 'archived');
        $write = function () use ($id, $data, $actor, $people, $archived): void {
            $row = $this->find($id, $actor, $people, true);
            $this->assertRevision($data, $row);
            $this->db->table($people ? 'acgn_people' : 'acgn_subjects')->where('id', $id)->update(['archived' => $archived, 'revision' => $row->revision + 1, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        };
        if ($people) $this->db->transaction($write, 3);
        else (new FactsMutation($this->db))->run('acgn-catalog', 0, [$id], [], 'subject.archive', $write);
        return $this->detail($id, $actor, $people);
    }

    public function record(int $subject, array $data, User $actor, bool $delete = false): array
    {
        $actor->assertRegistered();
        $actor->assertCan('acgn.collect');
        $fields = $delete ? [] : Input::record($data);
        (new FactsMutation($this->db))->run('acgn-records', (int) $actor->id, [$subject], [], $delete ? 'record.delete' : 'record.save', function () use ($subject, $fields, $actor, $delete): void {
            $row = $this->find($subject, $actor, false, true);
            if ($row->archived) Input::fail('subject', '已归档作品不能更新个人记录。');
            $key = ['subject_id' => $subject, 'user_id' => $actor->id];
            $existing = $this->db->table('acgn_records')->where($key)->lockForUpdate()->first();
            (new FirstCompletion($this->db))->observe((int) $actor->id, $subject, $existing, $delete ? null : $fields['status'], (int) $actor->id);
            if ($delete) { $this->db->table('acgn_records')->where($key)->delete(); return; }
            if ($row->total_units !== null && $fields['progress'] > $row->total_units) Input::fail('progress', '进度不能超过作品总进度。');
            $now = gmdate('Y-m-d H:i:s');
            $activity = RecordActivity::changes($fields, $existing, false, $row->type);
            if ($existing) {
                $this->db->table('acgn_records')->where($key)->update($fields + $activity + ['revision' => $existing->revision + 1, 'updated_at' => $now]);
            } else {
                $this->db->table('acgn_records')->insert($key + $fields + $activity + ['created_at' => $now, 'updated_at' => $now]);
            }
        });
        return $this->detail($subject, $actor);
    }

    public function credit(int $subject, array $data, User $actor, bool $delete = false): array
    {
        $actor->assertCan('acgn.update');
        $person = Input::integer($data['person_id'] ?? null, 'person_id', 1);
        $role = Input::text($data, 'role', 100);
        if ($role === '') Input::fail('role', '职责／角色类型不能为空。');
        $this->db->transaction(function () use ($subject, $person, $role, $actor, $delete): void {
            $this->find($subject, $actor, false, true);
            $this->find($person, $actor, true);
            $key = ['subject_id' => $subject, 'person_id' => $person, 'role' => $role];
            if ($delete) $this->db->table('acgn_credits')->where($key)->delete();
            else $this->db->table('acgn_credits')->insertOrIgnore($key);
        });
        return $this->detail($subject, $actor);
    }

    public function link(int $subject, int $discussion, User $actor, bool $delete = false): array
    {
        $actor->assertCan('acgn.link');
        (new FactsMutation($this->db))->run('acgn-catalog', 0, [$subject], [$discussion], $delete ? 'link.delete' : 'link.save', function () use ($subject, $discussion, $actor, $delete): void {
            $this->find($subject, $actor, false, true);
            $this->discussions->findOrFail($discussion, $actor);
            $key = ['subject_id' => $subject, 'discussion_id' => $discussion];
            if ($delete) $this->db->table('acgn_discussions')->where($key)->delete();
            else $this->db->table('acgn_discussions')->insertOrIgnore($key);
        });
        return $this->linkedDiscussions($subject, $actor);
    }

    public function linkedDiscussions(int $subject, User $actor): array
    {
        $this->find($subject, $actor);
        return $this->discussions->query()->whereVisibleTo($actor)->whereIn('discussions.id',
            $this->db->table('acgn_discussions')->select('discussion_id')->where('subject_id', $subject)
        )->orderByDesc('discussions.id')->limit(50)->get()->map(fn ($row) => ['id' => (int) $row->id, 'title' => $row->title, 'slug' => $this->slugs->forResource(Discussion::class)->toSlug($row)])->all();
    }

    public function discussionSubjects(int $discussion, User $actor): array
    {
        $this->discussions->findOrFail($discussion, $actor);
        $rows = $this->db->table('acgn_subjects')->where('archived', false)->whereIn('id',
            $this->db->table('acgn_discussions')->select('subject_id')->where('discussion_id', $discussion)
        )->orderBy('id')->limit(50)->get()->all();
        return $this->decorate($rows, $actor);
    }

    private function assertRevision(array $data, object $row): void
    {
        if (Input::integer($data['revision'] ?? null, 'revision') !== (int) $row->revision) Input::fail('revision', '资料已被修改，请重新加载后再保存。');
    }

    public function decorate(array $rows, User $actor, bool $people = false): array
    {
        $actor->assertCan('viewForum'); $actor->assertCan('acgn.view');
        $ids = array_values(array_unique(array_map(fn ($row) => $row->id, $rows)));
        $stats = $records = [];
        if (!$people && $ids) {
            $buckets = $this->db->table('acgn_records')->whereIn('subject_id', $ids)->whereBetween('score', [1, 10])
                ->selectRaw('subject_id, score, COUNT(*) as rating_count')->groupBy('subject_id', 'score')->get();
            foreach ($buckets as $bucket) {
                $stats[$bucket->subject_id] ??= array_fill(0, 10, 0);
                $stats[$bucket->subject_id][(int) $bucket->score - 1] = (int) $bucket->rating_count;
            }
            if (!$actor->isGuest()) $records = $this->db->table('acgn_records')->whereIn('subject_id', $ids)->where('user_id', $actor->id)->get()->keyBy('subject_id')->all();
        }
        return array_map(function ($row) use ($people, $stats, $records): array {
            $value = (array) $row;
            $value['id'] = (int) $row->id;
            $value['revision'] = (int) $row->revision;
            $value['archived'] = (bool) $row->archived;
            $value['aliases'] = json_decode($row->aliases, true) ?? [];
            if (!$people) {
                $value['tags'] = json_decode($row->tags, true) ?? [];
                $value['total_units'] = $row->total_units === null ? null : (int) $row->total_units;
                unset($value['source_score']);
                $distribution = $stats[$row->id] ?? array_fill(0, 10, 0);
                $count = array_sum($distribution);
                $sum = 0;
                foreach ($distribution as $index => $votes) $sum += ($index + 1) * $votes;
                $mean = $count ? $sum / $count : null;
                $variance = null;
                if ($count > 1) {
                    // Sample variance: weighted deviations from the unrounded sample mean.
                    $squaredDeviations = 0.0;
                    foreach ($distribution as $index => $votes) $squaredDeviations += $votes * ($index + 1 - $mean) ** 2;
                    $variance = round($squaredDeviations / ($count - 1), 4);
                }
                $value['rating_count'] = $count;
                $value['rating'] = $mean === null ? null : round($mean, 2);
                $value['rating_variance'] = $variance;
                $value['rating_distribution'] = $distribution;
                $record = isset($records[$row->id]) ? (array) $records[$row->id] : null;
                if ($record) {
                    unset($record['user_id'], $record['subject_id']);
                    $record['score'] = $record['score'] === null ? null : (int) $record['score'];
                    $record['progress'] = (int) $record['progress'];
                    $record['favorite'] = (bool) $record['favorite'];
                    $record['comments'] = $record['comments'] ?? '';
                    $record['revision'] = (int) $record['revision'];
                    $record['share_score'] = (bool) $record['share_score'];
                    $record['share_progress'] = (bool) $record['share_progress'];
                }
                $value['my_record'] = $record;
            }
            return $value;
        }, $rows);
    }
}
