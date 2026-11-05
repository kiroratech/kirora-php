<?php

declare(strict_types=1);

namespace Kirora\Service;

use InvalidArgumentException;
use Kirora\Contracts\Transporter;

/**
 * Creates each service the first time it's used.
 *
 * @internal
 */
final class ServiceFactory
{
    /** @var array<string, class-string<Service>> */
    public const SERVICES = [
        'contacts' => Contacts::class,
        'lists' => Lists::class,
        'campaigns' => Campaigns::class,
        'transactional' => Transactional::class,
        'events' => Events::class,
        'segments' => Segments::class,
        'messages' => Messages::class,
        'suppressions' => Suppressions::class,
        'workspaces' => Workspaces::class,
        'embedSessions' => EmbedSessions::class,
    ];

    /** @var array<string, Service> */
    private array $services = [];

    public function __construct(
        private readonly Transporter $transporter,
    ) {
    }

    public function has(string $name): bool
    {
        return isset(self::SERVICES[$name]);
    }

    public function get(string $name): Service
    {
        if (! isset(self::SERVICES[$name])) {
            throw new InvalidArgumentException(sprintf(
                'Kirora: there\'s no service "%s". The services are %s.',
                $name,
                implode(', ', array_keys(self::SERVICES)),
            ));
        }

        return $this->services[$name] ??= new (self::SERVICES[$name])($this->transporter);
    }
}
