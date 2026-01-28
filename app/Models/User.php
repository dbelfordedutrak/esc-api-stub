<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    protected $table      = 'ww_usr_user';

    protected $primaryKey = 'fldUserId';

    protected $username = 'fldUsername';

    protected $user_id = 'fldUserId';

    public $created = 'fldCreatedDate';

    public $is_active = 'fldActive';

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'fldFirstName',
        'fldLastName',
        'fldUsername',
        'fldEmail',
        'fldPassword',
        'fldPin',
        'fldLCS1000User',
        'fldLineUser',
        'fldLineAccess',
        'fldLineAccessAll',
        'fldLineCloser',
        'fldLineLastAccessed',
        'fldCloudId',
        'fldCreatedBy',
        'fldCreatedDate',
        'fldActive',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'fldPassword',
        'fldPin',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'fldLCS1000User' => 'boolean',
        'fldLineUser' => 'boolean',
        'fldLineAccessAll' => 'boolean',
        'fldLineCloser' => 'boolean',
        'fldActive' => 'boolean',
        'fldCreatedDate' => 'datetime',
        'fldLineAccess' => 'array',
    ];

    /**
     * Get the password for the user.
     *
     * @return string
     */
    public function getAuthPassword()
    {
        return $this->fldPassword;
    }

    public function getEmail()
    {
        return $this->fldEmail;
    }

    /**
     * Get the name attribute
     */
    public function getNameAttribute()
    {
        return trim($this->fldFirstName . ' ' . $this->fldLastName);
    }

    /**
     * Get the email attribute for authentication
     */
    public function getEmailForPasswordReset()
    {
        return $this->fldEmail;
    }

    /**
     * Business logic
     */
    public function hasAccessToLine(string $lineId)
    {
        return in_array($lineId, $this->fldLineAccess);
    }

    /**
     * Get lines the user has access to (excluding childcare lines)
     */
    public function lines()
    {
        // Base query - exclude childcare lines
        $query = Line::query()
            ->join('ww_mealtype', 'ww_line.fldMealType', '=', 'ww_mealtype.fldMealType')
            ->where('ww_mealtype.fldIsChildcare', 0)
            ->select('ww_line.*', 'ww_mealtype.fldIsFRProgram', 'ww_mealtype.fldMealDescription');

        // If user has access to all lines, return all non-childcare lines
        if ($this->fldLineAccessAll) {
            return $query->get();
        }

        $lines = $this->fldLineAccess;

        // Return empty collection if no line access
        if (empty($lines) || !is_array($lines)) {
            return collect([]);
        }

        // Build WHERE conditions for each line code
        $query->where(function($q) use ($lines) {
            foreach ($lines as $line) {
                $mealType = substr($line, 0, 1);
                $lineNum = substr($line, 1);

                $q->orWhere(function($subQ) use ($mealType, $lineNum) {
                    $subQ->where('ww_line.fldMealType', $mealType)
                         ->where('ww_line.fldLineNum', $lineNum);
                });
            }
        });

        return $query->get();
    }

    /**
     * Validate password using MD5
     */
    public function validatePassword(string $password): bool
    {
        return $this->fldPassword === md5($password);
    }
}
