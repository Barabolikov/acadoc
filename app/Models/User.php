<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /** Назви ролей (spatie/laravel-permission), як вони записані в базі */
    public const ROLE_STUDENT = 'Student';

    public const ROLE_TEACHER = 'Викладач';

    public const ROLE_ADMIN = 'super_admin';

    /** Скільки документів студент може перевірити за добу */
    public const STUDENT_DAILY_CHECKS = 1;

    /** Доба для ліміту рахується за київським часом (застосунок працює в UTC) */
    public const LIMIT_TIMEZONE = 'Europe/Kyiv';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'department_id', // Додали
        'group_id',      // Додали
        'requested_role', // Роль, обрана під час реєстрації: student | teacher
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function normControlChecks(): HasMany
    {
        return $this->hasMany(NormControlCheck::class);
    }

    /** Адмін-панель — лише для адміністратора; студенти й викладачі працюють на сайті. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasRole(self::ROLE_ADMIN);
    }

    /** Чи діє для користувача денний ліміт перевірок (студент без вищих ролей). */
    public function hasDailyCheckLimit(): bool
    {
        return $this->hasRole(self::ROLE_STUDENT) && ! $this->hasAnyRole([self::ROLE_ADMIN, self::ROLE_TEACHER]);
    }

    /** Скільки перевірок лишилося сьогодні; null — без обмежень. */
    public function remainingChecksToday(): ?int
    {
        if (! $this->hasDailyCheckLimit()) {
            return null;
        }

        $used = $this->normControlChecks()->where('created_at', '>=', self::startOfLimitDay())->count();

        return max(0, self::STUDENT_DAILY_CHECKS - $used);
    }

    /** Початок поточної доби (00:00 за Києвом) у часовому поясі застосунку. */
    public static function startOfLimitDay(): Carbon
    {
        return now(self::LIMIT_TIMEZONE)->startOfDay()->setTimezone(config('app.timezone'));
    }
}
