<?php

declare(strict_types=1);

namespace Local\AcgnDatabase;

use Flarum\Foundation\ValidationException;

final class Input
{
    public const TYPES = ['anime', 'manga', 'game', 'novel'];
    public const KINDS = ['person', 'character'];
    public const STATUSES = ['planned', 'current', 'completed', 'on_hold', 'dropped'];

    public static function fail(string $field, string $message): never
    {
        throw new ValidationException([$field => $message]);
    }

    public static function text(array $data, string $key, int $max, string $default = ''): string
    {
        $value = $data[$key] ?? $default;
        if (!is_string($value) || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            self::fail($key, "请输入不超过 {$max} 字的文本。");
        }
        return trim($value);
    }

    public static function integer(mixed $value, string $field, int $min = 0, int $max = 2147483647): int
    {
        if (!(is_int($value) || (is_string($value) && preg_match('/^\d{1,10}$/D', $value)))) {
            self::fail($field, '请输入有效整数。');
        }
        $number = (int) $value;
        if ($number < $min || $number > $max) self::fail($field, "数值必须在 {$min}–{$max} 之间。");
        return $number;
    }

    public static function boolean(mixed $value, string $field): bool
    {
        if (!is_bool($value)) self::fail($field, '请输入布尔值。');
        return $value;
    }

    public static function choice(mixed $value, string $field, array $choices): string
    {
        if (!is_string($value) || !in_array($value, $choices, true)) self::fail($field, '请选择有效选项。');
        return $value;
    }

    public static function strings(mixed $value, string $field, int $limit): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $limit) self::fail($field, "最多允许 {$limit} 项。");
        $result = [];
        foreach ($value as $item) {
            $item = self::text([$field => $item], $field, 120);
            if ($item !== '') $result[] = $item;
        }
        return array_values(array_unique($result));
    }

    public static function image(string $value, string $field): string
    {
        if ($value === '') return '';
        $url = parse_url($value);
        if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['port'])) {
            self::fail($field, '图片地址必须是无认证信息和自定义端口的 HTTPS 地址。');
        }
        return $value;
    }

    public static function entity(array $data, bool $person = false): array
    {
        $title = $person ? 'name' : 'title';
        $original = $person ? 'original_name' : 'original_title';
        $image = $person ? 'image_url' : 'cover_url';
        $fields = [
            $title => self::text($data, $title, 255),
            $original => self::text($data, $original, 255),
            'aliases' => self::strings($data['aliases'] ?? [], 'aliases', 30),
            'summary' => self::text($data, 'summary', 20000),
            $image => self::image(self::text($data, $image, 2000), $image),
        ];
        if ($fields[$title] === '') self::fail($title, '名称不能为空。');
        if ($person) {
            $fields['kind'] = self::choice($data['kind'] ?? null, 'kind', self::KINDS);
        } else {
            $fields['type'] = self::choice($data['type'] ?? null, 'type', self::TYPES);
            $fields['tags'] = self::strings($data['tags'] ?? [], 'tags', 20);
            $date = self::text($data, 'release_date', 10);
            if ($date !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $matches) || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]))) {
                self::fail('release_date', '日期格式为 YYYY-MM-DD，且必须是有效日期。');
            }
            $fields['release_date'] = $date;
            $fields['total_units'] = ($data['total_units'] ?? null) === null ? null : self::integer($data['total_units'], 'total_units', 1, 1000000);
        }
        return $fields;
    }

    public static function record(array $data): array
    {
        $fields = [
            'status' => self::choice($data['status'] ?? null, 'status', self::STATUSES),
            'score' => ($data['score'] ?? null) === null ? null : self::integer($data['score'], 'score', 1, 10),
            'favorite' => self::boolean($data['favorite'] ?? false, 'favorite'),
            'progress' => self::integer($data['progress'] ?? 0, 'progress', 0, 1000000),
        ];
        // Older clients omit this field: preserve an existing comment on ordinary saves.
        if (array_key_exists('comments', $data)) $fields['comments'] = self::text($data, 'comments', 2000);
        return $fields;
    }
}
