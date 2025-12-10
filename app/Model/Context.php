<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $description
 * @property array $content
 * @property bool $is_default
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Context extends Model
{
    protected ?string $table = 'contexts';

    protected array $fillable = ['name', 'description', 'content', 'is_default'];

    protected array $casts = [
        'id' => 'integer',
        'content' => 'array',
        'is_default' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

