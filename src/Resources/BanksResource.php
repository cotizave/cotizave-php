<?php

declare(strict_types=1);

namespace Cotizave\Resources;

/**
 * Bank identities (no prices).
 *
 * @phpstan-import-type RequestConfig from Resource
 */
final class BanksResource extends Resource
{
    /**
     * List a country's banks ({ "banks": [...] }); optional free-text search via q.
     *
     * @param array{q?: string} $options
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function list(string $country, array $options = [], array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get('/v2/banks/' . self::cc($country), ['q' => $options['q'] ?? null], $config);
    }

    /**
     * One bank by its official code (SUDEBAN in VE).
     *
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function get(string $country, string $code, array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get(
            '/v2/banks/' . self::cc($country) . '/' . self::seg($code, 'bank code'),
            [],
            $config,
        );
    }
}
