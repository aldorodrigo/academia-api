<?php

namespace App\Support\Tenancy;

use App\Models\Organization;

/**
 * Organización activa en la petición o el job actual.
 *
 * La setean los middlewares del panel y de la API; los jobs y comandos
 * que operan sobre datos de una organización deben usar run().
 */
class CurrentOrganization
{
    private ?Organization $organization = null;

    public function set(?Organization $organization): void
    {
        $this->organization = $organization;

        setPermissionsTeamId($organization?->getKey());
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?int
    {
        return $this->organization?->getKey();
    }

    public function check(): bool
    {
        return $this->organization !== null;
    }

    /**
     * Ejecuta el callback con la organización dada y restaura la anterior.
     *
     * @template T
     *
     * @param  callable(Organization): T  $callback
     * @return T
     */
    public function run(Organization $organization, callable $callback): mixed
    {
        $previous = $this->organization;
        $this->set($organization);

        try {
            return $callback($organization);
        } finally {
            $this->set($previous);
        }
    }
}
