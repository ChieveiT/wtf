<?php namespace ChieveiT\WTF\Protocol\Http;

use Closure;
use Exception;

class Router
{
    private static $instance = null;

    private $routes_tree = [];
    private $names_map = [];
    
    private $host = null;
    private $context = null;
    private $middleware = [];
    private $prefix_stack = [];
    private $namespace_stack = [];
    
    private $current_route = null;
    private $current_host = null;
    
    public static function __callStatic($method, $args)
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        
        $instance = self::$instance;
        
        switch (count($args)) {
            case 0:
                return $instance->$method();

            case 1:
                return $instance->$method($args[0]);

            case 2:
                return $instance->$method($args[0], $args[1]);

            case 3:
                return $instance->$method($args[0], $args[1], $args[2]);

            case 4:
                return $instance->$method($args[0], $args[1], $args[2], $args[3]);

            default:
                return call_user_func_array([$instance, $method], $args);
        }
    }
    
    public function host($host, Closure $callback)
    {
        $this->host = $host;
        $callback();
        $this->host = null;
    }
    
    public function group(array $statements, Closure $callback)
    {
        if (isset($statements['context'])) {
        	if (is_null($this->context)) {
        		$this->context = $statements['context'];
        	} else {
        		throw new Exception("Duplicate contexts: You should not apply duplicate contexts on any route.");
            }
        }
        if (isset($statements['middleware'])) {
            $origin_middleware = $this->middleware;
            $this->middleware = array_merge($this->middleware, $statements['middleware']);
        }
        if (isset($statements['prefix'])) {
            $this->prefix_stack[] = $statements['prefix'];
        }
        if (isset($statements['namespace'])) {
            $this->namespace_stack[] = $statements['namespace'];
        }
        
        $callback();
        
        if (isset($statements['context'])) {
            $this->context = null;
        }
        if (isset($statements['middleware'])) {
            $this->middleware = $origin_middleware;
        }
        if (isset($statements['prefix'])) {
            array_pop($this->prefix_stack);
        }
        if (isset($statements['namespace'])) {
            array_pop($this->namespace_stack);
        }
    }

    public function get($uri, $target)
    {
        $this->access(['GET'], $uri, $target);
    }
    
    public function post($uri, $target)
    {
        $this->access(['POST'], $uri, $target);
    }
    
    public function put($uri, $target)
    {
        $this->access(['PUT'], $uri, $target);
    }
    
    public function delete($uri, $target)
    {
        $this->access(['DELETE'], $uri, $target);
    }
    
    public function resource($uri, $target)
    {
        $this->access(['GET', 'POST', 'PUT', 'DELETE'], $uri, $target);
    }
    
    public function access($methods, $uri, $target)
    {
        //uri拼装
        $prefix = implode('', $this->prefix_stack);
        $uri = "{$prefix}{$uri}";
        
        //target拼装
        if (is_string($target)) {
            //target两种形式，字符串（'{完全类名}@{方法}'）和闭包，只有前者支持namespace
            $namespace = implode('\\', $this->namespace_stack);
            $target = "$namespace\\$target";
        }
    
        //第一层：host映射
        $host_map = &$this->routes_tree;
        if (is_null($this->host)) {
            $this->host = '*';
        }
        $host_map[$this->host] = $host_map[$this->host] ? : [];
        
        //第二层：method映射
        $method_map = &$host_map[$this->host];
        foreach ($methods as $method) {
            $method = strtoupper($method);
            $method_map[$method] = $method_map[$method] ? : [];
            
            //第三层：URI类型（static/regex）映射
            $type_map = &$method_map[$method];
            
            // if static
            if (!preg_match('/{[a-zA-Z_][a-zA-Z0-9_]*\??:.*?}/', $uri)) {
                $route = [
                    'host' => $this->host,
                    'uri' => $uri,
                
                    'context' => $this->context,
                    'middleware' => $this->middleware,
                    'target' => $target
                ];
            
                //第四之一层：静态URI映射
                $type_map['static'] = $type_map['static'] ? : [];
                $static_uri_map = &$type_map['static'];
                if (isset($static_uri_map[$uri])) {
                    throw new Exception("Route Redefine: The route '$uri' has already defined.");
                }
                $static_uri_map[$uri] = $route;
            }
            // if regex
            else {
                $regex = preg_replace_callback('/{[a-zA-Z_][a-zA-Z0-9_]*\??:(.*?)}/',
                    function($matches) {
                        $regex = $matches[1];
                        
                        if (substr($name, -1) == '?') {
                            $regex = "($regex)?";
                        } else {
                            $regex = "($regex)";
                        }
                        
                        return $regex;
                    }, $uri);
                
                $params = [];
                $template = preg_replace_callback('/{([a-zA-Z_][a-zA-Z0-9_]*\??):.*?}/',
                    function($matches) use (&$params) {
                        $name = $matches[1];
                        
                        if (substr($name, -1) == '?') {
                            $name = substr($name, -1);
                            $params[$name] = true;
                        } else {
                            $params[$name] = false;
                        }
                        $name = "\{{$name}\}";
                        
                        return $name;
                    }, $uri);
                
                $route = [
                    'host' => $this->host,
                    'params' => $params,
                    'template' => $template,
                
                    'context' => $this->context,
                    'middleware' => $this->middleware,
                    'target' => $target
                ];
                
                //第四之二层：正则URI映射
                $type_map['regex'] = $type_map['regex'] ? : [];
                $regex_uri_map = &$type_map['regex'];
                if (isset($regex_uri_map[$regex])) {
                    throw new Exception("Route Redefine: An old route defined as '$regex' has already existed.");
                }
                $regex_uri_map[$regex] = $route;
            }
        }
        
        $this->current_route = $route;
        
        return $this;
    }
    
    public function name($name)
    {
        if (isset($this->names_map[$name])) {
            throw new Exception("Name Conflict: You try to name two different route as '$name'.");
        }
        $this->names_map[$name] = $this->current_route;
    }
    
    public function url($name, array $args, $protocol = 'http')
    {
        if (!isset($this->names_map[$name])) {
            throw new Exception("Name Not Exists: The route '$name' that you called is not exists.");
        }
        
        // host
        if ($this->names_map[$name]['host'] != '*') {
            $host = $this->names_map[$name]['host'];
        } else if ($this->current_request) {
            $host = $this->current_host;
        } else {
            throw new Exception("URL Without Host: You try to get a url without host which is not recommended.");
        }
        
        // uri
        if (isset($this->names_map[$name]['uri'])) {
            $uri = $this->names_map[$name]['uri'];
        } else if (isset($this->names_map[$name]['params']) && isset($this->names_map[$name]['template'])) {
            $params = $this->names_map[$name]['params'];
            $uri = $this->names_map[$name]['template'];
            
            foreach ($params as $key => $is_optional) {
                if (isset($args[$key])) {
                    $uri = str_replace("\{{$key}\}", $args[$key], $uri);
                } else if ($is_optional) {
                    $uri = str_replace("\{{$key}\}", '', $uri);
                } else {
                    throw new Exception("Route Arg Required: Make sure you have supplied the required arg '$key' for '$name'.");
                }
            }
        }
        
        return "{$protocol}://{$host}{$uri}";
    }
    
    public function match($host, $method, $uri)
    {
    	//数据完整性
        if (empty($host) || empty($method) && empty($uri)) {
            throw new Exception("Illegal Match: Router can't accept the fragmentary request.");
        }
        
        $host_map = $this->routes_tree;
        $method_map = $host_map[$host] ? : $host_map['*'];
        
        if (!isset($method_map[$method])) {
        	return false;
        }
        
        $type_map = $method_map[$method];
        
        $static_uri_map = $type_map['static'];
        if (isset($static_uri_map[$uri])) {
            $this->current_route = $static_uri_map[$uri];
        	$this->current_host = $host;
            return true;
        }
        
        $regex_uri_map = $type_map['regex'];
        foreach ($regex_uri_map as $regex => $route) {
            if (preg_match("/$regex/", $uri)) {
                $this->current_route = $route;
                $this->current_host = $host;
                return true;
            }
        }
        
        return false;
    }
    
    public function dispatch()
    {
        if (is_null($this->current_route)) {
        	throw new Exception("Dispatch Fail: Can't dispatch a request before matching a route.");
        }
        
        
    }
}
