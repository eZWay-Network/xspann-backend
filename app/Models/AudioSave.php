<?php

namespace App\Models;

use Spark\Database\Model;

class AudioSave extends Model
{
    protected const UPDATED_AT = null;

    protected array $fillable = ['user_id', 'audio_id'];
}
