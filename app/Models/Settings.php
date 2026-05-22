<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


/**
 * App\Models\Settings
 *
 * @property int $id
 * @property string $key
 * @property array $value
 * @property int $settingable_id
 * @property string $settingable_type
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Settings extends Model
{
    /** @use HasFactory<\Database\Factories\SettingsFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'value',
        'settingable_id',
        'settingable_type',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'value' => 'array', // Automatically cast JSON to array
    ];

    /**
     * Get the owning settingable model.
     */
    public function settingable()
    {
        return $this->morphTo();
    }
}
