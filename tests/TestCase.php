<?php

declare(strict_types=1);

namespace Meil\Tests;

use Meil\Meil;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected const KEY = 'meil_000000000000000000000000000000000000000000000000';

    /** @var list<float> Seconds the client waited between attempts. */
    protected array $sleeps = [];

    /** @param array<string, mixed> $options */
    protected function client(FakeTransport $transport, array $options = []): Meil
    {
        $this->sleeps = [];
        return new Meil(self::KEY, $options + [
            'transport' => $transport,
            'sleep'     => function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        ]);
    }
}
