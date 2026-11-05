<?php

declare(strict_types=1);

namespace Kirora\Service;

use Generator;
use Kirora\Models\AddedSuppressions;
use Kirora\Models\DeletedSuppressions;
use Kirora\Models\Suppression;
use Kirora\Page;

/**
 * Email addresses Kirora won't send to, such as after a bounce or complaint.
 */
class Suppressions extends Service
{
    /**
     * Fetches a page of suppressions (listSuppressions). See `all()` for every one.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return Page<Suppression>
     */
    public function list(array $query = [], array $options = []): Page
    {
        return $this->page(Suppression::class, '/v1/suppressions', $query, $options);
    }

    /**
     * Iterates over every suppression, fetching pages as it goes (listSuppressions).
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return Generator<int, Suppression>
     */
    public function all(array $query = [], array $options = []): Generator
    {
        return $this->every(Suppression::class, '/v1/suppressions', $query, $options);
    }

    /**
     * Suppresses addresses (addSuppressions). Addresses already suppressed are skipped. A key
     * is made for the call, so a retry replays the first result.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function add(array $params, array $options = []): AddedSuppressions
    {
        return $this->one(AddedSuppressions::class, 'POST', '/v1/suppressions', [], $params, $options);
    }

    /**
     * Removes suppressions, so the addresses can be sent to again (deleteSuppressions).
     * Addresses not suppressed are skipped. A key is made for the call, so a retry replays the
     * first result rather than reporting them not found.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function delete(array $params, array $options = []): DeletedSuppressions
    {
        return $this->one(DeletedSuppressions::class, 'POST', '/v1/suppressions/delete', [], $params, $options);
    }
}
