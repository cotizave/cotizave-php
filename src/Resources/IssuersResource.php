<?php

declare(strict_types=1);

namespace Cotizave\Resources;

/**
 * Official-rate issuers directory (all plans).
 *
 * @phpstan-import-type RequestConfig from Resource
 */
final class IssuersResource extends Resource
{
    /**
     * List the institutions that issue official rates.
     *
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function list(array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get('/v2/issuers', [], $config);
    }
}
