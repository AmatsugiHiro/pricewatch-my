<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Create an account — PriceWatch MY')]
class Register extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function register(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            // Password::defaults() keeps the policy in one place rather than
            // scattering a literal minimum length through the codebase.
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        // The User model casts 'password' => 'hashed', so the plain value is never
        // what reaches the database.
        $user = User::create($validated);

        Event::dispatch(new Registered($user));

        Auth::login($user);
        session()->regenerate();

        $this->redirect(route('watchlist'), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.register');
    }
}
