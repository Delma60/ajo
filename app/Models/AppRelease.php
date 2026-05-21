<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppRelease extends Model
{
    //
    protected $fillable = [
        'platform','version','build','file_path','file_size',
        'sha256','is_published','is_supported','is_forced_update',
        'release_notes','uploaded_by',
        'file_name', 'file_mime', 'download_count'
    ];
}
