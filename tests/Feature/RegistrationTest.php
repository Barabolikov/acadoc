<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function register(array $overrides = [])
    {
        return $this->post(route('frontend.register'), $overrides + [
            'name' => 'Петренко Іван',
            'email' => 'petrenko@example.com',
            'password' => 'sekret-123',
            'password_confirmation' => 'sekret-123',
            'requested_role' => 'student',
            ...self::CAPTCHA,
        ]);
    }

    public function test_student_registers_and_is_logged_in_with_student_role(): void
    {
        $this->register()->assertRedirect('/')->assertSessionHas('status');

        $user = User::where('email', 'petrenko@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(['Student'], $user->getRoleNames()->all());
        $this->assertSame('student', $user->requested_role);
    }

    public function test_teacher_registration_still_gets_student_role_until_admin_approves(): void
    {
        $this->register(['requested_role' => 'teacher'])->assertRedirect('/');

        $user = User::where('email', 'petrenko@example.com')->firstOrFail();
        $this->assertSame(['Student'], $user->getRoleNames()->all());
        $this->assertSame('teacher', $user->requested_role);
        $this->assertTrue($user->hasDailyCheckLimit());
    }

    public function test_cannot_request_admin_role(): void
    {
        $this->register(['requested_role' => 'super_admin'])->assertSessionHasErrorsIn('register', 'requested_role');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'petrenko@example.com']);
    }

    public function test_validation_errors(): void
    {
        User::factory()->create(['email' => 'petrenko@example.com']);

        $this->register(['password_confirmation' => 'other-pass'])
            ->assertSessionHasErrorsIn('register', ['email', 'password']);
        $this->assertGuest();
    }

    public function test_registration_modal_is_on_home_page(): void
    {
        $this->get('/')->assertOk()
            ->assertSee(route('frontend.register'))
            ->assertSee('Зареєструватися');
    }

    public function test_admin_approves_teacher_request(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate(User::ROLE_ADMIN, 'web'));
        $applicant = User::factory()->create(['requested_role' => 'teacher']);
        $applicant->assignRole(Role::findOrCreate(User::ROLE_STUDENT, 'web'));

        $this->actingAs($admin);
        Livewire::test(ListUsers::class)
            ->filterTable('teacher_requests')
            ->assertCanSeeTableRecords([$applicant])
            ->assertCanNotSeeTableRecords([$admin])
            ->callAction(TestAction::make('makeTeacher')->table($applicant));

        $applicant->refresh();
        $this->assertSame([User::ROLE_TEACHER], $applicant->getRoleNames()->all());
        $this->assertFalse($applicant->hasDailyCheckLimit());
    }

    public function test_rules_page_is_public_and_linked_from_registration(): void
    {
        $this->get(route('rules'))->assertOk()
            ->assertSee('Правила сервісу')
            ->assertSee('Автоматичне виправлення («Преміум»)', false)
            ->assertSee('@Barabolikov');

        $this->get('/')->assertSee(route('rules'));
    }

    public function test_admin_grants_and_revokes_premium_and_teacher_role(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate(User::ROLE_ADMIN, 'web'));
        $student = User::factory()->create(['requested_role' => 'student']);
        $student->assignRole(Role::findOrCreate(User::ROLE_STUDENT, 'web'));

        $this->actingAs($admin);
        Livewire::test(ListUsers::class)->callAction(TestAction::make('grantPremium')->table($student));
        $this->assertTrue($student->fresh()->isPremium());

        // Викладача можна призначити й без заявки, а потім зняти роль
        Livewire::test(ListUsers::class)->callAction(TestAction::make('makeTeacher')->table($student));
        $this->assertTrue($student->fresh()->hasRole(User::ROLE_TEACHER));
        Livewire::test(ListUsers::class)->callAction(TestAction::make('removeTeacher')->table($student));
        $this->assertSame([User::ROLE_STUDENT], $student->fresh()->getRoleNames()->all());
        $this->assertTrue($student->fresh()->isPremium());

        Livewire::test(ListUsers::class)->callAction(TestAction::make('revokePremium')->table($student));
        $this->assertFalse($student->fresh()->isPremium());
    }

    public function test_only_admin_can_access_admin_panel(): void
    {
        $student = User::factory()->create();
        $student->assignRole(Role::findOrCreate(User::ROLE_STUDENT, 'web'));
        $teacher = User::factory()->create();
        $teacher->assignRole(Role::findOrCreate(User::ROLE_TEACHER, 'web'));
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate(User::ROLE_ADMIN, 'web'));

        $this->actingAs($student)->get('/admin')->assertForbidden();
        $this->actingAs($teacher)->get('/admin')->assertForbidden();
        $this->actingAs($admin)->get('/admin')->assertOk();

        // Список користувачів з колонками ролей і фільтром заявок на викладача
        $teacherRequest = User::factory()->create(['name' => 'Заявник Викладач', 'requested_role' => 'teacher']);
        $teacherRequest->assignRole(User::ROLE_STUDENT);
        $this->actingAs($admin)->get('/admin/users')->assertOk()
            ->assertSee('Заявник Викладач')
            ->assertSee('Обрав при реєстрації')
            ->assertSee('очікує підтвердження');

        // Кнопку «Адмін панель» бачить лише адміністратор
        $this->actingAs($teacher)->get('/')->assertDontSee('Адмін панель');
        $this->actingAs($admin)->get('/')->assertSee('Адмін панель');
    }
}
