<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Admin-only: adds an employee to the admin's own company and emails them a
 * set-password link. The user never has a password we know — sending one by
 * email is what we're avoiding.
 */
class InviteEmployee
{
    public function __invoke(Company $company, string $name, string $email, string $role): User
    {
        $user = new User([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(Str::random(40)),
            'is_active' => true,
        ]);
        $user->company_id = $company->id;
        $user->save();

        $user->assignRole($role);

        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status !== PasswordBroker::RESET_LINK_SENT) {
            report(new RuntimeException("Failed to send invite email to {$user->email}: {$status}"));
        }

        return $user;
    }
}
