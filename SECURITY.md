# Security Policy

## Reporting a vulnerability

Please report security issues privately. **Do not open a public GitHub issue for
a vulnerability.**

- Email: **security@cotizave.com**
- Or use GitHub's [private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability)
  on this repository.

Include a description, reproduction steps, affected versions, and impact. We aim
to acknowledge within a few business days and will coordinate a fix and
disclosure timeline with you.

## Handling API keys

This SDK is designed for **server-side** use.

- Never embed a private API key in client-side code.
- Load the key from a server-side secret (environment variable or secret
  manager), never from source control.
- The client reads `COTIZAVE_API_KEY` from the environment when no `apiKey` is
  passed, so the key never needs to appear in your source.

The SDK sends your key only as the `X-API-Key` header to the configured
`baseUrl`, never in a URL or query string. It is not exposed on `$client->config`
or in exception messages.

## Transport hardening

- **Origin pinning.** Every request is pinned to the configured origin. A path
  that resolves to a different host is refused (`invalid_request_path`), so a
  crafted path cannot send your key elsewhere.
- **No redirects.** Redirects are never followed — `allow_redirects` is forced
  off on every request, even when a custom HTTP client is injected, and a
  redirect response is turned into an error (`unexpected_redirect`). This
  prevents the key from being forwarded to another origin.
- **HTTPS required.** A non-HTTPS `baseUrl` is rejected unless you explicitly set
  `allowInsecureHttp => true` (intended only for local testing).
- **Response-size cap.** Bodies are capped at `maxResponseBytes` (default 25 MB);
  a larger body is rejected with `response_too_large`. The client never inflates
  a compressed body (`decode_content` is off), so a decompression bomb is never
  expanded, and every request is bounded in time by the per-request `timeout`.
- **Header-injection guard.** Caller-supplied header names and values are
  validated (no CR/LF/control characters) and cannot override `X-API-Key`.

## Supply chain

- Runtime dependencies are Guzzle and the PSR HTTP interfaces. `composer audit`
  is run in CI and must report no known advisories.
- Releases are built on GitHub-hosted runners.

## Supported versions

The latest released version receives security fixes. Pin a version and upgrade
promptly when a security release is published.
