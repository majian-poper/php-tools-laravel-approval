<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as BaseUser;
use PHPTools\Approval\Contracts\Approver;

/**
 * @property string $name
 * @property string $email
 * @property string $password
 */
class User extends BaseUser implements Approver
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
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

    public static function newModel(): User
    {
        return User::factory()->create();
    }

    public static function newModels(int $count): array
    {
        return User::factory()->count($count)->create()->all();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'string',
        ];
    }

    public function getApproverTitleAttribute(): string
    {
        return $this->name;
    }

    public function contains(Authenticatable $user): bool
    {
        return $this->is($user);
    }

    public function canRollBack(): bool
    {
        return $this->getKey() === 1;
    }
}
