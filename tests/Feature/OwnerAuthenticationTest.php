<?php

namespace Tests\Feature;

use App\Livewire\Checkout;
use App\Models\User;
use App\Support\DemoCatalogue;
use App\Support\SessionCash;
use App\Support\SessionSales;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class OwnerAuthenticationTest extends TestCase
{
    private const PASSWORD = 'Owner-Test-Password42!';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--path' => 'database/migrations/0001_01_01_000000_create_users_table.php', '--force' => true]);
        Auth::forgetGuards();
    }

    private function owner(): User
    {
        return User::factory()->create(['id' => User::OWNER_ID, 'name' => 'Shop owner', 'email' => 'owner@example.test', 'password' => self::PASSWORD]);
    }

    public function test_all_shop_pages_require_owner_login(): void
    {
        foreach (['', 'dashboard', 'checkout', 'products', 'inventory', 'purchasing', 'sales', 'credit', 'losses', 'cash', 'reports', 'settings'] as $section) {
            $this->get('/'.$section)->assertRedirect('/login')->assertDontSee('Bottled drink');
        }
        $this->getJson('/reports')->assertUnauthorized();
    }

    public function test_first_setup_has_no_public_registration_or_default_password(): void
    {
        $this->get('/login')->assertOk()->assertSee('First-time setup')->assertSee('setup-owner.ps1')->assertDontSee('name="password"', false);
        $this->post('/register', ['email' => 'owner@example.test', 'password' => self::PASSWORD])->assertStatus(405);
        $this->post('/setup', ['email' => 'owner@example.test', 'password' => self::PASSWORD])->assertStatus(405);
        $this->assertSame(0, User::query()->count());
    }

    public function test_valid_login_returns_to_requested_page_and_rotates_session(): void
    {
        $owner = $this->owner();
        $this->get('/reports')->assertRedirect('/login');
        $sessionId = session()->getId();
        $this->post('/login', ['email' => ' OWNER@EXAMPLE.TEST ', 'password' => self::PASSWORD])->assertRedirect('/reports');
        $this->assertAuthenticatedAs($owner);
        $this->assertNotSame($sessionId, session()->getId());
        $response = $this->get('/reports')->assertOk()->assertSee('Sign out');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('/login')->assertRedirect('/dashboard');
    }

    public function test_incorrect_and_unknown_credentials_share_error_without_flashing_password(): void
    {
        $this->owner();
        foreach (['owner@example.test', 'unknown@example.test'] as $email) {
            $this->from('/login')->post('/login', ['email' => $email, 'password' => 'Wrong-Password42!'])
                ->assertRedirect('/login')->assertSessionHasErrors(['email' => 'The email or password is incorrect.'])
                ->assertSessionMissing('_old_input.password');
            $this->assertGuest();
        }
    }

    public function test_failed_login_limit_expires_and_allows_retry(): void
    {
        $this->owner();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/login', ['email' => 'owner@example.test', 'password' => 'Wrong-Password42!'])->assertUnprocessable();
        }
        $this->postJson('/login', ['email' => 'owner@example.test', 'password' => self::PASSWORD])->assertStatus(429);
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->post('/login', ['email' => 'owner@example.test', 'password' => self::PASSWORD])->assertRedirect('/dashboard');
        $this->assertAuthenticated();
    }

    public function test_ip_limit_prevents_changing_email_to_bypass_throttling(): void
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson('/login', ['email' => 'invalid-'.$attempt, 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/login', ['email' => 'another@example.test', 'password' => 'wrong'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_malformed_login_inputs_do_not_cause_server_errors(): void
    {
        $this->postJson('/login', ['email' => ['bad'], 'password' => ['bad']])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_other_users_cannot_log_in_or_access_owner_workspace(): void
    {
        $other = User::factory()->create(['id' => 2, 'password' => self::PASSWORD]);
        $this->postJson('/login', ['email' => $other->email, 'password' => self::PASSWORD])->assertUnprocessable();
        $this->assertGuest();
        $this->actingAs($other)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_logout_invalidates_session_and_prevents_access(): void
    {
        $this->owner();
        $this->post('/login', ['email' => 'owner@example.test', 'password' => self::PASSWORD]);
        $this->get('/dashboard')->assertOk();
        $sessionId = session()->getId();
        $token = session()->token();
        session()->put('private-marker', 'old-session');
        $this->post('/logout')->assertRedirect('/login')->assertSessionMissing('private-marker');
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame($token, session()->token());
        $this->assertGuest();
        $this->get('/reports')->assertRedirect('/login');
    }

    public function test_login_and_logout_require_csrf_tokens(): void
    {
        $this->owner();
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->post('/login', ['email' => 'owner@example.test', 'password' => self::PASSWORD])->assertStatus(419);
        $this->assertGuest();
        $this->post('/login', ['email' => 'owner@example.test', 'password' => self::PASSWORD, '_token' => session()->token()])->assertRedirect('/dashboard');
        $this->assertAuthenticated();
        $this->post('/logout')->assertStatus(419);
        $this->assertAuthenticated();
        $this->post('/logout', ['_token' => session()->token()])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_expired_login_cannot_replay_a_mounted_checkout(): void
    {
        $this->actingAs($this->owner());
        SessionCash::open('auth-cash', '100', '');
        $component = Livewire::test(Checkout::class)->call('add', 'drink-one')->set('received', '100')->call('preview');
        $snapshot = $component->snapshot;
        $stock = DemoCatalogue::find(1)['stock'];
        $sales = SessionSales::all();
        Auth::forgetGuards();
        $this->postJson(route('default-livewire.update'), ['components' => [[
            'snapshot' => json_encode($snapshot), 'updates' => [], 'calls' => [['path' => '', 'method' => 'complete', 'params' => []]],
        ]]])->assertUnauthorized();
        $this->assertSame($stock, DemoCatalogue::find(1)['stock']);
        $this->assertSame($sales, SessionSales::all());
    }

    public function test_local_setup_hashes_password_and_cannot_replace_existing_owner(): void
    {
        $this->artisan('owner:setup')->expectsQuestion('Owner name', 'Wynn')->expectsQuestion('Owner email', ' OWNER@EXAMPLE.TEST ')
            ->expectsQuestion('Password (hidden)', self::PASSWORD)->expectsQuestion('Confirm password (hidden)', self::PASSWORD)->assertSuccessful()->run();
        $owner = User::query()->findOrFail(User::OWNER_ID);
        $this->assertSame('owner@example.test', $owner->email);
        $this->assertTrue(Hash::check(self::PASSWORD, $owner->password));
        $this->assertNotSame(self::PASSWORD, $owner->password);
        $hash = $owner->password;
        $this->artisan('owner:setup')->assertFailed()->run();
        $this->assertSame(1, User::query()->count());
        $this->assertSame($hash, $owner->fresh()->password);
    }

    public function test_setup_rejects_weak_unconfirmed_or_oversize_passwords(): void
    {
        foreach ([['weak', 'weak'], [self::PASSWORD, 'different'], [str_repeat('é', 40).'Aa1!', str_repeat('é', 40).'Aa1!']] as [$password, $confirmation]) {
            $this->artisan('owner:setup')->expectsQuestion('Owner name', 'Wynn')->expectsQuestion('Owner email', 'owner@example.test')
                ->expectsQuestion('Password (hidden)', $password)->expectsQuestion('Confirm password (hidden)', $confirmation)->assertFailed()->run();
            $this->assertSame(0, User::query()->count());
        }
    }

    public function test_setup_refuses_noninteractive_creation(): void
    {
        $this->artisan('owner:setup', ['--no-interaction' => true])->assertFailed()->run();
        $this->assertSame(0, User::query()->count());
    }

    public function test_password_reset_revokes_old_password_and_sessions(): void
    {
        $owner = $this->owner();
        $this->post('/login', ['email' => $owner->email, 'password' => self::PASSWORD])->assertRedirect('/dashboard');
        $this->get('/reports')->assertOk();
        $oldToken = $owner->remember_token;
        $newPassword = 'Replacement-Owner43!';
        $this->artisan('owner:setup', ['--reset' => true])
            ->expectsConfirmation('Reset the owner password and require all devices to sign in again?', 'yes')
            ->expectsQuestion('Password (hidden)', $newPassword)->expectsQuestion('Confirm password (hidden)', $newPassword)->assertSuccessful()->run();
        $this->assertNotSame($oldToken, $owner->fresh()->remember_token);
        Auth::forgetGuards();
        $this->get('/reports')->assertRedirect('/login');
        $this->assertGuest();
        $this->postJson('/login', ['email' => $owner->email, 'password' => self::PASSWORD])->assertUnprocessable();
        $this->post('/login', ['email' => $owner->email, 'password' => $newPassword])->assertRedirect('/reports');
        $this->assertAuthenticated();
    }

    public function test_declined_reset_keeps_credentials_unchanged(): void
    {
        $owner = $this->owner();
        $hash = $owner->password;
        $this->artisan('owner:setup', ['--reset' => true])
            ->expectsConfirmation('Reset the owner password and require all devices to sign in again?', 'no')->assertFailed()->run();
        $this->assertSame($hash, $owner->fresh()->password);
    }

    public function test_owner_settings_shows_account_and_recovery(): void
    {
        $this->actingAs($this->owner())->get('/settings')->assertOk()->assertSee('Owner account')->assertSee('owner@example.test')->assertSee(base_path('setup-owner.ps1'))->assertSee('-Reset');
    }
}
