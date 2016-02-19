<?php namespace WTF;

use Closure;
use Exception;

class Router
{
    private static $self = null;

    private $routes_tree = [];
    private $names_map = [];
    
    private $host = null;
    private $context = null;
    private $pipe_stack = [];
    private $prefix_stack = [];
    private $namespace_stack = [];
    
    private $current_route = null;
    
    public static function __callStatic($method, $args)
    {
        if (!self::$self) {
            self::$self = new self();
        }
        
        self::$self->$method(...$args);
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
    
    public function pipeline($pipe, Closure $callback)
    {
        $origin_pipe_stack = $this->pipe_stack;
        if (is_array($pipe)) {
            $this->pipe_stack = array_merge($this->pipe_stack, $pipe);
        } else {
            $this->pipe_stack[] = $pipe;
        }
        $callback();
        $this->pipe_stack = $pipe_stack;
    }
    
    public function prefix($prefix, Closure $callback)
    {
        $this->prefix_stack[] = $prefix;
        $callback();
        array_pop($this->prefix_stack);
    }
    
    public function namespace($namespace, Closure $callback)
    {
        $this->namespace_stack[] = $namespace;
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
        if (is_null($this->context)) {
            $this->context = new Context();
        }
    
        //uri拼装
        $prefix = implode('', $this->prefix_stack);
        $uri = "{$prefix}{$uri}";
        
        //target拼装
        if (is_string($target)) {
            $namespace = implode('\\', $this->namespace_stack);
            $target = "$namespace\\$target";
        }
        
        //将pipes和target堆叠成可调用的闭包，即为当前路由对应的action
        // *借鉴于 Illuminate\Pipeline\Pipeline
        $action = array_reduce(
            array_reverse($this->pipe_stack),
            function ($next, $pipe) {
                return function ($request, $response) use ($next, $pipe) {
                    //调用middle方法名为handle
                    if (is_string($pipe) && false !== strpos('@', $pipe)) {
                        $pipe .= '@handle';
                    }
                    
                    $this->context->call($pipe, [
                        'request' => $request,
                        'response' => $response,
                        'next' => $next,
                        'context' => $this->context
                    ]);
                };
            },
            function ($request, $response) use ($target) {
                return $this->context->call($target, [
                    'request' => $request,
                    'response' => $response,
                    'context' => $this->context
                ]);
            }
        );
        
        //routes tree构造
        $method_map = &$this->routes_tree;
        foreach ($methods as $method) {
            $method = strtoupper($method);
            $type_map = &$method_map[$method];
            
            // if static
            if (!preg_match('/{[a-zA-Z_][a-zA-Z0-9_]*\??:.*?}/', $uri)) {
                $route = [
                    'uri' => $uri,
                    'action' => $target
                ];
            
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
                    'params' => $params,
                    'template' => $template,
                    'action' => $action
                ];
                
                $regex_uri_map = &$type_map['regex'];
                if (isset($regex_uri_map[$regex])) {
                    throw new Exception("Route Redefine: An old route defined as '$regex' has already existed.");
                }
                $regex_uri_map[$regex] = $route;
            }
        }
        
        $this->current = $route;
        
        return $this;
    }
    
    public function name($name)
    {
        if (isset($this->names_map[$name])) {
            throw new Exception("Name Conflict: You try to name two different route as '$name'.");
        }
        $this->names_map[$name] = $this->current;
    }
    
    public function uri($name, $args = [])
    {
        if (!isset($this->names_map[$name])) {
            throw new Exception("Name Not Exists: The route '$name' that you called is not exists.");
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
        
        return $uri;
    }
    
    public function match($method, $uri)
    {
    	//数据完整性
        if (empty($method) && empty($uri)) {
            throw new Exception("Illegal Match: Router can't accept the fragmentary request.");
        }
        
        $method_map = $this->routes_tree;
        
        if (!isset($method_map[$method])) {
        	return false;
        }
        
        $type_map = $method_map[$method];
        
        $static_uri_map = $type_map['static'];
        if (isset($static_uri_map[$uri])) {
            $this->current = $static_uri_map[$uri];
            return true;
        }
        
        $regex_uri_map = $type_map['regex'];
        foreach ($regex_uri_map as $regex => $route) {
            if (preg_match("/$regex/", $uri)) {
                $this->current = $route;
                return true;
            }
        }
        
        return false;
    }
    
    public function dispatch($request, $response)
    {
        if (is_null($this->current)) {
        	throw new Exception("Dispatch Fail: Can't dispatch a request before matching a route.");
        }
        
        $action = $this->current['action'];
        return $action($request, $response);
    }
}
