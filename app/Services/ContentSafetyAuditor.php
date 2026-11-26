<?php

namespace App\Services;

use App\Models\Page;
use App\Models\Post;
use App\Support\HtmlSanitizer;

/**
 * Scans stored post/page content for markup that the sanitiser would remove.
 *
 * Used by both the `content:sanitize` command and the admin Security page so
 * non-technical users can review and clean legacy content without SSH.
 */
class ContentSafetyAuditor
{
    public const DEFAULT_LIMIT = 2000;

    public function __construct(
        protected HtmlSanitizer $sanitizer,
    ) {}

    /**
     * @return array{scanned: int, affected: int, samples: array<int, array{type: string, label: string, id: int, title: string|null}>, truncated: bool}
     */
    /**
     * @param  array<int, string>  $types  post, page
     */
    public function scan(int $limit = self::DEFAULT_LIMIT, array $types = ['post', 'page']): array
    {
        return $this->run($limit, false, $types);
    }

    /**
     * @return array{scanned: int, affected: int, samples: array<int, array{type: string, label: string, id: int, title: string|null}>, truncated: bool}
     */
    /**
     * @param  array<int, string>  $types  post, page
     */
    public function apply(int $limit = self::DEFAULT_LIMIT, array $types = ['post', 'page']): array
    {
        return $this->run($limit, true, $types);
    }

    /**
     * @return array{scanned: int, affected: int, samples: array<int, array{type: string, label: string, id: int, title: string|null}>, truncated: bool}
     */
    /**
     * @param  array<int, string>  $types
     */
    protected function run(int $limit, bool $apply, array $types): array
    {
        $limit = max(1, $limit);

        $scanned = 0;
        $affected = 0;
        $samples = [];
        $truncated = false;

        $models = array_values(array_filter([
            ['type' => 'post', 'label' => 'Post', 'model' => Post::class],
            ['type' => 'page', 'label' => 'Page', 'model' => Page::class],
        ], fn (array $definition) => in_array($definition['type'], $types, true)));

        foreach ($models as $definition) {
            if ($truncated) {
                break;
            }

            /** @var class-string<Post|Page> $model */
            $model = $definition['model'];

            $model::query()
                ->select('id', 'title', 'content')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use (&$scanned, &$affected, &$samples, &$truncated, $limit, $apply, $definition): bool {
                    foreach ($rows as $row) {
                        if ($scanned >= $limit) {
                            $truncated = true;

                            return false;
                        }

                        $scanned++;

                        $clean = $this->sanitizer->sanitize($row->content);

                        if ($clean === $row->content) {
                            continue;
                        }

                        $affected++;

                        if (count($samples) < 10) {
                            $samples[] = [
                                'type' => $definition['type'],
                                'label' => $definition['label'],
                                'id' => $row->id,
                                'title' => $row->title,
                            ];
                        }

                        if ($apply) {
                            $row->forceFill(['content' => $clean])->saveQuietly();
                        }
                    }

                    return true;
                });
        }

        return [
            'scanned' => $scanned,
            'affected' => $affected,
            'samples' => $samples,
            'truncated' => $truncated,
        ];
    }
}
