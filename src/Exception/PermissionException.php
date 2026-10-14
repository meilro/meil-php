<?php

declare(strict_types=1);

namespace Meil\Exception;

/** 403: the key's scope, plan or domain does not allow this, or the account is suspended. */
class PermissionException extends MeilException
{
}
