<?php

declare(strict_types=1);

namespace Meil\Tests;

final class RequestTest extends TestCase
{
    private const SEND = ['from' => 'a@example.org', 'to' => ['b@example.net', 'c@example.net'], 'subject' => 'S', 'html' => 'H'];
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function testSendMakesARandomUuidPerCall(): void
    {
        $t = new FakeTransport(FakeTransport::json(['id' => 'e1']), FakeTransport::json(['id' => 'e2']));
        $meil = $this->client($t);
        $meil->emails->send(self::SEND);
        $meil->emails->send(self::SEND);
        self::assertMatchesRegularExpression(self::UUID, $t->requests[0]['headers']['idempotency-key']);
        self::assertMatchesRegularExpression(self::UUID, $t->requests[1]['headers']['idempotency-key']);
        self::assertNotSame($t->requests[0]['headers']['idempotency-key'], $t->requests[1]['headers']['idempotency-key']);
    }

    public function testSendsTheCallersOwnKey(): void
    {
        $t = new FakeTransport(FakeTransport::json(['id' => 'e1']), FakeTransport::json(['object' => 'broadcast', 'id' => 'b1', 'status' => 'draft', 'scheduled_at' => null]));
        $meil = $this->client($t);
        $meil->emails->send(self::SEND, ['idempotency_key' => 'order-2451-confirmation']);
        $meil->broadcasts->create(['audience_id' => 'a1', 'from' => 'n@example.org', 'subject' => 'S', 'html' => 'H'], ['idempotency_key' => 'march-newsletter']);
        self::assertSame('order-2451-confirmation', $t->requests[0]['headers']['idempotency-key']);
        self::assertSame('march-newsletter', $t->requests[1]['headers']['idempotency-key']);
    }

    public function testOnlySendAndCreateBroadcastCarryAKey(): void
    {
        $t = new FakeTransport(FakeTransport::json([]), FakeTransport::json([]), FakeTransport::json([]), FakeTransport::json([]));
        $meil = $this->client($t);
        $meil->broadcasts->create(['audience_id' => 'a1', 'from' => 'n@example.org', 'subject' => 'S', 'html' => 'H']);
        $meil->broadcasts->send('b1');
        $meil->templates->create(['name' => 'T', 'html' => 'H']);
        $meil->content->check(['html' => 'H']);
        self::assertMatchesRegularExpression(self::UUID, $t->requests[0]['headers']['idempotency-key']);
        foreach (array_slice($t->requests, 1) as $request) {
            self::assertArrayNotHasKey('idempotency-key', $request['headers']);
        }
    }

    public function testTestSendsGoToTheTestAudience(): void
    {
        $answer = ['object' => 'broadcast_test', 'to' => ['ana@example.com']];
        $t = new FakeTransport(FakeTransport::json($answer), FakeTransport::json($answer));
        $meil = $this->client($t);
        self::assertSame($answer, $meil->broadcasts->test('b/1'));
        $meil->broadcasts->test(['from' => 'n@example.org', 'subject' => 'S', 'html' => 'H']);
        self::assertSame('POST', $t->requests[0]['method']);
        self::assertStringEndsWith('/broadcasts/b%2F1/send', $t->requests[0]['url']);
        self::assertSame(['audience_id' => 'test'], $t->requests[0]['body']);
        self::assertArrayNotHasKey('idempotency-key', $t->requests[0]['headers']);
        self::assertStringEndsWith('/broadcasts', $t->requests[1]['url']);
        self::assertSame(['audience_id' => 'test', 'from' => 'n@example.org', 'subject' => 'S', 'html' => 'H'], $t->requests[1]['body']);
        self::assertMatchesRegularExpression(self::UUID, $t->requests[1]['headers']['idempotency-key']);
    }

    public function testEncodesEmailsInPaths(): void
    {
        $t = new FakeTransport(FakeTransport::json([]), FakeTransport::json([]), FakeTransport::json([]), FakeTransport::json([]));
        $meil = $this->client($t);
        $meil->contacts->get('ana+news@example.com');
        $meil->contacts->update('ana+news@example.com', ['first_name' => 'Ana']);
        $meil->contacts->delete('a b/c?d#e@example.com');
        $meil->contacts->remove('aud/1', 'ana+news@example.com');
        self::assertSame([
            '/contacts/ana%2Bnews%40example.com',
            '/contacts/ana%2Bnews%40example.com',
            '/contacts/a%20b%2Fc%3Fd%23e%40example.com',
            '/audiences/aud%2F1/contacts/ana%2Bnews%40example.com',
        ], array_column($t->requests, 'path'));
    }

    public function testEncodesIdsAndQueryCursors(): void
    {
        $t = new FakeTransport(FakeTransport::json([]), FakeTransport::json([]));
        $meil = $this->client($t);
        $meil->emails->get('../x');
        $meil->emails->list(['before' => 'a&b=c', 'after' => null]);
        self::assertSame('/emails/..%2Fx', $t->requests[0]['path']);
        self::assertSame('/emails?before=a%26b%3Dc', $t->requests[1]['path']);
    }

    public function testBase64EncodesAttachments(): void
    {
        $t = new FakeTransport(FakeTransport::json(['id' => 'e1']));
        $this->client($t)->emails->send(self::SEND + ['attachments' => [
            ['filename' => 'a.pdf', 'content' => "%PDF-1.4\n"],
            ['filename' => 'c.bin', 'content' => "\x00\xff\x80"],
        ]]);
        self::assertSame([
            ['filename' => 'a.pdf', 'content' => 'JVBERi0xLjQK'],
            ['filename' => 'c.bin', 'content' => 'AP+A'],
        ], $t->requests[0]['body']['attachments']);
    }

    public function testRefusesAnAttachmentWithoutContent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client(new FakeTransport())->emails->send(self::SEND + ['attachments' => [['filename' => 'a.pdf']]]);
    }

    public function testSendsScheduledAtStringsAsGiven(): void
    {
        $t = new FakeTransport(FakeTransport::json([]), FakeTransport::json([]));
        $meil = $this->client($t);
        $meil->broadcasts->schedule('b1', '2030-03-01T09:00:00Z');
        $meil->broadcasts->schedule('b1', new \DateTime('2030-03-01 09:00:00', new \DateTimeZone('Europe/Bucharest')));
        self::assertSame(['scheduled_at' => '2030-03-01T09:00:00Z'], $t->requests[0]['body']);
        self::assertSame(['scheduled_at' => '2030-03-01T09:00:00+02:00'], $t->requests[1]['body']);
    }

    public function testKeepsJsonObjectsAsObjects(): void
    {
        $t = new FakeTransport(FakeTransport::json([]), FakeTransport::json([]), FakeTransport::json([]));
        $meil = $this->client($t);
        $meil->contacts->add(['audience_ids' => ['a1'], 'email' => 'c@example.net', 'properties' => []]);
        $meil->emails->send(self::SEND + ['variables' => []]);
        $meil->broadcasts->unschedule('b1');
        self::assertStringContainsString('"properties":{}', (string) $t->requests[0]['raw']);
        self::assertStringContainsString('"variables":{}', (string) $t->requests[1]['raw']);
        self::assertNull($t->requests[2]['raw']);
    }

    public function testKeepsUnicodeAndSlashesReadable(): void
    {
        $t = new FakeTransport(FakeTransport::json(['id' => 'e1']));
        $this->client($t)->emails->send(['from' => 'a@example.org', 'to' => 'b@example.net', 'subject' => 'Comandă', 'html' => '<a href="https://example.org/a">ș</a>']);
        self::assertStringContainsString('"subject":"Comandă"', (string) $t->requests[0]['raw']);
        self::assertStringContainsString('https://example.org/a', (string) $t->requests[0]['raw']);
    }

    public function testRefusesInvalidUtf8(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client(new FakeTransport())->emails->send(['from' => 'a@example.org', 'to' => 'b@example.net', 'subject' => "\xB1\x31", 'html' => 'H']);
    }
    public function testAddPassesConfirmPageThrough(): void
    {
        $t = new FakeTransport(FakeTransport::json([]));
        $this->client($t)->contacts->add([
            'audience_ids' => ['aud_1'], 'email' => 'a@b.com', 'confirm' => true,
            'confirm_page' => 'https://example.com/confirm',
        ]);
        self::assertSame('https://example.com/confirm', $t->requests[0]['body']['confirm_page'] ?? null);
    }

    public function testConfirmSendsTheTokenAndLeavesOutAnEmptyIp(): void
    {
        $done = ['object' => 'confirmation', 'status' => 'confirmed', 'email' => 'a@b.com', 'audiences' => [],
            'confirmed_at' => '2026-03-01 09:14:02', 'expires_at' => '2026-03-08 09:00:00', 'already_confirmed' => false];
        $t = new FakeTransport(FakeTransport::json($done), FakeTransport::json($done), FakeTransport::json($done));
        $meil = $this->client($t);
        self::assertSame('confirmed', $meil->contacts->confirm('tok.1', '2001:db8::7')['status']);
        $meil->contacts->confirm('tok.1');
        $meil->contacts->confirm('tok.1', '');
        self::assertSame([
            ['token' => 'tok.1', 'ip' => '2001:db8::7'],
            ['token' => 'tok.1'],
            ['token' => 'tok.1'],
        ], array_column($t->requests, 'body'));
    }

    public function testAnExpiredLinkIsAStatusNotAnError(): void
    {
        $t = new FakeTransport(FakeTransport::json(['object' => 'confirmation', 'status' => 'expired', 'email' => 'a@b.com', 'audiences' => [],
            'confirmed_at' => null, 'expires_at' => '2026-03-08 09:00:00', 'already_confirmed' => false]));
        self::assertSame('expired', $this->client($t)->contacts->confirm('tok.1')['status']);
    }

    public function testAnInvalidTokenThrowsNotFound(): void
    {
        $t = new FakeTransport(FakeTransport::error(404, 'not_found'));
        $this->expectException(\Meil\Exception\NotFoundException::class);
        $this->client($t)->contacts->getConfirmation('nope');
    }
}
