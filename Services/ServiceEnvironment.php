<?php namespace ChieveiT\WTF\Services;

class ServiceEnvironment
{
    private static $map = [];
    private static $current = null;
    
    public static function setup($config)
    {
        
    }
    
    public static function current($name = null)
    {
        if ($name) {
            if ($this->map[$name]) {
                $this->current = $this->map[$name];
            } else {
                throw new Exception("ServiceEnviroment Not Exists: The '$name' ServiceEnvironment is not defined.");
            }
        }
        
        return $this->current;
    }
}
