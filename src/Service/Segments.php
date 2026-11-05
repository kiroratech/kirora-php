<?php

declare(strict_types=1);

namespace Kirora\Service;

use Generator;
use Kirora\Models\ContactDetail;
use Kirora\Models\Segment;
use Kirora\Models\SegmentPreviewResult;
use Kirora\Models\SegmentSizeHistory;
use Kirora\Models\SegmentUsage;
use Kirora\Page;

/**
 * Audiences described by rules, whose members change as contacts and their events change.
 *
 * Kirora counts a saved segment's members in the background, about every 15 minutes, so
 * `memberCount` and `listContacts()` can be up to that far behind.
 */
class Segments extends Service
{
    /**
     * Fetches a page of segments (listSegments). Filter by `query` and `tag`. See `all()` for
     * every segment.
     *
     * @param  array<string, mixed>  $query  Such as `query`, `tag`, `limit` and `cursor`.
     * @param  array<string, mixed>  $options
     * @return Page<Segment>
     */
    public function list(array $query = [], array $options = []): Page
    {
        return $this->page(Segment::class, '/v1/segments', $query, $options);
    }

    /**
     * Iterates over every segment, fetching pages as it goes (listSegments).
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return Generator<int, Segment>
     */
    public function all(array $query = [], array $options = []): Generator
    {
        return $this->every(Segment::class, '/v1/segments', $query, $options);
    }

    /**
     * Creates a segment from a name and its rules (createSegment). Retries reuse one
     * idempotency key, so they never create two.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function create(array $params, array $options = []): Segment
    {
        return $this->one(Segment::class, 'POST', '/v1/segments', [], $params, $options);
    }

    /**
     * Counts and samples the contacts some rules would match, without saving a segment
     * (previewSegment). Safe to retry.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function preview(array $params, array $options = []): SegmentPreviewResult
    {
        return $this->one(SegmentPreviewResult::class, 'POST', '/v1/segments/preview', [], $params, $options);
    }

    /**
     * Fetches a segment (getSegment).
     *
     * @param  array<string, mixed>  $options
     */
    public function get(string $segmentId, array $options = []): Segment
    {
        return $this->one(Segment::class, 'GET', self::path('/v1/segments/%s', $segmentId), [], null, $options);
    }

    /**
     * Changes a segment's name, description, tags or rules (updateSegment). Safe to retry.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function update(string $segmentId, array $params, array $options = []): Segment
    {
        return $this->one(Segment::class, 'PATCH', self::path('/v1/segments/%s', $segmentId), [], $params, $options);
    }

    /**
     * Deletes a segment; the contacts it matched are kept (deleteSegment). Refused with
     * `IN_USE` while an unsent campaign, an active or paused journey, or another segment uses
     * it; see `usage()`. Safe to retry: a retry that finds it already deleted succeeds.
     *
     * @param  array<string, mixed>  $options
     */
    public function delete(string $segmentId, array $options = []): void
    {
        $this->none('DELETE', self::path('/v1/segments/%s', $segmentId), [], $options);
    }

    /**
     * Fetches a page of the contacts in a segment (listSegmentContacts).
     *
     * @param  array<string, mixed>  $query  Such as `limit` and `cursor`.
     * @param  array<string, mixed>  $options
     * @return Page<ContactDetail>
     */
    public function listContacts(string $segmentId, array $query = [], array $options = []): Page
    {
        return $this->page(ContactDetail::class, self::path('/v1/segments/%s/contacts', $segmentId), $query, $options);
    }

    /**
     * Iterates over every contact in a segment (listSegmentContacts).
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return Generator<int, ContactDetail>
     */
    public function allContacts(string $segmentId, array $query = [], array $options = []): Generator
    {
        return $this->every(ContactDetail::class, self::path('/v1/segments/%s/contacts', $segmentId), $query, $options);
    }

    /**
     * Fetches a page of the contacts who joined or left a segment on a day
     * (listSegmentMembershipChanges).
     *
     * @param  array{change: 'joined'|'left', day: string, limit?: int, cursor?: string}  $query
     *                                                                                          `day` is `YYYY-MM-DD` in the workspace's timezone.
     * @param  array<string, mixed>  $options
     * @return Page<ContactDetail>
     */
    public function listMembershipChanges(string $segmentId, array $query, array $options = []): Page
    {
        return $this->page(ContactDetail::class, self::path('/v1/segments/%s/membership-changes', $segmentId), $query, $options);
    }

    /**
     * Iterates over every contact who joined or left a segment on a day
     * (listSegmentMembershipChanges).
     *
     * @param  array{change: 'joined'|'left', day: string, limit?: int}  $query
     * @param  array<string, mixed>  $options
     * @return Generator<int, ContactDetail>
     */
    public function allMembershipChanges(string $segmentId, array $query, array $options = []): Generator
    {
        return $this->every(ContactDetail::class, self::path('/v1/segments/%s/membership-changes', $segmentId), $query, $options);
    }

    /**
     * Returns a segment's size day by day, with how many joined and left
     * (getSegmentSizeHistory).
     *
     * @param  array{from?: string, to?: string}  $query  `to` is exclusive: to include a
     *                                                    whole last day, pass the day after it.
     * @param  array<string, mixed>  $options
     */
    public function sizeHistory(string $segmentId, array $query = [], array $options = []): SegmentSizeHistory
    {
        return $this->one(SegmentSizeHistory::class, 'GET', self::path('/v1/segments/%s/size-history', $segmentId), $query, null, $options);
    }

    /**
     * Returns the campaigns, journeys and segments that use a segment (getSegmentUsage).
     *
     * @param  array<string, mixed>  $options
     */
    public function usage(string $segmentId, array $options = []): SegmentUsage
    {
        return $this->one(SegmentUsage::class, 'GET', self::path('/v1/segments/%s/usage', $segmentId), [], null, $options);
    }
}
