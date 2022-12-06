<?php
declare(strict_types=1);

use Symfony\Component\VarExporter\VarExporter;
use think\facade\Event;
use think\facade\Route;
use think\Config as ConfigM;
use \think\facade\Config;
use think\helper\{
    Str, Arr
};


\think\Console::starting(function (\think\Console $console) {
    $console->addCommands([
        'addons:config' => '\\think\\addons\\command\\SendConfig'
    ]);
});

// 插件类库自动载入
spl_autoload_register(function ($class) {

    $class = ltrim($class, '\\');

    $dir = app()->getRootPath();
    $namespace = 'addons';

    if (strpos($class, $namespace) === 0) {
        $class = substr($class, strlen($namespace));
        $path = '';
        if (($pos = strripos($class, '\\')) !== false) {
            $path = str_replace('\\', '/', substr($class, 0, $pos)) . '/';
            $class = substr($class, $pos + 1);
        }
        $path .= str_replace('_', '/', $class) . '.php';
        $dir .= $namespace . $path;

        if (file_exists($dir)) {
            include $dir;
            return true;
        }

        return false;
    }

    return false;

});

if (!function_exists('hook')) {
    /**
     * 处理插件钩子
     * @param string $event 钩子名称
     * @param array|null $params 传入参数
     * @param bool $once 是否只返回一个结果
     * @return mixed
     */
    function hook($event, $params = null, bool $once = false)
    {
        $result = Event::trigger($event, $params, $once);
        return join('', $result);
    }
}

if (!function_exists('get_addons_info')) {
    /**
     * 读取插件的基础信息
     * @param string $name 插件名
     * @return array
     */
    function get_addons_info($name)
    {
        $addon = get_addons_instance($name);
        if (!$addon) {
            return [];
        }

        return $addon->getInfo();
    }
}

if (!function_exists('get_addons_instance')) {
    /**
     * 获取插件的单例
     * @param string $name 插件名
     * @return mixed|null
     */
    function get_addons_instance($name)
    {

        static $_addons = [];
        if (isset($_addons[$name])) {
            return $_addons[$name];
        }

        $class = get_addons_class($name);
        if (class_exists($class)) {
            $_addons[$name] = new $class(app());
            return $_addons[$name];
        } else {
            return null;
        }
    }
}

if (!function_exists('get_addons_class')) {
    /**
     * 获取插件类的类名
     * @param string $name 插件名
     * @param string $type 返回命名空间类型
     * @param string $class 当前类名
     * @return string
     */
    function get_addons_class($name, $type = 'hook', $class = null)
    {
        $name = trim($name);
        // 处理多级控制器情况
        if (!is_null($class) && strpos($class, '.')) {
            $class = explode('.', $class);

            $class[count($class) - 1] = Str::studly(end($class));
            $class = implode('\\', $class);
        } else {
            $class = Str::studly(is_null($class) ? $name : $class);
        }
        switch ($type) {
            case 'controller':
                $namespace = '\\addons\\' . $name . '\\controller\\' . $class;
                break;
            default:
                $namespace = '\\addons\\' . $name . '\\'. $class;
        }

        return class_exists($namespace) ? $namespace : '';
    }
}

if (!function_exists('addons_url')) {
    /**
     * 插件显示内容里生成访问插件的url
     * @param $url
     * @param array $param
     * @param bool|string $suffix 生成的URL后缀
     * @param bool|string $domain 域名
     * @return bool|string
     */
    function addons_url($url = '', $param = [], $suffix = true, $domain = false)
    {
        $request = app('request');
        if (empty($url)) {
            // 生成 url 模板变量
            $addons = $request->addon;
            $controller = $request->controller();
            $controller = str_replace('/', '.', $controller);
            $action = $request->action();
        } else {
            $url = Str::studly($url);
            $url = parse_url($url);
            if (isset($url['scheme'])) {
                $addons = strtolower($url['scheme']);
                $controller = $url['host'];
                $action = trim($url['path'], '/');
            } else {
                $route = explode('/', $url['path']);
                $addons = $request->addon;
                $action = array_pop($route);
                $controller = array_pop($route) ?: $request->controller();
            }
            $controller = Str::snake((string)$controller);

            /* 解析URL带的参数 */
            if (isset($url['query'])) {
                parse_str($url['query'], $query);
                $param = array_merge($query, $param);
            }
        }

        return Route::buildUrl("@addons/{$addons}/{$controller}/{$action}", $param)->suffix($suffix)->domain($domain);
    }

    /**********************************/
    if (!function_exists('set_addon_fullconfig')) {
        /**
         * 写入配置文件
         *
         * @param string $name  插件名
         * @param array  $array 配置数据
         * @return boolean
         * @throws Exception
         */
        function set_addon_fullconfig($name, $array)
        {
            $addon_path = app()->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
            $file = $addon_path . $name . DIRECTORY_SEPARATOR . 'config.php';
            $ret = file_put_contents($file, "<?php\n\n" . "return " . VarExporter::export($array) . ";\n", LOCK_EX);
            if (!$ret) {
                throw new Exception("配置写入失败");
            }
            return true;
        }
    }


    if (!function_exists('set_addon_config')) {
        /**
         * 写入配置文件
         * @param string  $name      插件名
         * @param array   $config    配置数据
         * @param boolean $writefile 是否写入配置文件
         * @return bool
         * @throws Exception
         */
        function set_addon_config($name, $config, $writefile = true)
        {
            $addon = get_addons_instance($name);
            $addon->setConfig($name, $config);
            $fullconfig = get_addon_fullconfig($name);
            foreach ($fullconfig as $k => &$v) {
                if (isset($config[$v['name']])) {
                    $value = $v['type'] !== 'array' && is_array($config[$v['name']]) ? implode(',', $config[$v['name']]) : $config[$v['name']];
                    $v['value'] = $value;
                }
            }
            if ($writefile) {
                // 写入配置文件
                set_addon_fullconfig($name, $fullconfig);
            }
            return true;
        }
    }



    if (!function_exists('set_addon_info')) {
        /**
         * 设置基础配置信息
         * @param string $name  插件名
         * @param array  $array 配置数据
         * @return boolean
         * @throws Exception
         */
        function set_addon_info($name, $array)
        {
            $addon_path = app()->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
            $file = $addon_path . $name . DIRECTORY_SEPARATOR . 'info.ini';
            $addon = get_addons_instance($name);
            $array = $addon->setInfo($name, $array);
            if (!isset($array['name']) || !isset($array['title']) || !isset($array['version'])) {
                throw new Exception("插件配置写入失败");
            }
            $res = array();
            foreach ($array as $key => $val) {
                if (is_array($val)) {
                    $res[] = "[$key]";
                    foreach ($val as $skey => $sval) {
                        $res[] = "$skey = " . (is_numeric($sval) ? $sval : $sval);
                    }
                } else {
                    $res[] = "$key = " . (is_numeric($val) ? $val : $val);
                }
            }
//            var_dump($res);exit;
            if (file_put_contents($file, implode("\n", $res) . "\n", LOCK_EX)) {
                //清空当前配置缓存
                Config::set([], "addon_{$name}_info");
            } else {
                throw new Exception("文件没有写入权限");
            }
            return true;
        }
    }


    if (!function_exists('addon_url')) {

        /**
         * 插件显示内容里生成访问插件的url
         * @param string      $url    地址 格式：插件名/控制器/方法
         * @param array       $vars   变量参数
         * @param bool|string $suffix 生成的URL后缀
         * @param bool|string $domain 域名
         * @return bool|string
         */
        function addon_url($url, $vars = [], $suffix = true, $domain = false)
        {

            $url = ltrim($url, '/');
            echo $url;exit;
            $addon = substr($url, 0, stripos($url, '/'));
            if (!is_array($vars)) {
                parse_str($vars, $params);
                $vars = $params;
            }
            $params = [];
            foreach ($vars as $k => $v) {
                if (substr($k, 0, 1) === ':') {
                    $params[$k] = $v;
                    unset($vars[$k]);
                }
            }
            $val = "@addons/{$url}";
            $config = get_addon_config($addon);
            $dispatch = think\Request::instance()->dispatch();
            $indomain = isset($dispatch['var']['indomain']) && $dispatch['var']['indomain'] && $dispatch['var']['addon'] == $addon ? true : false;
            //优先取插件配置中的domain，没有的情况下取全局的域名前缀配置
            $domainprefix = $config && isset($config['domain']) && $config['domain'] ? $config['domain'] : Config::get('addons.domain');
            $domain = $domainprefix && Config::get('url_domain_deploy') ? $domainprefix : $domain;
            $rewrite = $config && isset($config['rewrite']) && $config['rewrite'] ? $config['rewrite'] : [];
            if ($rewrite) {
                $path = substr($url, stripos($url, '/') + 1);
                if (isset($rewrite[$path]) && $rewrite[$path]) {
                    $val = $rewrite[$path];
                    array_walk($params, function ($value, $key) use (&$val) {
                        $val = str_replace("[{$key}]", $value, $val);
                    });
                    $val = str_replace(['^', '$'], '', $val);
                    if (substr($val, -1) === '/') {
                        $suffix = false;
                    }
                } else {
                    // 如果采用了域名部署,则需要去掉前两段
                    if ($indomain && $domainprefix) {
                        $arr = explode("/", $val);
                        $val = implode("/", array_slice($arr, 2));
                    }
                }
            } else {
                // 如果采用了域名部署,则需要去掉前两段
                if ($indomain && $domainprefix) {
                    $arr = explode("/", $val);
                    $val = implode("/", array_slice($arr, 2));
                }
                foreach ($params as $k => $v) {
                    $vars[substr($k, 1)] = $v;
                }
            }
            $url = url($val, [], $suffix, $domain) . ($vars ? '?' . http_build_query($vars) : '');
            $url = preg_replace("/\/((?!index)[\w]+)\.php\//i", "/", $url);
            return $url;
        }
    }


    if (!function_exists('get_addon_tables')) {
        /**
         * 获取插件创建的表
         * @param string $name 插件名
         * @return array
         */
        function get_addon_tables($name)
        {
            $addonInfo = get_addon_info($name);
            if (!$addonInfo) {
                return [];
            }
            $regex = "/^CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z_]+)`?/mi";
            $addon_path = app()->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
            $sqlFile = $addon_path . $name . DIRECTORY_SEPARATOR . 'install.sql';
            $tables = [];
            if (is_file($sqlFile)) {
                preg_match_all($regex, file_get_contents($sqlFile), $matches);
                if ($matches && isset($matches[2]) && $matches[2]) {
                    $prefix = config('database.connections.mysql.prefix');
                    $tables = array_map(function ($item) use ($prefix) {
                        return str_replace("__PREFIX__", $prefix, $item);
                    }, $matches[2]);
                }
            }
            return $tables;
        }
    }
    if (!function_exists('get_addon_info')) {
        /**
         * 读取插件的基础信息
         * @param string $name 插件名
         * @return array
         */
        function get_addon_info($name)
        {
            $addon = get_addons_instance($name);
            if (!$addon) {
                return [];
            }
            return $addon->getInfo($name);
        }
    }


    if (!function_exists('get_addon_config')) {
        /**
         * 获取插件类的配置值值
         * @param string $name 插件名
         * @return array
         */
        function get_addon_config($name)
        {
            $addon = get_addons_instance($name);
            if (!$addon) {
                return [];
            }
            return $addon->getConfig($name);
        }
    }



    if (!function_exists('get_addon_fullconfig')) {
        /**
         * 获取插件类的配置数组
         * @param string $name 插件名
         * @return array
         */
        function get_addon_fullconfig($name)
        {
            $addon = get_addons_instance($name);
            if (!$addon) {
                return [];
            }
            return $addon->getFullConfig($name);
        }
    }

    if (!function_exists('get_addon_autoload_config')) {
        /**
         * 获得插件自动加载的配置
         * @param bool $truncate 是否清除手动配置的钩子
         * @return array
         */
        function get_addon_autoload_config($truncate = false)
        {
            // 读取addons的配置
            $config = (array)\think\facade\Config::get('addons');//
            if ($truncate) {
                // 清空手动配置的钩子
                $config['hooks'] = [];
            }

            // 伪静态优先级
            $priority = isset($config['priority']) && $config['priority'] ? is_array($config['priority']) ? $config['priority'] : explode(',', $config['priority']) : [];

            $route = [];
            // 读取插件目录及钩子列表
            $base = get_class_methods("\\think\\Addons");
            $base = array_merge($base, ['install', 'uninstall', 'enable', 'disable']);

            $url_domain_deploy = \think\facade\Config::get('url_domain_deploy');

            $addons = get_addon_list();
            $domain = [];

            $priority = array_merge($priority, array_keys($addons));

            $orderedAddons = array();
            foreach ($priority as $key) {
                if (!isset($addons[$key])) {
                    continue;
                }
                $orderedAddons[$key] = $addons[$key];
            }

            foreach ($orderedAddons as $name => $addon) {

                if (!$addon['state']) {
                    continue;
                }

                // 读取出所有公共方法
                $methods = (array)get_class_methods("\\addons\\" . $name . "\\" . ucfirst($name));
//                $methods = (array)get_class_methods("\\addons\\" . $name );

                // 跟插件基类方法做比对，得到差异结果
                $hooks = array_diff($methods, $base);

                // 循环将钩子方法写入配置中
                foreach ($hooks as $hook) {
//                    $hook = Str::studly($hook);
                    $hook = trim($hook);
                    if (!isset($config['hooks'][$hook])) {
                        $config['hooks'][$hook] = [];
                    }
                    // 兼容手动配置项
                    if (is_string($config['hooks'][$hook])) {
                        $config['hooks'][$hook] = explode(',', $config['hooks'][$hook]);
                    }
                    if (!in_array($name, $config['hooks'][$hook])) {
                        $config['hooks'][$hook][] = $name;
                    }
                }
                $conf = get_addon_config($addon['name']);
                if ($conf) {
                    $conf['rewrite'] = isset($conf['rewrite']) && is_array($conf['rewrite']) ? $conf['rewrite'] : [];
                    $rule = array_map(function ($value) use ($addon) {
                        return "{$addon['name']}/{$value}";
                    }, array_flip($conf['rewrite']));
                    if ($url_domain_deploy && isset($conf['domain']) && $conf['domain']) {
                        $domain[] = [
                            'addon'  => $addon['name'],
                            'domain' => $conf['domain'],
                            'rule'   => $rule
                        ];
                    } else {
                        $route = array_merge($route, $rule);
                    }
                }
            }
            $config['route'] = $route;
            $config['route'] = array_merge($config['route'], $domain);
            return $config;
        }
    }


    if (!function_exists('get_addon_list')) {
        /**
         * 获得插件列表
         * @return array
         */
        function get_addon_list()
        {
            $addon_path = app()->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
            $results = scandir($addon_path);
            $list = [];
            foreach ($results as $name) {
                if ($name === '.' or $name === '..') {
                    continue;
                }
                if (is_file($addon_path . $name)) {
                    continue;
                }
                $addonDir = $addon_path . $name . DIRECTORY_SEPARATOR;
                if (!is_dir($addonDir)) {
                    continue;
                }

                if (!is_file($addonDir . ucfirst($name) . '.php')) {
                    continue;
                }
                //这里不采用get_addon_info是因为会有缓存
                //$info = get_addon_info($name);
                $info_file = $addonDir . 'info.ini';
                if (!is_file($info_file)) {
                    continue;
                }
                $config = new ConfigM();
                $info = $config->load($info_file,$name);
                if (!isset($info['name'])) {
                    continue;
                }
//                $info['url'] = addon_url($name);
                $list[$name] = $info;
            }
            return $list;
        }
    }


    if (!function_exists('remove_empty_folder')) {
        /**
         * 移除空目录
         * @param string $dir 目录
         */
        function remove_empty_folder($dir)
        {
            try {
                $isDirEmpty = !(new \FilesystemIterator($dir))->valid();
                if ($isDirEmpty) {
                    @rmdir($dir);
                    remove_empty_folder(dirname($dir));
                }
            } catch (\UnexpectedValueException $e) {
            } catch (\Exception $e) {
            }
        }
    }


    function get_files($dir) {
        $files = array();
        for (; $dir->valid(); $dir->next()) {
            if ($dir->isDir() && !$dir->isDot()) {
                if ($dir->haschildren()) {
                    $files = array_merge($files, get_files($dir->getChildren()));

                };
            }else if($dir->isFile()){
                $files[] = $dir->getPathName();
            }
        }
        return $files;
    }

}
