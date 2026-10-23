<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class ProvisionUser extends Command
{
    protected $signature = 'qa:user {email} {--name=QA Manager} {--role=manager} {--reset-password}';

    protected $description = 'Provision a local reviewer and save generated credentials to private, ignored storage.';

    public function handle(): int
    {
        $email = $this->argument('email');
        $role = $this->option('role');

        Validator::make(['email' => $email, 'role' => $role], ['email' => 'required|email', 'role' => 'in:admin,manager'])->validate();

        $user = User::where('email', $email)->first();

        if ($user && !$this->option('reset-password'))
        {
            $this->info('Account already exists. Use --reset-password to generate new credentials.');

            return self::SUCCESS;
        }
        $password = Str::password(20);

        $user = User::updateOrCreate(['email' => $email], ['name' => $this->option('name'), 'role' => $role, 'password' => $password]);

        Storage::disk('local')->put('local-access.json', json_encode(['email' => $user->email, 'password' => $password], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->info('Account provisioned. Credentials: storage/app/private/local-access.json (never commit or share).');

        return self::SUCCESS;
    }
}
