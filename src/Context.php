<?php namespace ChieveiT\WTF;

use Closure;

class Context
{
    private $bindings = [];
    private $singletons = [];
    
    //用于避免循环依赖的跟踪数组
    private $fetching = [];
    
    public function __construct(array $providers = [])
    {
    	foreach ($providers as $provider) {
    		if (!class_exists($provider)) {
    			throw new Exception("Provider Not Exists: Check your autoloader for '{$provider}'.");
    		}

    		$provider::bind($this);
        }
        
        return $this;
    }
    
    public function bind($abstract, $concrete, $singleton = false)
    {
        if (!(is_string($concrete) || $concrete instanceof Closure)) {
            throw new Exception("Binding Error: The binding called '$abstract' should be string or Closure.");
        }
    
        if (isset($this->bindings[$abstract])) {
            throw new Exception("Binding Redefine: The binding called '$abstract' has already defined.");
        }

        $this->bindings[$abstract] = $concrete;
        if (true == $singleton) {
            $this->singletons[$abstract] = true;
        }
        
        return $this;
    }
    
    public function invoke($abstract)
    {
        //单例获取
        if (isset($this->singletons[$abstract]) && $this->singletons[$abstract] !== true) {
            return $this->singletons[$abstract];
        }
        
        //检测是否存在循环依赖
        if (in_array($abstract, $this->fetching)) {
            $trace = implode(' -> ', $this->fetching);
            throw new Exception("Circular Dependencies: '$trace'.");
        }
        
        $this->fetching[] = $abstract;
        
        $concrete = $this->bindings[$abstract] ? : $abstract;
        
        //字符串（别名或类名）
        if (is_string($concrete)) {
            //更深的解析层次
            if (isset($this->bindings[$concrete])) {
                array_pop($this->fetching);
                $object = $this->invoke($concrete);
            }
            //对于存在的类，构造对象并进行构造方法的依赖注入
            else if (class_exists($concrete, true)) {
                $reflector = new ReflectionClass($concrete);
                $constructor = $reflector->getConstructor();
                
                //不存在构造函数
                if (is_null($constructor)) {
                    $object = new $concrete;
                }
                //存在构造函数
                else {
                    $dependencies = [];
                    foreach ($constructor->getParameters() as $parameter) {
                        $class = $parameter->getClass();
                        if (is_null($class)) {
                            throw new Exception("Invoke Fail: Constructor of '$concrete' has a parameter without type hinting.");
                        }
                        $dependencies[] = $this->invoke($class);
                    }
                    
                    $object = new $concrete(...$dependencies);
                }
            }
            else {
                throw new Exception("Class Not Exists: Class '$concrete' is not required or can't be autoloaded.");
            }
        }
        //闭包
        else {
            $reflector = new ReflectionFunction($concrete);
            $dependencies = [];
            foreach ($reflector->getParameters() as $parameter) {
                $class = $parameter->getClass();
                if (is_null($class)) {
                    throw new Exception("Invoke Fail: Closure '$abstract' has a parameter without type hinting.");
                }
                $dependencies[] = $this->invoke($class);
            }
            
            $object = $concrete(...$dependencies);
        }
        
        array_pop($this->fetching);
        
        //单例存放
        if (isset($this->singletons[$abstract]) && $this->singletons[$abstract] === true) {
            $this->singletons[$abstract] = $object;
        }
        
        return $object;
    }
    
    public function call($callable, array $args = [])
    {
        // Class@method syntax
        if (is_string($callable) && strpos($callable, '@') !== false) {
            $segments = explode('@', $callable, 2);
            $callable = [
                $this->invoke($segments[0]),
                $segments[1]
            ];
        }
        
        if (is_callable($callable)) {         
            //类名::方法名
            if (is_string($callable) && strpos($callable, '::') !== false) {
                $reflector = new ReflectionMethod($callable);
            }
            //[类名或对象, 方法名]
            else if (is_array($callable)) {
                $reflector = new ReflectionMethod($callable[0], $callbale[1]);
            }
            //闭包或函数
            else {
                $reflector = new ReflectionFunction($callable);
            }
            
            $call_args = [];
            foreach ($reflector->getParameters() as $parameter) {
                //调用者传入的参数
                if (isset($args[$parameter->name])) {
                    $call_args[] = $args[$parameter->name];
                }
                //有默认值的可选参数
                else if ($parameter->isOptional()) {
                    $call_args[] = $parameter->getDefaultValue();
                }
                //由注入器注入的依赖
                else if ($class = $parameter->getClass()) {
                    $call_args[] = $this->invoke($class);
                } else {
                    throw new Exception("Call Fail: The function/method/closure has a parameter which can be neither passed nor injected.");
                }
            }
            
            //类名::方法名
            if (is_string($callable) && strpos($callable, '::') !== false) {
                return $callable(...$call_args);
            }
            //[类名或对象, 方法名]
            else if (is_array($callable)) {
                return $callable[0]->$callable[1](...$call_args);
            }
            //闭包或函数
            else {
                return $callable(...$call_args);
            }
        } else {
            throw new Exception("Call Fail: Make sure you have passed a function/method/closure to the call injecting.");
        }
    }
}
