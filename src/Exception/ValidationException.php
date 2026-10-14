<?php

declare(strict_types=1);

namespace Meil\Exception;

/** 422: the request was refused as invalid; also send_rejected (with getId()). */
class ValidationException extends MeilException
{
}
