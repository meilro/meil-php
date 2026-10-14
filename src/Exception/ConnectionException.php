<?php

declare(strict_types=1);

namespace Meil\Exception;

/** No answer: network failure or timeout. getErrorName() is "connection_error" or "timeout". */
class ConnectionException extends MeilException
{
}
