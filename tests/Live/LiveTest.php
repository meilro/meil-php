<?php

declare(strict_types=1);

namespace Meil\Tests\Live;

use Meil\Exception\AuthenticationException;
use Meil\Exception\ConflictException;
use Meil\Exception\MeilException;
use Meil\Exception\NotFoundException;
use Meil\Exception\PermissionException;
use Meil\Meil;
use Meil\Transport\CurlTransport;
use Meil\Transport\Response;
use Meil\Transport\TransportInterface;
use PHPUnit\Framework\TestCase;

/**
 * Against the real API: MEIL_API_KEY=... MEIL_TEST_DOMAIN=yourdomain.ro composer test:live
 *
 * Mails only Amazon SES's mailbox simulator, keeps 0.3 s between calls, never
 * sends a broadcast, and deletes everything it creates.
 */
final class LiveTest extends TestCase
{
    private static Meil $meil;
    private static string $domain;
    private static string $run;
    /** @var array{audiences: list<string>, templates: list<string>, broadcasts: list<string>, contacts: list<string>} */
    private static array $created = ['audiences' => [], 'templates' => [], 'broadcasts' => [], 'contacts' => []];

    public static function setUpBeforeClass(): void
    {
        $key    = getenv('MEIL_API_KEY');
        $domain = getenv('MEIL_TEST_DOMAIN');
        if (!is_string($key) || $key === '' || !is_string($domain) || $domain === '') {
            self::markTestSkipped('Set MEIL_API_KEY and MEIL_TEST_DOMAIN to run the live tests.');
        }
        self::$domain = $domain;
        self::$run    = (string) time();
        self::$meil   = new Meil($key, ['transport' => self::paced()]);
    }

    public static function tearDownAfterClass(): void
    {
        if (!isset(self::$meil)) {
            return;
        }
        $quietly = static function (callable $fn): void {
            try {
                $fn();
            } catch (NotFoundException) {
            } catch (MeilException $e) {
                fwrite(STDERR, 'Cleanup failed: ' . $e->getMessage() . "\n");
            }
        };
        foreach (self::$created['broadcasts'] as $id) {
            $quietly(fn () => self::$meil->broadcasts->delete($id));
        }
        foreach (self::$created['templates'] as $id) {
            $quietly(fn () => self::$meil->templates->delete($id));
        }
        foreach (self::$created['contacts'] as $email) {
            $quietly(fn () => self::$meil->contacts->delete($email));
        }
        foreach (self::$created['audiences'] as $id) {
            $quietly(fn () => self::$meil->audiences->delete($id));
        }
    }

    /** cURL, with at least 0.3 s between calls. */
    private static function paced(): TransportInterface
    {
        return new class () implements TransportInterface {
            private float $last = 0.0;
            private CurlTransport $curl;

            public function __construct()
            {
                $this->curl = new CurlTransport();
            }

            public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
            {
                $wait = $this->last + 0.3 - microtime(true);
                if ($wait > 0) {
                    usleep((int) ($wait * 1_000_000));
                }
                $this->last = microtime(true);
                return $this->curl->send($method, $url, $headers, $body, $timeout);
            }
        };
    }

    private static function from(): string
    {
        return 'meil-php-test <sdk-test@' . self::$domain . '>';
    }

    private static function sim(string $tag): string
    {
        return 'success+php-' . $tag . '-' . self::$run . '@simulator.amazonses.com';
    }

    /** Runs $fn, or marks the test skipped when this key's scope does not cover it. */
    private function scoped(callable $fn): void
    {
        try {
            $fn();
        } catch (PermissionException $e) {
            if ($e->getErrorName() !== 'insufficient_permission') {
                throw $e;
            }
            self::markTestSkipped('The API key does not have permission for this part.');
        }
    }

    public function testChecksContent(): void
    {
        $check = self::$meil->content->check(['from' => self::from(), 'subject' => 'BIG SALE!!!', 'html' => '<p><a href="https://bit.ly/x">x</a></p>']);
        self::assertSame('content_check', $check['object']);
        self::assertContains('link_shortener', array_column($check['issues'], 'code'));
    }

    public function testSendsAndReplaysAnIdempotencyKey(): void
    {
        $key    = 'meil-php-live-' . self::$run;
        $params = ['from' => self::from(), 'to' => self::sim('send'), 'subject' => 'meil-php live test', 'html' => '<p>Hello from the SDK test.</p>'];
        $first  = self::$meil->emails->send($params, ['idempotency_key' => $key]);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $first['id']);
        self::assertSame($first['id'], self::$meil->emails->send($params, ['idempotency_key' => $key])['id']);
        try {
            self::$meil->emails->send(['subject' => 'other'] + $params, ['idempotency_key' => $key]);
            self::fail('expected a conflict');
        } catch (ConflictException $e) {
            self::assertSame('invalid_idempotent_request', $e->getErrorName());
        }

        $email = self::$meil->emails->get($first['id']);
        self::assertSame([self::sim('send')], $email['to']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $email['created_at']);

        self::assertCount(1, self::$meil->emails->list(['limit' => 1])['data']);
        $seen = 0;
        foreach (self::$meil->emails->iterate(['limit' => 1]) as $_) {
            if (++$seen === 2) {
                break;
            }
        }
        self::assertSame(2, $seen);
    }

    public function testSendsABrandedEmailWithAnAttachment(): void
    {
        $sent = self::$meil->emails->send([
            'from'        => self::from(),
            'to'          => self::sim('layout'),
            'subject'     => 'meil-php layout test',
            'heading'     => 'Test',
            'body'        => 'An attachment is included.',
            'button'      => ['label' => 'Open', 'url' => 'https://' . self::$domain . '/'],
            'attachments' => [['filename' => 'note.txt', 'content' => 'hello']],
        ]);
        $email = self::$meil->emails->get($sent['id']);
        self::assertSame('note.txt', $email['attachments'][0]['filename']);
        self::assertSame(5, $email['attachments'][0]['bytes']);
    }

    public function testManagesTemplatesAndSendsOne(): void
    {
        $this->scoped(function (): void {
            $tpl = self::$meil->templates->create(['name' => 'meil-php ' . self::$run, 'subject' => 'Order {{order_id}}', 'html' => '<p>Hi {{name|there}}, order {{order_id}}.</p>']);
            self::$created['templates'][] = $tpl['id'];
            self::assertSame(['order_id', 'name'], $tpl['variables']);
            self::assertIsInt($tpl['content_check']['score']);
            self::assertSame('Your order {{order_id}}', self::$meil->templates->update($tpl['id'], ['subject' => 'Your order {{order_id}}'])['subject']);
            self::assertStringContainsString('{{order_id}}', self::$meil->templates->get($tpl['id'])['html']);
            self::assertContains($tpl['id'], array_column(self::$meil->templates->list()['data'], 'id'));
            $sent = self::$meil->emails->send(['from' => self::from(), 'to' => self::sim('template'), 'template_id' => $tpl['id'], 'variables' => ['order_id' => 2451]]);
            self::assertSame('Your order 2451', self::$meil->emails->get($sent['id'])['subject']);
            self::assertTrue(self::$meil->templates->delete($tpl['id'])['deleted']);
            array_pop(self::$created['templates']);
        });
    }

    public function testManagesAudiencesContactsAndADraftBroadcast(): void
    {
        $this->scoped(function (): void {
            $meil     = self::$meil;
            $audience = $meil->audiences->create(['name' => 'meil-php ' . self::$run, 'domain' => self::$domain, 'visibility' => 'private']);
            self::$created['audiences'][] = $audience['id'];
            self::assertContains($audience['id'], array_column($meil->audiences->list()['data'], 'id'));

            $a = self::sim('contact-a');
            $b = self::sim('contact-b');
            array_push(self::$created['contacts'], $a, $b);
            self::assertTrue($meil->contacts->add(['audience_ids' => [$audience['id']], 'email' => $a, 'first_name' => 'Ana', 'properties' => ['city' => 'Cluj', 'plan' => 'pro']])['audiences'][0]['subscribed']);
            $meil->contacts->add(['audience_ids' => [$audience['id']], 'email' => $b, 'properties' => []]);
            self::assertSame(['city'], $meil->contacts->add(['audience_ids' => [$audience['id']], 'email' => $a, 'properties' => ['city' => 'Iasi']])['kept_properties'] ?? null);

            $contact = $meil->contacts->get($a);
            self::assertSame($a, $contact['email']);
            self::assertSame(['city' => 'Cluj', 'plan' => 'pro'], $contact['properties']);
            self::assertSame(['city' => 'Iasi'], $meil->contacts->update($a, ['last_name' => 'Pop', 'properties' => ['plan' => null, 'city' => 'Iasi']])['properties']);
            self::assertFalse($meil->contacts->update($a, ['audiences' => [$audience['id'] => false]])['audiences'][0]['subscribed']);
            self::assertTrue($meil->contacts->update($a, ['audiences' => [$audience['id'] => true]])['audiences'][0]['subscribed']);

            self::assertSame(2, $meil->audiences->get($audience['id'])['contacts_count']);
            self::assertTrue($meil->audiences->listContacts($audience['id'], ['limit' => 1])['has_more']);
            $emails = array_column(iterator_to_array($meil->audiences->iterateContacts($audience['id'], ['limit' => 1])), 'email');
            sort($emails);
            $expected = [$a, $b];
            sort($expected);
            self::assertSame($expected, $emails);

            // A draft only: it is never sent.
            $draft = $meil->broadcasts->create(['audience_id' => $audience['id'], 'from' => self::from(), 'subject' => 'meil-php draft', 'html' => '<p>Draft</p>']);
            self::assertSame('draft', $draft['status']);
            self::$created['broadcasts'][] = $draft['id'];
            self::assertSame('meil-php draft 2', $meil->broadcasts->update($draft['id'], ['subject' => 'meil-php draft 2', 'reply_to' => null])['subject']);
            self::assertSame('draft', $meil->broadcasts->get($draft['id'])['status']);
            self::assertContains($draft['id'], array_column($meil->broadcasts->list()['data'], 'id'));
            try {
                $scheduled = $meil->broadcasts->schedule($draft['id'], new \DateTimeImmutable('+7 days'));
                self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $scheduled['scheduled_at']);
            } catch (PermissionException $e) {
                self::assertSame('plan_required', $e->getErrorName());   // scheduling is for paid plans
            }
            self::assertNull($meil->broadcasts->unschedule($draft['id'])['scheduled_at']);
            self::assertTrue($meil->broadcasts->delete($draft['id'])['deleted']);
            array_pop(self::$created['broadcasts']);

            $removed = $meil->contacts->remove($audience['id'], $b);
            self::assertSame($audience['id'], $removed['audience_id'] ?? null);
            self::assertTrue($meil->contacts->delete($a)['deleted']);
            self::assertTrue($meil->audiences->delete($audience['id'])['deleted']);
            array_pop(self::$created['audiences']);
        });
    }

    public function testMapsErrors(): void
    {
        try {
            self::$meil->emails->get('00000000-0000-4000-8000-000000000000');
            self::fail('expected 404');
        } catch (NotFoundException $e) {
            self::assertSame('not_found', $e->getErrorName());
        }
        $this->expectException(AuthenticationException::class);
        (new Meil('meil_' . str_repeat('0', 48), ['transport' => self::paced()]))->templates->list();
    }
}
