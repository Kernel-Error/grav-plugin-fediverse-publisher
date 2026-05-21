<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Signature;

/**
 * Strict media-type parser — `str_contains` admits crap like
 * `text/plain; x=application/activity+json` (per R3-6). This helper
 * splits the header properly and checks tokens for equality.
 */
final class MediaType
{
    /**
     * True iff the header advertises an AS 2.0-flavoured JSON content
     * (either `application/activity+json`, or `application/ld+json`
     * with the AS profile parameter).
     */
    public static function isActivityPubJson(string $header): bool
    {
        if ($header === '') {
            return false;
        }
        $parts = explode(';', $header, 2);
        $mediaType = strtolower(trim($parts[0]));

        if ($mediaType === 'application/activity+json') {
            return true;
        }
        if ($mediaType !== 'application/ld+json') {
            return false;
        }

        $params = $parts[1] ?? '';
        return preg_match(
            '/profile\s*=\s*"?https:\/\/www\.w3\.org\/ns\/activitystreams"?/i',
            $params,
        ) === 1;
    }
}
