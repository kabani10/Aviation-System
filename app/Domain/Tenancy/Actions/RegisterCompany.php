<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Self-service tenant signup: a new company plus its first user, who is
 * always the company's Admin. Every subsequent employee is invited by that
 * Admin (see InviteEmployee), never through this path.
 */
class RegisterCompany
{
    public function __invoke(string $companyName, string $adminName, string $adminEmail, string $password): User
    {
        return DB::transaction(function () use ($companyName, $adminName, $adminEmail, $password) {
            $company = Company::create([
                'name' => $companyName,
                'slug' => $this->uniqueSlug($companyName),
            ]);

            $user = new User([
                'name' => $adminName,
                'email' => $adminEmail,
                'password' => $password,
                'is_active' => true,
            ]);
            $user->company_id = $company->id;
            $user->save();

            $user->assignRole('Admin');

            return $user;
        });
    }

    private function uniqueSlug(string $companyName): string
    {
        $base = Str::slug($companyName);
        $slug = $base;
        $suffix = 1;

        while (Company::where('slug', $slug)->exists()) {
            $slug = "{$base}-".++$suffix;
        }

        return $slug;
    }
}
