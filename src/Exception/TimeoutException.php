<?php

declare(strict_types=1);

namespace Cotizave\Exception;

/** The request was aborted because it exceeded the configured timeout. */
class TimeoutException extends ConnectionException
{
}
