<?php namespace ChieveiT\WTF;

use Closure;
use Exception;

class Router
{
    private static $routes_tree = [];
    private static $names_map = [];
    
    private static $host = null;
    private static $service_environment = null;
    private static $middleware = [];
    private static $prefix_stack = [];
    private static $namespace_stack = [];
    
    private static $current_route = null;
    
    public static function host($host, Closure $callback, $not_found)
    {
        $this->host = $host;
        $callback();
        $this->host = null;
    }
    
    public static function group(array $statements, Closure $callback)
    {
        if ($statements['service_environment']) {
            $this->service_environment = $statements['service_environment'];
        }
        if ($statements['middleware']) {
            $origin_middleware = $this->middleware;
            $this->middleware = array_merge($this->middleware, $statements['middleware']);
        }
        if ($statements['prefix']) {
            $this->prefix_stack[] = $statements['prefix'];
        }
        if ($statements['namespace']) {
            $this->namespace_stack[] = $statements['namespace'];
        }
        
        $callback();
        
        if ($statements['service_environment']) {
            $this->service_environment = null;
        }
        if ($statements['middleware']) {
            $this->middleware = $origin_middleware;
        }
        if ($statements['prefix']) {
            array_pop($this->prefix_stack);
        }
        if ($statements['namespace']) {
            array_pop($this->namespace_stack);
        }
    }

    public static function get($uri, $target)
    {
        $this->access(['GET'], $uri, $target);
    }
    
    public static function post($uri, $target)
    {
        $this->access(['POST'], $uri, $target);
    }
    
    public static function put($uri, $target)
    {
        $this->access(['PUT'], $uri, $target);
    }
    
    public static function delete($uri, $target)
    {
        $this->access(['DELETE'], $uri, $target);
    }
    
    public static function any($uri, $target)
    {
        $this->access(['GET', 'POST', 'PUT', 'DELETE'], $uri, $target);
    }
    
    public static function access($methods, $uri, $target)
    {
        //uri拼装
        $prefix = implode('', $this->prefix_stack);
        $uri = "{$prefix}{$uri}";
        
        //target拼装
        //注：target两种形式，字符串（'{完全类名}@{方法}'）和闭包，只有前者支持namespace
        if (is_string($target)) {
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
                
                    'service_environment' => $this->service_environment,
                    'middleware' => $this->middleware,
                    'target' => $target
                ];
            
                //第四之一层：静态URI映射
                $type_map['static'] = $type_map['static'] ? : [];
                $static_uri_map = &$type_map['static'];
                if (isset($static_uri_map[$uri])) {
                    throw new Exception("Route Redefine: An old route defined as '$uri' has already existed.");
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
                
                    'service_environment' => $this->service_environment,
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
    
    public static function name($name)
    {
        if ($this->names_map[$name]) {
            throw new Exception("Name Conflict: You try to name two different route as '$name'.");
        }
        $this->names_map[$name] = $this->current_route;
    }
    
    public static function url($name, array $args, $protocol = 'http')
    {
        if (!$this->names_map[$name]) {
            throw new Exception("Name Not Exists: The route '$name' that you called is not exists.");
        }
        
        // host
        if ($this->names_map[$name]['host']) {
            $host = $this->names_map[$name]['host'];
        } else if ($this->current_request) {
            $host = $this->current_request->host;
        } else {
            throw new Exception("URL Without Host: You try to get a url without host which is not recommended.");
        }
        
        // uri
        if ($this->names_map[$name]['uri']) {
            $uri = $this->names_map[$name]['uri'];
        } else if ($this->names_map[$name]['params'] && $this->names_map[$name]['template']) {
            $params = $this->names_map[$name]['params'];
            $uri = $this->names_map[$name]['template'];
            
            foreach ($params as $key => $is_optional) {
                if ($args[$key]) {
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
    
    public static function match($host, $method, $uri)
    {
        //数据完整性和合法性
        if (!($host && in_array($method, ['GET', 'POST', 'PUT', 'DELETE']) && $uri)) {
            return false;
        }
        
        $host_map = $this->routes_tree;
        $method_map = $host_map[$host] ? : $host_map['*'];
        $type_map = $method_map[$method];
        
        $static_uri_map = $type_map['static'];
        if ($static_uri_map[$uri]) {
            $this->current_route = $static_uri_map[$uri];
            return true;
        }
        
        $regex_uri_map = $type_map['regex'];
        foreach ($regex_uri_map as $regex => $route) {
            if (preg_match("/$regex/", $uri)) {
                $this->current_route = $route;
                return true;
            }
        }
        
        return false;
    }
    
    public static function dispatch()
    {
        if (!$this->current_route) {
            throw new Exception("Dispatch Fail: You must match a route before dispatching it.");
        }
        
        
    }
}
