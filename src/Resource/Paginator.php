<?php

declare(strict_types=1);

namespace Meil\Resource;

/** @internal */
final class Paginator
{
    /**
     * Walks a cursor-paged list. Going forward (the default, or with "after")
     * it passes the last id of each page as the next "after"; started with
     * "before", it walks toward newer items, passing the first id as the next
     * "before".
     *
     * @template T of array{id: string}
     *
     * @param callable(array<string, mixed>): array{has_more: bool, data: list<T>} $fetchPage
     * @param array<string, mixed> $params
     *
     * @return \Generator<int, T, mixed, void>
     */
    public static function iterate(callable $fetchPage, array $params): \Generator
    {
        $backward = isset($params['before']);
        while (true) {
            $page = $fetchPage($params);
            foreach ($page['data'] as $item) {
                yield $item;
            }
            $edge = $backward ? ($page['data'][0] ?? null) : ($page['data'][count($page['data']) - 1] ?? null);
            if (!$page['has_more'] || $edge === null) {
                return;
            }
            $params[$backward ? 'before' : 'after'] = $edge['id'];
        }
    }
}
