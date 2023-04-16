<?php
namespace Rails\Routing\UrlHelpers;

use Rails;
use Rails\Routing\Traits\NamedPathAwareTrait;
use Rails\Routing\Exception;

class UrlHelpers
{
    use NamedPathAwareTrait;

    public function __call($method, $params)
    {
        if ($this->isNamedPathMethod($method)) {
            return $this->getNamedPath($method, $params);
        }

        throw new Exception\BadMethodCallException(
            sprintf("Called to unknown method: %s", $method)
        );
    }

    public function find_route_with_alias($alias, array $params = [])
    {
        if ($alias == 'root') {
            return $this->router()->rootPath();
        } elseif ($alias == "base") {
            return $this->router()->basePath();
        } elseif ($alias == "asset") {
            return \Rails::assets()->prefix() . '/';
        }

        if ($this->useCache()) {
            $key = 'Rails.routes.aliases.' . $alias;
            $index = Rails::cache()->read($key);

            if (null === $index) {
                $index = $this->findAliasedRoute($alias, $params);
                Rails::cache()->write($key, $index);
            }

            if ($index) {
                $route = $this->router()->routes()->routes()->offsetGet($index);

                if ($url = $this->buildUrl($route, $params)) {
                    return $url;
                }
            }
        } else {
            if ($index = $this->findAliasedRoute($alias, $params)) {
                $route = $this->router()->routes()->routes()->offsetGet($index);
                if ($url = $this->buildUrl($route, $params)) {
                    return $url;
                }
            }
        }

        # Build exception
        if ($params) {
            if (is_object($params[0])) {
                $inlineParams = '(object of class ' . get_class($params[0]) . ')';
            } else {
                $inlineParams = '( ';
                foreach ($params as $k => $v) {
                    $inlineParams .= $k . '=>' . $v;
                }
                $inlineParams .= ' )';
            }
        } else {
            $inlineParams = "(no parameters)";
        }

        throw new Exception\RuntimeException(
            sprintf("No route found with alias '%s' %s", $alias, $inlineParams)
        );
    }

    protected function findAliasedRoute($alias, array $params = [])
    {
        foreach ($this->router()->routes() as $k => $route) {
            if ($route->alias() == $alias) {
                if (isset($params[0]) && $params[0] instanceof \Rails\ActiveRecord\Base) {
                    $params = $this->extract_route_vars_from_model($route, $params[0]);
                } else {
                    $params = $this->assocWithRouteParams($route, $params);
                }

                if ($route->build_url_path($params)) {
                    return $k;
                }
            }
        }
        return false;
    }

    private function buildUrl($route, $params)
    {
        if (isset($params[0]) && $params[0] instanceof \Rails\ActiveRecord\Base) {
            $params = $this->extract_route_vars_from_model($route, $params[0]);
        } else {
            $params = $this->assocWithRouteParams($route, $params);
        }

        $url = $route->build_url_path($params);

        if ($url) {
            if ($base_path = $this->router()->basePath()) {
                $url = $base_path . $url;
            }
            return $url;
        }

        return false;
    }

    /**
     * @return array, null | route and url
     */
    public function find_route_for_token($token, $params = [])
    {
        if ($params instanceof \Rails\ActiveRecord\Base) {
            $model = $params;
        } else {
            $model = false;
        }

        if ($this->useCache()) {
            $key = 'Rails.routes.tokens.' . $token;
            $index = Rails::cache()->fetch($key, function () use ($model, $token, $params) {
                foreach ($this->router()->routes() as $k => $route) {
                    if ($model) {
                        $params = $this->extract_route_vars_from_model($route, $model);
                    }
                    if ($route->match_with_token($token, $params)) {
                        return $k;
                    }
                }
                return false;
            });

            if ($index !== false) {
                $route = $this->router()->routes()->routes()->offsetGet($index);
                if ($model) {
                    $params = $this->extract_route_vars_from_model($route, $model);
                }
                $url = $route->match_with_token($token, $params);
                if ($base_path = $this->router()->basePath()) {
                    $url = $base_path . $url;
                }
                return [$route, $url];
            }
        } else {
            foreach ($this->router()->routes() as $route) {
                if ($model) {
                    $params = $this->extract_route_vars_from_model($route, $model);
                }
                $url = $route->match_with_token($token, $params);

                if ($url) {
                    if ($base_path = $this->router()->basePath())
                        $url = $base_path . $url;
                    return [$route, $url];
                }
            }
        }

        return false;
    }

    public function router()
    {
        return \Rails::application()->router();
    }

    private function extract_route_vars_from_model($route, $model)
    {
        $vars = [];
        $modelClassName = get_class($model);
        foreach (array_keys($route->vars()) as $name) {
            if ($modelClassName::isAttribute($name)) {
                $vars[$name] = $model->$name;
            }
        }
        return $vars;
    }

    private function assocWithRouteParams($route, $params)
    {
        $vars = [];
        $params = array_values($params);
        $i = 0;
        foreach (array_keys($route->vars()) as $name) {
            if (!isset($params[$i])) {
                break;
            } else {
                $vars[$name] = $params[$i];
                $i++;
            }
        }
        return $vars;
    }

    private function useCache()
    {
        return \Rails::env() == 'production';
    }
}