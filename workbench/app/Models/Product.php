<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use PHPTools\Approval\Contracts\Approvable;
use PHPTools\Approval\Facades\ApprovalFacade;
use PHPTools\Approval\ShouldBeApproved;

class Product extends Model implements Approvable
{
    use HasFactory;
    use ShouldBeApproved;
    use SoftDeletes;

    protected $fillable = [
        'name',
    ];

    public static function newModel(): Product
    {
        return ApprovalFacade::forceRun(fn(): Product => Product::factory()->create());
    }

    public static function newModels(int $count): array
    {
        return ApprovalFacade::forceRun(fn(): array => Product::factory()->count($count)->create()->all());
    }

    public function getUniqueKeyName(): string
    {
        return 'name';
    }

    public function getUniqueKey(): string
    {
        return $this->name ?? '';
    }

    public function getForeignModelKeys(): array
    {
        return [];
    }

    public function getCustomCreatingAttributes(): array
    {
        return [
            ['custom_old' => 'before'],
            ['custom_new' => 'after'],
        ];
    }
}
