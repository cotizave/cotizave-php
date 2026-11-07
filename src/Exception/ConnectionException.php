<?php

declare(strict_types=1);

namespace Cotizave\Exception;

/** The request failed before any response (DNS, TCP, TLS, abort). */
class ConnectionException extends CotizaveException
{
}
