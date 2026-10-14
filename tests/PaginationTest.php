<?php

declare(strict_types=1);

namespace Meil\Tests;

use Meil\Transport\Response;

final class PaginationTest extends TestCase
{
    /** @param list<string> $ids */
    private static function page(array $ids, bool $hasMore): Response
    {
        return FakeTransport::json(['object' => 'list', 'has_more' => $hasMore, 'data' => array_map(static fn (string $id) => ['id' => $id], $ids)]);
    }

    public function testListReturnsOnePage(): void
    {
        $t = new FakeTransport(self::page(['e3', 'e2'], true));
        $page = $this->client($t)->emails->list(['limit' => 2]);
        self::assertTrue($page['has_more']);
        self::assertSame(['e3', 'e2'], array_column($page['data'], 'id'));
        self::assertCount(1, $t->requests);
    }

    public function testIterateFollowsAfterUntilTheEnd(): void
    {
        $t = new FakeTransport(self::page(['e5', 'e4'], true), self::page(['e3', 'e2'], true), self::page(['e1'], false));
        $ids = [];
        foreach ($this->client($t)->emails->iterate(['limit' => 2]) as $email) {
            $ids[] = $email['id'];
        }
        self::assertSame(['e5', 'e4', 'e3', 'e2', 'e1'], $ids);
        self::assertSame(['/emails?limit=2', '/emails?limit=2&after=e4', '/emails?limit=2&after=e2'], array_column($t->requests, 'path'));
    }

    public function testIteratorKeysDoNotRepeat(): void
    {
        $t = new FakeTransport(self::page(['e2'], true), self::page(['e1'], false));
        self::assertSame([['id' => 'e2'], ['id' => 'e1']], iterator_to_array($this->client($t)->emails->iterate()));
    }

    public function testIterateContacts(): void
    {
        $t = new FakeTransport(self::page(['m3', 'm2'], true), self::page(['m1'], false));
        $ids = array_column(iterator_to_array($this->client($t)->audiences->iterateContacts('aud 1', ['limit' => 2])), 'id');
        self::assertSame(['m3', 'm2', 'm1'], $ids);
        self::assertSame('/audiences/aud%201/contacts?limit=2&after=m2', $t->requests[1]['path']);
    }

    public function testStartedWithBeforeWalksTowardNewerItems(): void
    {
        $t = new FakeTransport(self::page(['e7', 'e6'], true), self::page(['e8'], false));
        $ids = array_column(iterator_to_array($this->client($t)->emails->iterate(['before' => 'e5', 'limit' => 2])), 'id');
        self::assertSame(['e7', 'e6', 'e8'], $ids);
        self::assertSame('/emails?before=e7&limit=2', $t->requests[1]['path']);
    }

    public function testStopsOnAnEmptyPage(): void
    {
        $t = new FakeTransport(self::page([], true));
        self::assertSame([], iterator_to_array($this->client($t)->emails->iterate()));
        self::assertCount(1, $t->requests);
    }

    public function testFetchesLazily(): void
    {
        $t = new FakeTransport(self::page(['e3', 'e2'], true), self::page(['e1'], false));
        foreach ($this->client($t)->emails->iterate() as $email) {
            break;
        }
        self::assertCount(1, $t->requests);
    }
}
