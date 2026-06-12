<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use PHPTools\Approval\Contracts\Approvable;
use PHPTools\Approval\Facades\ApprovalFacade;
use PHPTools\Approval\ShouldBeApproved;

class Article extends Model implements Approvable
{
    use HasFactory;
    use ShouldBeApproved;

    protected $fillable = [
        'title',
        'body',
        'author_email',
    ];

    public static function newModel(): Article
    {
        return ApprovalFacade::forceRun(fn(): Article => Article::factory()->create());
    }

    public static function newModels(int $count): array
    {
        return ApprovalFacade::forceRun(fn(): array => Article::factory()->count($count)->create()->all());
    }

    public function getLabel(): string
    {
        return 'article';
    }

    public function getUniqueKeyName(): string
    {
        return 'author_email';
    }

    public function getUniqueKey(): string
    {
        return $this->author_email ?? '';
    }

    public function getForeignModelKeys(): array
    {
        return [];
    }
}
