<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_login_and_register_screens_render(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/register')->assertOk();
    }

    #[Test]
    public function a_new_user_can_register_and_is_signed_in(): void
    {
        Livewire::test(Register::class)
            ->set('name', 'Farish')
            ->set('email', 'farish@example.com')
            ->set('password', 'correct-horse-battery')
            ->set('password_confirmation', 'correct-horse-battery')
            ->call('register')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'farish@example.com']);
        $this->assertAuthenticated();
    }

    #[Test]
    public function the_password_is_never_stored_in_plain_text(): void
    {
        Livewire::test(Register::class)
            ->set('name', 'Farish')
            ->set('email', 'farish@example.com')
            ->set('password', 'correct-horse-battery')
            ->set('password_confirmation', 'correct-horse-battery')
            ->call('register');

        $user = User::query()->firstOrFail();

        $this->assertNotSame('correct-horse-battery', $user->password);
        $this->assertTrue(Hash::check('correct-horse-battery', $user->password));
    }

    #[Test]
    public function registration_requires_a_matching_confirmation(): void
    {
        Livewire::test(Register::class)
            ->set('name', 'Farish')
            ->set('email', 'farish@example.com')
            ->set('password', 'correct-horse-battery')
            ->set('password_confirmation', 'something-else')
            ->call('register')
            ->assertHasErrors('password');

        $this->assertGuest();
    }

    #[Test]
    public function registration_rejects_an_email_already_in_use(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::test(Register::class)
            ->set('name', 'Farish')
            ->set('email', 'taken@example.com')
            ->set('password', 'correct-horse-battery')
            ->set('password_confirmation', 'correct-horse-battery')
            ->call('register')
            ->assertHasErrors('email');
    }

    #[Test]
    public function a_registered_user_can_sign_in(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'correct-horse-battery')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function signing_in_with_the_wrong_password_fails(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function an_unknown_email_gives_the_same_error_as_a_wrong_password(): void
    {
        // Distinct messages would let the form be used to enumerate accounts.
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        $wrongPassword = Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->errors()
            ->first('email');

        $unknownEmail = Livewire::test(Login::class)
            ->set('email', 'nobody@example.com')
            ->set('password', 'wrong-password')
            ->call('login')
            ->errors()
            ->first('email');

        $this->assertSame($wrongPassword, $unknownEmail);
    }

    #[Test]
    public function repeated_failures_are_rate_limited(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        foreach (range(1, 5) as $ignored) {
            Livewire::test(Login::class)
                ->set('email', $user->email)
                ->set('password', 'wrong-password')
                ->call('login')
                ->assertHasErrors('email');
        }

        // The sixth attempt is refused on throttling, not on credentials — even
        // though the password supplied this time is the correct one.
        $error = Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'correct-horse-battery')
            ->call('login')
            ->errors()
            ->first('email');

        $this->assertStringContainsString('seconds', $error);
        $this->assertGuest();
    }

    #[Test]
    public function a_signed_in_user_can_sign_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect(route('items.index'));

        $this->assertGuest();
    }

    #[Test]
    public function signing_out_requires_a_post(): void
    {
        // A GET logout route can be triggered by a prefetch or a crawler.
        $this->actingAs(User::factory()->create())
            ->get('/logout')
            ->assertMethodNotAllowed();
    }

    #[Test]
    public function the_watchlist_is_closed_to_guests(): void
    {
        $this->get('/watchlist')->assertRedirect(route('login'));
    }

    #[Test]
    public function browsing_prices_stays_open_to_guests(): void
    {
        $this->get('/')->assertOk();
        $this->get('/pipeline')->assertOk();
    }
}
