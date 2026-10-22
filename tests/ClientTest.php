<?php

declare(strict_types=1);

namespace Meil\Tests;

use Meil\Meil;
use PHPUnit\Framework\Attributes\DataProvider;

final class ClientTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('MEIL_API_KEY');
        unset($_ENV['MEIL_API_KEY'], $_SERVER['MEIL_API_KEY']);
    }

    public function testSendsKeyJsonHeadersAndUserAgent(): void
    {
        $t = new FakeTransport(FakeTransport::json(['object' => 'list', 'data' => []]));
        $this->client($t)->audiences->list();

        $request = $t->requests[0];
        self::assertSame('https://api.meil.ro/audiences', $request['url']);
        self::assertSame('Bearer ' . self::KEY, $request['headers']['authorization']);
        self::assertSame('application/json', $request['headers']['accept']);
        self::assertStringStartsWith('meil-php/' . Meil::VERSION . ' PHP/', $request['headers']['user-agent']);
        self::assertArrayNotHasKey('content-type', $request['headers']);
        self::assertSame(30.0, $request['timeout']);
    }

    public function testReadsTheKeyFromTheEnvironment(): void
    {
        putenv('MEIL_API_KEY=meil_fromenv');
        $t = new FakeTransport(FakeTransport::json(['object' => 'list', 'data' => []]));
        (new Meil(null, ['transport' => $t]))->templates->list();
        self::assertSame('Bearer meil_fromenv', $t->requests[0]['headers']['authorization']);
    }

    public function testRefusesToStartWithoutAKey(): void
    {
        putenv('MEIL_API_KEY');
        $this->expectException(\InvalidArgumentException::class);
        new Meil();
    }

    public function testTakesAnotherHttpsBaseUrlAndTimeout(): void
    {
        $t = new FakeTransport(FakeTransport::json(['id' => 'e1']));
        $this->client($t, ['base_url' => 'https://meil.ro/api/', 'timeout' => 5])->emails->get('e1');
        self::assertSame('https://meil.ro/api/emails/e1', $t->requests[0]['url']);
        self::assertSame(5.0, $t->requests[0]['timeout']);
    }

    public function testRefusesPlainHttp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Meil(self::KEY, ['base_url' => 'http://api.meil.ro']);
    }

    public function testVersionMatchesTheChangelog(): void
    {
        $changelog = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
        self::assertMatchesRegularExpression('/^## ' . preg_quote(Meil::VERSION, '/') . '\b/m', $changelog);
    }

    /**
     * @return array<string, array{\Closure(Meil): mixed, string, string, mixed}>
     */
    public static function operations(): array
    {
        $day = new \DateTimeImmutable('2030-01-02T03:04:05+02:00');
        return [
            'sendEmail' => [fn (Meil $m) => $m->emails->send(['from' => 'a@example.org', 'to' => 'b@example.net', 'subject' => 'S', 'html' => 'H']), 'POST', '/emails', ['from' => 'a@example.org', 'to' => 'b@example.net', 'subject' => 'S', 'html' => 'H']],
            'listEmails' => [fn (Meil $m) => $m->emails->list(['limit' => 5, 'after' => 'e9']), 'GET', '/emails?limit=5&after=e9', null],
            'getEmail' => [fn (Meil $m) => $m->emails->get('e1'), 'GET', '/emails/e1', null],
            'createAudience' => [fn (Meil $m) => $m->audiences->create(['name' => 'N', 'domain' => 'example.org']), 'POST', '/audiences', ['name' => 'N', 'domain' => 'example.org']],
            'listAudiences' => [fn (Meil $m) => $m->audiences->list(), 'GET', '/audiences', null],
            'getAudience' => [fn (Meil $m) => $m->audiences->get('a1'), 'GET', '/audiences/a1', null],
            'deleteAudience' => [fn (Meil $m) => $m->audiences->delete('a1'), 'DELETE', '/audiences/a1', null],
            'listContacts' => [fn (Meil $m) => $m->audiences->listContacts('a1', ['limit' => 1000]), 'GET', '/audiences/a1/contacts?limit=1000', null],
            'addContact' => [fn (Meil $m) => $m->contacts->add(['audience_ids' => ['a1'], 'email' => 'c@example.net', 'properties' => ['city' => 'Cluj'], 'confirm' => true]), 'POST', '/contacts', ['audience_ids' => ['a1'], 'email' => 'c@example.net', 'properties' => ['city' => 'Cluj'], 'confirm' => true]],
            'getContact' => [fn (Meil $m) => $m->contacts->get('c@example.net'), 'GET', '/contacts/c%40example.net', null],
            'updateContact' => [fn (Meil $m) => $m->contacts->update('c@example.net', ['properties' => ['old' => null], 'audiences' => ['a1' => true, 'a2' => false]]), 'PATCH', '/contacts/c%40example.net', ['properties' => ['old' => null], 'audiences' => ['a1' => true, 'a2' => false]]],
            'deleteContact' => [fn (Meil $m) => $m->contacts->delete('c@example.net'), 'DELETE', '/contacts/c%40example.net', null],
            'removeContact' => [fn (Meil $m) => $m->contacts->remove('a1', 'c@example.net'), 'DELETE', '/audiences/a1/contacts/c%40example.net', null],
            'createBroadcast' => [fn (Meil $m) => $m->broadcasts->create(['audience_id' => 'a1', 'from' => 'n@example.org', 'subject' => 'S', 'html' => 'H', 'scheduled_at' => $day]), 'POST', '/broadcasts', ['audience_id' => 'a1', 'from' => 'n@example.org', 'subject' => 'S', 'html' => 'H', 'scheduled_at' => '2030-01-02T03:04:05+02:00']],
            'listBroadcasts' => [fn (Meil $m) => $m->broadcasts->list(), 'GET', '/broadcasts', null],
            'getBroadcast' => [fn (Meil $m) => $m->broadcasts->get('b1'), 'GET', '/broadcasts/b1', null],
            'updateBroadcast' => [fn (Meil $m) => $m->broadcasts->update('b1', ['reply_to' => null]), 'PATCH', '/broadcasts/b1', ['reply_to' => null]],
            'deleteBroadcast' => [fn (Meil $m) => $m->broadcasts->delete('b1'), 'DELETE', '/broadcasts/b1', null],
            'sendBroadcast' => [fn (Meil $m) => $m->broadcasts->send('b1'), 'POST', '/broadcasts/b1/send', null],
            'scheduleBroadcast' => [fn (Meil $m) => $m->broadcasts->schedule('b1', $day), 'POST', '/broadcasts/b1/schedule', ['scheduled_at' => '2030-01-02T03:04:05+02:00']],
            'unscheduleBroadcast' => [fn (Meil $m) => $m->broadcasts->unschedule('b1'), 'POST', '/broadcasts/b1/unschedule', null],
            'createTemplate' => [fn (Meil $m) => $m->templates->create(['name' => 'T', 'html' => 'H']), 'POST', '/templates', ['name' => 'T', 'html' => 'H']],
            'listTemplates' => [fn (Meil $m) => $m->templates->list(), 'GET', '/templates', null],
            'getTemplate' => [fn (Meil $m) => $m->templates->get('t1'), 'GET', '/templates/t1', null],
            'updateTemplate' => [fn (Meil $m) => $m->templates->update('t1', ['subject' => 'S2']), 'PATCH', '/templates/t1', ['subject' => 'S2']],
            'deleteTemplate' => [fn (Meil $m) => $m->templates->delete('t1'), 'DELETE', '/templates/t1', null],
            'checkContent' => [fn (Meil $m) => $m->content->check(['html' => '<p>Hi</p>']), 'POST', '/content/check', ['html' => '<p>Hi</p>']],
        ];
    }

    public function testCoversAll27Operations(): void
    {
        self::assertCount(27, self::operations());
    }

    /** @param \Closure(Meil): mixed $call */
    #[DataProvider('operations')]
    public function testOperation(\Closure $call, string $method, string $path, mixed $body): void
    {
        $answer = ['object' => 'x', 'id' => 'id1', 'data' => [], 'has_more' => false];
        $t = new FakeTransport(FakeTransport::json($answer));

        self::assertSame($answer, $call($this->client($t)));
        self::assertSame($method, $t->requests[0]['method']);
        self::assertSame($path, $t->requests[0]['path']);
        self::assertSame($body, $t->requests[0]['body']);
        if ($body !== null) {
            self::assertSame('application/json', $t->requests[0]['headers']['content-type']);
        }
    }
}
