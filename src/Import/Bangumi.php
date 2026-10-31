<?php

declare(strict_types=1);

namespace Local\AcgnDatabase\Import;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Local\AcgnDatabase\Input;

class Bangumi
{
    private ClientInterface $client;

    public function __construct(private SettingsRepositoryInterface $settings, ?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client();
    }

    public static function subjectId(string $value): int
    {
        $value = trim($value);
        if (preg_match('/^\d+$/D', $value)) return Input::integer($value, 'subject', 1);
        $url = parse_url($value);
        if (!$url || ($url['scheme'] ?? '') !== 'https' || !in_array(strtolower($url['host'] ?? ''), ['bgm.tv', 'bangumi.tv', 'chii.in'], true)
            || isset($url['user']) || isset($url['pass']) || isset($url['port']) || !preg_match('~^/subject/(\d+)/?$~D', $url['path'] ?? '', $matches)) {
            Input::fail('subject', '请输入 Bangumi 作品 ID 或 https://bgm.tv/subject/123 形式的链接。');
        }
        return Input::integer($matches[1], 'subject', 1);
    }

    public function preview(int $id, string $type): array
    {
        $subject = $this->get("subjects/{$id}");
        if (($subject['id'] ?? null) !== $id) throw new ImportException('Bangumi 返回了不匹配的条目。');
        $sourceType = $subject['type'] ?? null;
        $allowed = match ($sourceType) { 1 => ['manga', 'novel'], 2 => ['anime'], 4 => ['game'], default => [] };
        if (!in_array($type, $allowed, true)) Input::fail('type', '本地类型与 Bangumi 条目不匹配；书籍请选择漫画或小说，音乐及三次元条目暂不支持。');
        $date = is_string($subject['date'] ?? null) ? $subject['date'] : '';
        if ($date !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) $date = '';
        // eps is the declared episode/chapter total; total_episodes counts API episode rows.
        $units = $subject['eps'] ?? 0;
        if (!is_int($units) || $units < 1 || $units > 1000000 || $sourceType === 4) $units = null;
        $tags = [];
        foreach (array_slice($subject['tags'] ?? [], 0, 20) as $tag) if (is_string($tag['name'] ?? null)) $tags[] = mb_substr($tag['name'], 0, 120);
        $fields = Input::entity([
            'type' => $type,
            'title' => $this->short($subject['name_cn'] ?? '') ?: $this->short($subject['name'] ?? ''),
            'original_title' => $this->short($subject['name'] ?? ''),
            'aliases' => $this->aliases($subject['infobox'] ?? []),
            'summary' => $this->short($subject['summary'] ?? '', 20000),
            'cover_url' => $this->image($subject['images'] ?? null),
            'release_date' => $date, 'total_units' => $units, 'tags' => $tags,
        ]);
        $people = [];
        $credits = [];
        $truncated = false;
        foreach (['persons' => 'person', 'characters' => 'character'] as $endpoint => $kind) {
            $relations = $this->get("subjects/{$id}/{$endpoint}");
            if (!array_is_list($relations)) throw new ImportException('Bangumi 关联资料格式无效。');
            $truncated = $truncated || count($relations) > 80;
            foreach (array_slice($relations, 0, 80) as $relation) {
                $relationKey = $kind.':'.($relation['id'] ?? '');
                if (count($people) >= 240 && !isset($people[$relationKey])) { $truncated = true; continue; }
                $this->addPerson($people, $credits, $relation, $kind, $this->short($relation['relation'] ?? '关联', 100));
                // RelatedCharacter embeds its actors. This needs no recursive API calls.
                if ($kind === 'character') {
                    foreach (array_slice($relation['actors'] ?? [], 0, 10) as $voice) {
                        if (count($people) >= 240) { $truncated = true; break; }
                        $this->addPerson($people, $credits, $voice, 'person', '配音：'.$this->short($relation['name'] ?? '', 90));
                    }
                }
            }
        }
        return ['source' => 'bangumi', 'source_id' => $id, 'subject' => $fields, 'people' => array_values($people), 'credits' => array_values($credits), 'truncated' => $truncated];
    }

    private function addPerson(array &$people, array &$credits, array $data, string $kind, string $role): void
    {
        $id = Input::integer($data['id'] ?? null, 'source_id', 1);
        $key = $kind.':'.$id;
        $person = Input::entity([
            'kind' => $kind, 'name' => $this->short($data['name'] ?? ''), 'original_name' => $this->short($data['name'] ?? ''),
            'summary' => $this->short($data['summary'] ?? '', 20000), 'image_url' => $this->image($data['images'] ?? null), 'aliases' => [],
        ], true);
        $people[$key] ??= $person + ['source_id' => $id];
        $role = $role === '' ? '关联' : $role;
        $credits[$key.':'.$role] = ['key' => $key, 'role' => $role];
    }

    private function aliases(array $infobox): array
    {
        $aliases = [];
        foreach ($infobox as $field) {
            if (($field['key'] ?? '') !== '别名') continue;
            $value = $field['value'] ?? [];
            if (is_string($value)) $aliases[] = mb_substr($value, 0, 120);
            elseif (is_array($value)) foreach ($value as $alias) {
                $text = is_string($alias) ? $alias : (is_array($alias) ? ($alias['v'] ?? '') : '');
                if (is_string($text) && $text !== '') $aliases[] = mb_substr($text, 0, 120);
            }
        }
        return array_slice(array_values(array_unique($aliases)), 0, 30);
    }

    private function image(mixed $images): string
    {
        if (!is_array($images)) return '';
        $image = $images['large'] ?? $images['medium'] ?? $images['common'] ?? '';
        if (!is_string($image)) return '';
        $url = parse_url($image);
        $host = strtolower($url['host'] ?? '');
        if (($url['scheme'] ?? '') !== 'https' || ($host !== 'bgm.tv' && !str_ends_with($host, '.bgm.tv'))) return '';
        return Input::image($image, 'image');
    }

    private function short(mixed $value, int $length = 255): string
    {
        return is_string($value) ? trim(mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value), 0, $length)) : '';
    }

    public function collectionPage(string $accessToken, int $offset): array
    {
        if (!preg_match('#^[A-Za-z0-9._~+/=-]{8,512}$#D', $accessToken)) Input::fail('access_token', '请输入有效的 Bangumi Access Token。');
        $identity = $this->get('me', $accessToken);
        $id = Input::integer($identity['id'] ?? null, 'account', 1);
        $username = Input::text($identity, 'username', 100);
        if ($username === '') $username = (string) $id;
        $page = $this->get('users/'.rawurlencode($username).'/collections?subject_type=2&limit=20&offset='.$offset, $accessToken);
        if (!isset($page['data']) || !is_array($page['data']) || !array_is_list($page['data']) || count($page['data']) > 20) {
            throw new ImportException('Bangumi 收藏分页格式无效。');
        }
        return ['account' => ['id' => $id, 'username' => $username], 'offset' => $offset,
            'total' => Input::integer($page['total'] ?? 0, 'total', 0, 1000000), 'data' => $page['data']];
    }

    private function get(string $path, ?string $accessToken = null): array
    {
        $userAgent = Input::text(['ua' => $this->settings->get('acgn.bangumi_user_agent', '')], 'ua', 200);
        if (!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+~', $userAgent) || preg_match('/[\r\n]/', $userAgent)) Input::fail('user_agent', '请管理员先设置包含开发者 ID／应用名称及版本的 Bangumi User-Agent。');
        try {
            $headers = ['User-Agent' => $userAgent, 'Accept' => 'application/json'];
            if ($accessToken !== null) $headers['Authorization'] = 'Bearer '.$accessToken;
            $response = $this->client->request('GET', 'https://api.bgm.tv/v0/'.$path, [
                'headers' => $headers,
                'connect_timeout' => 3, 'timeout' => 8, 'allow_redirects' => false, 'http_errors' => false, 'stream' => true,
            ]);
            $body = $response->getBody();
            $deadline = microtime(true) + 8;
            try {
                $status = $response->getStatusCode();
                if ($status !== 200) throw new ImportException(match ($status) { 401, 403 => 'Bangumi 授权无效或权限不足，请检查 Access Token。', 404 => 'Bangumi 找不到此资料。', 429 => 'Bangumi 请求过于频繁，请稍后重试。', default => 'Bangumi 暂时不可用，请稍后重试。' });
                $raw = '';
                while (!$body->eof() && strlen($raw) <= 2097152) {
                    if (microtime(true) > $deadline) throw new ImportException('Bangumi 响应超时，请重试。');
                    $chunk = $body->read(min(65536, 2097153 - strlen($raw)));
                    if ($chunk === '' && !$body->eof()) throw new ImportException('Bangumi 响应未完成，请重试。');
                    $raw .= $chunk;
                }
                if (strlen($raw) > 2097152) throw new ImportException('Bangumi 响应过大，已停止导入。');
                $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($data)) throw new ImportException('Bangumi 响应格式无效。');
                return $data;
            } finally { $body->close(); }
        } catch (ImportException $error) { throw $error; }
        catch (\Throwable) { throw new ImportException('无法读取 Bangumi 数据，请检查网络后重试。'); }
    }
}
