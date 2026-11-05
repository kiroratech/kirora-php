<?php

declare(strict_types=1);

namespace Kirora\Contracts;

use Kirora\Exceptions\KiroraException;
use Kirora\ValueObjects\Transporter\Payload;
use Kirora\ValueObjects\Transporter\Response;

/**
 * Sends calls to the Kirora API.
 */
interface Transporter
{
    /**
     * Sends the call, retrying as its endpoint allows, and returns what it returned.
     *
     * @throws KiroraException
     */
    public function request(Payload $payload): Response;

    /**
     * A copy whose calls act in the partner workspace with this `externalId`.
     */
    public function withWorkspace(string $externalId): static;
}
