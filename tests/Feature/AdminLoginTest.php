<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['password' => 'secret-pass']);
        $admin->assignRole(Role::findOrCreate(User::ROLE_ADMIN, 'web'));

        return $admin;
    }

    public function test_admin_login_requires_captcha(): void
    {
        Filament::setCurrentPanel('admin');
        $admin = $this->admin();

        Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'secret-pass')
            ->call('authenticate')
            ->assertHasErrors(['data.captcha' => 'required']);

        $this->assertGuest();
    }

    public function test_admin_logs_in_with_captcha(): void
    {
        Filament::setCurrentPanel('admin');
        $admin = $this->admin();

        Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'secret-pass')
            ->set('data.captcha', 'test-token')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($admin);
    }
}
