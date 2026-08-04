<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Minimal staging fixtures only — roles/permissions + optional staging admin.
 * Refuses production. Does not seed demo restaurants or marketplace catalogues.
 */
class StagingSeedMinimalCommand extends Command
{
    protected $signature = 'staging:seed-minimal
        {--with-admin : Create/update staging super-admin from STAGING_ADMIN_* env}
        {--force : Required confirmation flag}';

    protected $description = 'Seed roles/permissions (and optional staging admin). Refuses production.';

    public function handle(): int
    {
        $env = (string) config('app.env');
        if (in_array($env, ['production', 'prod'], true)) {
            $this->error('Refusing staging:seed-minimal in production.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->error('Pass --force to run staging:seed-minimal (explicit opt-in).');

            return self::FAILURE;
        }

        $this->seedRolesAndPermissions();
        $this->info('Roles and permissions ensured.');

        if ($this->option('with-admin')) {
            $this->seedStagingAdmin();
        } else {
            $this->comment('Skipped admin user (pass --with-admin and set STAGING_ADMIN_*).');
        }

        $this->comment('Did not create demo businesses, Suvakamana, or catalogue fixtures.');

        return self::SUCCESS;
    }

    private function seedRolesAndPermissions(): void
    {
        foreach (config('suvakamana.permissions', []) as $slug) {
            Permission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => Str::headline($slug)],
            );
        }

        // Align with DatabaseSeeder role map via artisan calling seeder subset is heavy;
        // ensure core role rows exist; permissions sync is done by full seeder when needed.
        foreach (['customer', 'restaurant_owner', 'restaurant_manager', 'restaurant_staff', 'super_admin'] as $slug) {
            Role::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => Str::headline($slug), 'guard' => 'web'],
            );
        }
    }

    private function seedStagingAdmin(): void
    {
        $email = (string) env('STAGING_ADMIN_EMAIL', '');
        $password = (string) env('STAGING_ADMIN_PASSWORD', '');

        if ($email === '' || $password === '') {
            throw new RuntimeException('STAGING_ADMIN_EMAIL and STAGING_ADMIN_PASSWORD are required with --with-admin.');
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('STAGING_ADMIN_EMAIL is invalid.');
        }

        $user = User::query()->updateOrCreate(
            ['email' => Str::lower($email)],
            [
                'first_name' => 'Staging',
                'last_name' => 'Admin',
                'password' => Hash::make($password),
                'status' => 'active',
                'email_verified_at' => now(),
            ],
        );

        $role = Role::query()->where('slug', 'super_admin')->firstOrFail();
        if (! $user->roles()->where('roles.id', $role->id)->exists()) {
            $user->roles()->attach($role->id, ['restaurant_id' => null]);
        }

        $this->info('Staging super-admin ensured (password not printed).');
    }
}
