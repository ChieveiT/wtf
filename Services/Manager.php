<?php namespace ChieveiT\WTF\Services;

use Exception;

class Manager
{
    private static $contexts = [];
    
    public static function context($name, array $providers = [])
    {
    	if (!empty($providers)) {
    		$context = new Context;
    
    		foreach ($providers as $provider) {
    			if (!class_exists($provider)) {
    				throw new Exception("Provider Not Exists: Check you autoloader for {$provider}.");
    			}
    		
    			$provider::register($context);
    		}
    	
    		foreach ($providers as $provider) {
    			$provider::boot($context);
    		}
    	
    		$this->contexts[$name] = $context;
    	}
    
    	if (!isset($this->contexts[$name])) {
    		throw new Exception("Context Not Exists: Make sure you have defined a context named '{$name}' before.");
    	}
    	
    	return $this->contexts[$name];
    }
}
