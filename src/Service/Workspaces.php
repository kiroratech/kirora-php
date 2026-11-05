<?php

declare(strict_types=1);

namespace Kirora\Service;

use Generator;
use Kirora\Models\Workspace;
use Kirora\Page;

/**
 * Partner keys only: your tenants' workspaces. Each is named by the `externalId` you give
 * it; act in one with `$kirora->forWorkspace($externalId)`.
 */
class Workspaces extends Service
{
    /**
     * Fetches a page of workspaces (listWorkspaces). See `all()` for every one.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return Page<Workspace>
     */
    public function list(array $query = [], array $options = []): Page
    {
        return $this->page(Workspace::class, '/v1/workspaces', $query, $options);
    }

    /**
     * Iterates over every workspace, fetching pages as it goes (listWorkspaces).
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return Generator<int, Workspace>
     */
    public function all(array $query = [], array $options = []): Generator
    {
        return $this->every(Workspace::class, '/v1/workspaces', $query, $options);
    }

    /**
     * Creates a workspace (createWorkspace). Retries reuse one idempotency key, so they never
     * create two.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function create(array $params, array $options = []): Workspace
    {
        return $this->one(Workspace::class, 'POST', '/v1/workspaces', [], $params, $options);
    }

    /**
     * Fetches a workspace (getWorkspace).
     *
     * @param  array<string, mixed>  $options
     */
    public function get(string $externalId, array $options = []): Workspace
    {
        return $this->one(Workspace::class, 'GET', self::path('/v1/workspaces/%s', $externalId), [], null, $options);
    }

    /**
     * Changes a workspace (updateWorkspace). Safe to retry.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function update(string $externalId, array $params, array $options = []): Workspace
    {
        return $this->one(Workspace::class, 'PATCH', self::path('/v1/workspaces/%s', $externalId), [], $params, $options);
    }

    /**
     * Schedules a workspace's deletion, which `cancelDeletion()` can undo until it happens,
     * and returns the workspace in its pending state (deleteWorkspace).
     *
     * @param  array<string, mixed>  $options
     */
    public function delete(string $externalId, array $options = []): Workspace
    {
        return $this->one(Workspace::class, 'DELETE', self::path('/v1/workspaces/%s', $externalId), [], null, $options);
    }

    /**
     * Stops every send in a workspace until it's resumed, dropping what's queued
     * (suspendWorkspace). Reading and editing keep working.
     *
     * @param  array<string, mixed>  $options
     */
    public function suspend(string $externalId, array $options = []): Workspace
    {
        return $this->one(Workspace::class, 'POST', self::path('/v1/workspaces/%s/suspend', $externalId), [], null, $options);
    }

    /**
     * Lets a suspended workspace send again (resumeWorkspace).
     *
     * @param  array<string, mixed>  $options
     */
    public function resume(string $externalId, array $options = []): Workspace
    {
        return $this->one(Workspace::class, 'POST', self::path('/v1/workspaces/%s/resume', $externalId), [], null, $options);
    }

    /**
     * Stops a workspace's scheduled deletion (cancelWorkspaceDeletion).
     *
     * @param  array<string, mixed>  $options
     */
    public function cancelDeletion(string $externalId, array $options = []): Workspace
    {
        return $this->one(Workspace::class, 'POST', self::path('/v1/workspaces/%s/cancel-deletion', $externalId), [], null, $options);
    }
}
