# Changelog

All notable changes to this project are documented here. This project adheres
to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Initial release of the official PHP SDK for the Cotizave API v2.
- `Cotizave\Cotizave` client with typed resources: `rates`, `history`,
  `countries`, `currencies`, `banks`, `issuers`, `holidays`, `crypto`, plus
  top-level `convert`, `convertBulk`, `markets`, `spread`, `route` and
  `bankRates`.
- Typed exception hierarchy (`CotizaveException` and subclasses), controlled
  retries with full jitter and a time budget, a response-size cap, origin
  pinning and redirect refusal to protect the API key.
- Built on Guzzle; a PSR-18 client can be injected. PHPStan array-shape types
  for responses.
