<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Signature;

use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds + signs an outbound POST so a remote inbox accepts it.
 *
 * The signed-headers set matches what Mastodon, Pleroma, Lemmy and
 * GoToSocial expect for POST deliveries (ADR-002 §4):
 *
 *   (request-target) host date digest content-type
 *
 * `Date` is set to the current UTC time formatted as RFC 7231 GMT,
 * `Digest: SHA-256=<base64>` is computed from the raw request body
 * (always present, even on empty bodies — ADR-002 §5).
 *
 * The signer adds these headers to the request and returns a new PSR-7
 * `RequestInterface`. No mutation of the input.
 */
final class RequestSigner
{
    private const SIGNED_HEADERS = ['(request-target)', 'host', 'date', 'digest', 'content-type'];

    public function __construct(
        private readonly Signer $signer,
        private readonly Clock $clock,
        private readonly LoggerInterface $log = new NullLogger(),
    ) {
    }

    /**
     * @param string $keyId      Public-key URL fragment id, e.g.
     *                            `https://blog.local/activitypub/actor#main-key`
     * @param string $privatePem PEM-encoded RSA private key
     */
    public function sign(RequestInterface $request, string $keyId, string $privatePem): RequestInterface
    {
        $body  = (string) $request->getBody();
        $request->getBody()->rewind();

        // Mandatory headers added by the signer itself.
        $now    = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('D, d M Y H:i:s') . ' GMT';
        $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));

        $request = $request
            ->withHeader('Date', $now)
            ->withHeader('Digest', $digest);
        if (!$request->hasHeader('Content-Type')) {
            $request = $request->withHeader('Content-Type', 'application/activity+json');
        }
        // Host comes from the URL. Force-set (don't trust an existing
        // Host header on the request) so the value we sign matches the
        // value the underlying HTTP client puts on the wire — PSR-7
        // implementations sometimes carry an auto-derived Host that
        // diverges from URI when the URI gets swapped after construction.
        $uri = $request->getUri();
        $host = $uri->getPort() !== null && $uri->getPort() !== 443
            ? $uri->getHost() . ':' . $uri->getPort()
            : $uri->getHost();
        $request = $request->withHeader('Host', $host);

        // Build the signing string in the exact order of SIGNED_HEADERS.
        $lines = [];
        foreach (self::SIGNED_HEADERS as $name) {
            if ($name === '(request-target)') {
                $target = $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');
                $lines[] = '(request-target): ' . strtolower($request->getMethod()) . ' ' . $target;
                continue;
            }
            $lines[] = $name . ': ' . $request->getHeaderLine($name);
        }
        $signingString = implode("\n", $lines);

        $signatureB64 = $this->signer->sign($signingString, $privatePem);

        $headerValue = \sprintf(
            'keyId="%s",algorithm="rsa-sha256",headers="%s",signature="%s"',
            $keyId,
            implode(' ', self::SIGNED_HEADERS),
            $signatureB64,
        );

        // Debug log lets the dispatcher correlate a failed delivery with
        // the exact bytes that were signed. The signature itself is
        // truncated — only the first 12 chars are useful for matching
        // attempts, and full signatures clutter the log.
        $this->log->debug('outbound HTTP signature built', [
            'key_id'         => $keyId,
            'signing_string' => $signingString,
            'digest'         => $digest,
            'date'           => $now,
            'signature_head' => substr($signatureB64, 0, 12),
        ]);

        return $request->withHeader('Signature', $headerValue);
    }
}
