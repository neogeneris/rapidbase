<?php
/**
 * User Model
 * Extends ActiveRecord for ORM functionality
 */

namespace Example;

use RapidBase\ORM\ActiveRecord\Model;

class User extends Model
{
    protected static string $table = 'users';
    protected static string $primaryKey = 'id';
    
    // Optional: Define fillable fields for mass assignment
    protected array $fillable = ['name', 'email', 'role', 'created_at'];
    
    // Optional: Define hidden fields (not included in JSON/array output)
    protected array $hidden = [];
    
    /**
     * Custom validation before save
     */
    public function beforeSave(): bool
    {
        if (empty($this->name)) {
            throw new \Exception('Name is required');
        }
        
        if (empty($this->email)) {
            throw new \Exception('Email is required');
        }
        
        // Auto-set created_at if not exists
        if (empty($this->created_at)) {
            $this->created_at = date('Y-m-d H:i:s');
        }
        
        return true;
    }
}
