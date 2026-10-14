<?php

declare(strict_types=1);

namespace Meil\Exception;

/** 409: idempotency conflicts, or a broadcast blocked by one under review. */
class ConflictException extends MeilException
{
}
