<?php

namespace Gadya\Connect\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements FilamentUser
{
    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password', 'role'];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
