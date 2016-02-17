<?php namespace ChieveiT\WTF;

use Closure;
use Exception;

class Router
{
    private static $self = null;

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
        if (!self::$self) {
            self::$self = new self();
        }
        
        $self = self::$self;
        
        // http://php.net/manual/en/function.call-user-func-array.php#100794
        switch (count($args)) {
            case 0:
                return $self->$method();

            case 1:
                return $self->$method($args[0]);

            case 2:
                return $self->$method($args[0], $args[1]);

            case 3:
                return $self->$method($args[0], $args[1], $args[2]);

            case 4:
                return $self->$method($args[0], $args[1], $args[2], $args[3]);

            default:
                return $self->$method(...$args);
        }
    }
    
    public function host($host, Closure $callback)
    {
        $this->host = $host;
        $callback();
        $this->host = null;
    }
    
    public function context(Context $context, Closure $callback)
    {
        if (is_null($this->context)) {
            $this->context = $context;
        } else {
        	throw new Exception("Duplicate contexts: You should not apply duplicate contexts on any route.");
        }
        $callback();
        $this->context = null;
    }
    
    public function middleware($middleware, Closure $callback)
    {
        $origin_middleware = $this->middleware;
        if (is_array($middleware)) {
            $this->middleware = array_merge($this->middleware, $middleware);
        } else {
            $this->middleware[] = $middleware;
        }
        $callback();
        $this->middleware = $origin_middleware;
    }
    
    public function prefix($prefix, Closure $callback)
    {
        $this->prefix_stack[] = $statements['prefix'];
        $callback();
        array_pop($this->prefix_stack);
    }
    
    public function namespace($host, Closure $callback)
    {
        $this->namespace_stack[] = $statements['namespace'];
        $callback();
        array_pop($this->namespace_stack);
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
        //target支持两种类型，字符串类型（'{完全类名}@{方法}'） 和 闭包
        if (is_string($target)) {
            $namespace = implode('\\', $this->namespace_stack);
            $target = "$namespace\\$target";
        }
        
        //将middleware和target堆叠成可调用的闭包，即为当前路由对应的action
        //借鉴于 Illuminate\Pipeline\Pipeline
        $pipes = array_reverse($this->middleware);
        $action = array_reduce($pipes, function ($next, $pipe) {
            return function ($request) use ($next, $pipe) {
                //调用middle方法名为handle
                if (is_string($pipe) && false !== strpos('@', $pipe)) {
                    $pipe .= '@handle';
                }
                $this->context->call($pipe, array('request' => $request, 'next' => $next));
            };
        }, $target);
    
        //第一层：host映射
        $host_map = &$this->routes_tree;
        if (is_null($this->host)) {
            $this->host = '*';
        }
        
        //第二层：method映射
        $method_map = &$host_map[$this->host];
        foreach ($methods as $method) {
            //第三层：URI类型（static/regex）映射
            $method = strtoupper($method);
            $type_map = &$method_map[$method];
            
            // if static
            if (!preg_match('/{[a-zA-Z_][a-zA-Z0-9_]*\??:.*?}/', $uri)) {
                $route = [
                    'host' => $this->host,
                    'uri' => $uri,

                    'action' => $target
                ];
            
                //第四之一层：静态URI映射
                $static_uri_map = &$type_map['static'];
                if (isset($static_uri_map[$uri])) {
                    throw new Exception("Route Redefine: The route '$uri' has already defined.");
                }
                $static_uri_map[$uri] = $route;
            }
            // if regex
            else {
                //生成用于匹配请求的正则表达式
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
                
                //生成用于url拼装的模板字符串，形如 ...{参数1}...{参数2}...
                //同时提取出记录参数是否可选的参数列表
                $params = [];
                $template = preg_replace_callback('/{([a-zA-Z_][a-zA-Z0-9_]*\??):.*?}/',
                    function($matches) use (&$params) {
                        $name = $matches[1];
                        
                        //可选参数
                        if (substr($name, -1) == '?') {
                            $name = substr($name, -1);
                            $params[$name] = true;
                        }
                        //非可选参数
                        else {
                            $params[$name] = false;
                        }
                        $name = "\{{$name}\}";
                        
                        return $name;
                    }, $uri);
                
                $route = [
                    'host' => $this->host,
                    'params' => $params,
                    'template' => $template,
                
                    'action' => $action
                ];
                
                //第四之二层：正则URI映射
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
    
    public function url($name, $args = [], $protocol = 'http')
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
    
    public function dispatch($request)
    {
        if (is_null($this->current_route)) {
        	throw new Exception("Dispatch Fail: Can't dispatch a request before matching a route.");
        }
        
        $action = $this->current_route['action'];
        return $action($request);
    }
}
