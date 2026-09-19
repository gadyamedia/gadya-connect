<?php

namespace Gadya\Connect\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * This site's link to the portal: which site it is there, and the secret
 * both sides sign with. There is only ever one; pairing again replaces it.
 *
 * @property int $site_id
 * @property string $portal_url
 * @property string $secret
 * @property ?string $site_name
 * @property ?string $client_name
 * @property bool $sso_enabled
 * @property ?Carbon $paired_at
 * @property ?Carbon $last_report_at
 * @property ?string $last_error
 */
class Connection extends Model
{
    protected $table = 'gadya_connect_connections';

    protected $guarded = [];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'sso_enabled' => 'boolean',
            'paired_at' => 'datetime',
            'last_report_at' => 'datetime',
        ];
    }

    public static function current(): ?self
    {
        return rescue(fn (): ?self => static::query()->latest('id')->first(), null, report: false);
    }
}
