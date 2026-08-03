<?php

namespace App\Support\Tenancy;

class CurrentCompany
{
    private ?int $companyId = null;

    public function set(?int $companyId): void
    {
        $this->companyId = $companyId;
    }

    public function id(): ?int
    {
        return $this->companyId;
    }

    public function isSet(): bool
    {
        return $this->companyId !== null;
    }

    public function clear(): void
    {
        $this->companyId = null;
    }
}
