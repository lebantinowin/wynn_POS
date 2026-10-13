<?php

namespace App\Console\Commands;

use App\Models\User;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

#[Signature('owner:setup {--reset : Replace the existing owner password from this local terminal}')]
#[Description('Privately create the single owner account, or reset its password locally')]
class SetupOwner extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run this command interactively. Passwords must be entered at the hidden prompt.');

            return self::FAILURE;
        }
        $reset = (bool) $this->option('reset');
        $owner = User::query()->find(User::OWNER_ID);
        if (($owner || User::query()->exists()) && ! $reset) {
            $this->error('An account already exists. No changes made. Use owner:setup --reset to recover the owner password.');

            return self::FAILURE;
        }
        if ($reset && ! $owner) {
            $this->error('No owner account exists. Run owner:setup without --reset first.');

            return self::FAILURE;
        }
        if ($reset && ! $this->confirm('Reset the owner password and require all devices to sign in again?', false)) {
            return self::FAILURE;
        }
        $name = $reset ? $owner->name : trim((string) $this->ask('Owner name'));
        $email = $reset ? $owner->email : strtolower(trim((string) $this->ask('Owner email')));
        $this->line('Use at least 12 characters, with uppercase and lowercase letters, a number and a symbol.');
        try {
            $password = (string) $this->secret('Password (hidden)', false);
            $passwordConfirmation = (string) $this->secret('Confirm password (hidden)', false);
        } catch (RuntimeException) {
            $this->error('This terminal cannot hide password entry. Open an interactive PowerShell terminal and try again.');

            return self::FAILURE;
        }
        $validator = Validator::make([
            'name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $passwordConfirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols(), function (string $attribute, string $value, Closure $fail): void {
                if (strlen($value) > 72) {
                    $fail('The password must not exceed 72 bytes.');
                }
            }],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        try {
            DB::transaction(function () use ($reset, $name, $email, $password): void {
                if ($reset) {
                    $owner = User::query()->whereKey(User::OWNER_ID)->lockForUpdate()->firstOrFail();
                    $owner->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                } else {
                    $owner = new User;
                    $owner->forceFill(['id' => User::OWNER_ID, 'name' => $name, 'email' => $email, 'password' => $password])->save();
                }
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'])) {
                $this->error('An account was created by another setup. No account was replaced.');

                return self::FAILURE;
            }
            throw $exception;
        }
        $this->info($reset ? 'Owner password updated. Sign in again on each device.' : 'Owner account created. You can now sign in to Wynn\'s Mart.');

        return self::SUCCESS;
    }
}
