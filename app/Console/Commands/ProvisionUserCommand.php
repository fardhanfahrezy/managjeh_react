<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class ProvisionUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:provision
        {--name= : Full name of the user}
        {--email= : Email address of the user}
        {--password= : Password for the account}
        {--timezone=Asia/Jakarta : IANA timezone for the user}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Provision a user account manually when public registration is disabled';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Name'));
        $email = Str::lower((string) ($this->option('email') ?: $this->ask('Email address')));
        $password = (string) ($this->option('password') ?: $this->secret('Password'));
        $timezone = (string) ($this->option('timezone') ?: 'Asia/Jakarta');

        $validator = Validator::make(
            [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'timezone' => $timezone,
            ],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'string', Password::defaults()],
                'timezone' => ['required', 'string', 'max:64', 'timezone:all'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'timezone' => $timezone,
                'reminder_quiet_start' => '00:00:00',
                'reminder_quiet_end' => '05:00:00',
            ],
        );

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $this->info("User [{$user->email}] provisioned successfully (ID: {$user->id}).");

        return self::SUCCESS;
    }
}
