<?php

declare(strict_types=1);

namespace Kirora\Service;

use Kirora\Models\EmbedSession;

/**
 * Partner keys only: sessions for embedding Kirora's builders in your app.
 */
class EmbedSessions extends Service
{
    /**
     * Creates a session for one tenant, so call it on `$kirora->forWorkspace()`
     * (createEmbedSession). Its token lasts 15 minutes; give it to the embedded builders from
     * your server. A key you pass must be new per session, since reusing one returns the first
     * session.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function create(array $params, array $options = []): EmbedSession
    {
        return $this->one(EmbedSession::class, 'POST', '/v1/embed-sessions', [], $params, $options);
    }
}
