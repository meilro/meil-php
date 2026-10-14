<?php

declare(strict_types=1);

namespace Meil\Exception;

/** 5xx: the service had a problem; also mail_disabled (503). */
class ServerException extends MeilException
{
}
