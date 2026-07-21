<?php
namespace App;

use Exception;
use SimpleXMLElement;

/**
 * BoardGameGeek XML API2 Client (official, read-only)
 * Zero-dependency curl + SimpleXML wrapper, following StripeHelper's conventions.
 *
 * BGG requires every XML API2 consumer to register an application and send an
 * "Authorization: Bearer <token>" header on every request (rolled out July 2025) --
 * see boardgamegeek.com/using_the_xml_api. There is no write API; collection
 * mutations are handled separately by BggCollectionSync's reverse-engineered flow.
 */
class BggClient {

    private const BASE_URL = 'https://boardgamegeek.com/xmlapi2';

    /**
     * Search BGG's game database by name (used by the librarian "add a game" flow).
     * @return array<int, array{bgg_id: int, name: string, year_published: ?int}>
     * @throws Exception
     */
    public static function search(string $query): array {
        $xml = self::request('/search', ['query' => $query, 'type' => 'boardgame']);

        $results = [];
        foreach ($xml->item as $item) {
            $bggId = (int)$item['id'];
            $name = self::primaryName($item);
            if ($name === null) {
                continue; // no usable name on this result, skip it
            }
            $yearEl = $item->yearpublished ?? null;
            $results[] = [
                'bgg_id' => $bggId,
                'name' => $name,
                'year_published' => $yearEl !== null && (string)$yearEl['value'] !== '' ? (int)$yearEl['value'] : null,
            ];
        }
        return $results;
    }

    /**
     * Fetch full metadata for a batch of BGG game ids. Batches internally in
     * chunks of 20 to keep request URLs reasonable.
     * @param int[] $bggIds
     * @return array<int, array> bgg_id => details, in the shape tgg_games expects
     * @throws Exception
     */
    public static function thing(array $bggIds): array {
        $bggIds = array_values(array_unique(array_map('intval', $bggIds)));
        $results = [];
        $chunks = array_chunk($bggIds, 20);

        foreach ($chunks as $i => $chunk) {
            if ($i > 0) {
                // Courtesy pacing between batches -- BGG enforces a rate limit on
                // this endpoint and back-to-back chunked requests can trip it.
                usleep(500000);
            }
            $xml = self::request('/thing', ['id' => implode(',', $chunk), 'stats' => 1]);

            foreach ($xml->item as $item) {
                $bggId = (int)$item['id'];
                $results[$bggId] = self::parseThingItem($item);
            }
        }

        return $results;
    }

    /**
     * Fetch a user's owned boardgame collection. Handles BGG's known async-queue
     * behavior: a fresh request can return 202 with a "processing" placeholder
     * body -- poll with backoff until the real 200 response arrives.
     * @return array<int, array{bgg_id: int, name: string, year_published: ?int, thumbnail_url: ?string, image_url: ?string, comment: ?string}>
     * @throws Exception
     */
    public static function collection(string $username): array {
        $xml = self::request('/collection', [
            'username' => $username,
            'subtype' => 'boardgame',
            'excludesubtype' => 'boardgameexpansion',
        ]);

        $results = [];
        foreach ($xml->item as $item) {
            $bggId = (int)$item['objectid'];
            $results[] = [
                'bgg_id' => $bggId,
                'name' => trim((string)$item->name),
                'year_published' => (string)$item->yearpublished !== '' ? (int)$item->yearpublished : null,
                'thumbnail_url' => (string)($item->thumbnail ?? '') ?: null,
                'image_url' => (string)($item->image ?? '') ?: null,
                'comment' => (string)($item->comment ?? '') ?: null,
            ];
        }
        return $results;
    }

    /**
     * Resolve a BGG username to its numeric user id, needed by
     * BggCollectionSync's write-side "does this game already have a
     * collection entry" lookups (which key off userid, not username).
     * @throws Exception
     */
    public static function resolveUserId(string $username): int {
        $xml = self::request('/user', ['name' => $username]);
        $id = (int)($xml['id'] ?? 0);
        if ($id === 0) {
            throw new Exception("Could not resolve BGG user id for '{$username}'.");
        }
        return $id;
    }

    /**
     * Pick the item's primary name if one is tagged, otherwise fall back to the
     * first name present (search results can otherwise return only alternates
     * when the query matched a translated/alternate title).
     */
    private static function primaryName(SimpleXMLElement $item): ?string {
        $first = null;
        foreach ($item->name as $nameEl) {
            $value = (string)$nameEl['value'];
            if ($first === null) {
                $first = $value;
            }
            if ((string)$nameEl['type'] === 'primary') {
                return $value;
            }
        }
        return $first;
    }

    private static function parseThingItem(SimpleXMLElement $item): array {
        $mechanisms = [];
        $categories = [];
        foreach ($item->link as $link) {
            $type = (string)$link['type'];
            $value = (string)$link['value'];
            if ($type === 'boardgamemechanic') {
                $mechanisms[] = $value;
            } elseif ($type === 'boardgamecategory') {
                $categories[] = $value;
            }
        }

        $stats = $item->statistics->ratings ?? null;

        return [
            'name' => self::primaryName($item) ?? '',
            'year_published' => (string)($item->yearpublished['value'] ?? '') !== '' ? (int)$item->yearpublished['value'] : null,
            'thumbnail_url' => (string)($item->thumbnail ?? '') ?: null,
            'image_url' => (string)($item->image ?? '') ?: null,
            'description' => (string)($item->description ?? '') ?: null,
            'min_players' => self::intAttr($item->minplayers ?? null),
            'max_players' => self::intAttr($item->maxplayers ?? null),
            'min_playtime' => self::intAttr($item->minplaytime ?? null),
            'max_playtime' => self::intAttr($item->maxplaytime ?? null),
            'min_age' => self::intAttr($item->minage ?? null),
            'bgg_rating_bayes' => $stats !== null && (string)$stats->bayesaverage['value'] !== '' ? (float)$stats->bayesaverage['value'] : null,
            'bgg_weight' => $stats !== null && (string)$stats->averageweight['value'] !== '' ? (float)$stats->averageweight['value'] : null,
            'mechanisms' => $mechanisms,
            'categories' => $categories,
        ];
    }

    private static function intAttr(?SimpleXMLElement $el): ?int {
        if ($el === null || (string)$el['value'] === '') {
            return null;
        }
        return (int)$el['value'];
    }

    /**
     * GET a BGG XML API2 endpoint and parse the response as XML, retrying with
     * backoff on responses that mean "try again shortly" rather than failure:
     * 202 (BGG queued the request -- mainly seen on /collection for a cold
     * cache) and 429 (rate limited -- seen when hitting /thing in quick
     * succession, e.g. many chunked batches back-to-back).
     * @throws Exception
     */
    private static function request(string $path, array $query, int $maxAttempts = 10, int $waitSeconds = 5): SimpleXMLElement {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            [$httpCode, $body] = self::rawRequest($path, $query);

            if ($httpCode === 200) {
                return self::parseXml($body);
            }
            if (($httpCode === 202 || $httpCode === 429) && $attempt < $maxAttempts) {
                sleep($waitSeconds);
                continue;
            }
            throw new Exception("BGG API error (HTTP {$httpCode}) for {$path}: " . substr($body, 0, 300));
        }
        throw new Exception("BGG API request for {$path} never completed after {$maxAttempts} attempts.");
    }

    /**
     * @return array{0: int, 1: string} [httpCode, rawBody]
     * @throws Exception
     */
    private static function rawRequest(string $path, array $query): array {
        $token = $_ENV['BGG_API_TOKEN'] ?? '';
        if (empty($token)) {
            throw new Exception("BGG_API_TOKEN is not configured in environment.");
        }

        $url = self::BASE_URL . $path . '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("BGG API request failed for {$path}: " . $curlErr);
        }

        return [$httpCode, $response];
    }

    /** @throws Exception */
    private static function parseXml(string $body): SimpleXMLElement {
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($xml === false) {
            throw new Exception("BGG API returned unparseable XML: " . substr($body, 0, 300));
        }
        return $xml;
    }
}
