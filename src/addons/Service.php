<?php
declare(strict_types=1);

namespace think\addons;

use Symfony\Component\VarExporter\VarExporter;
//use think\Db;
use think\facade\Db;
use think\Route;
use think\helper\Str;
use think\facade\Config;
use think\facade\Lang;
use think\facade\Cache;
use think\facade\Event;
use think\addons\middleware\Addons;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use PhpZip\Exception\ZipException;
use PhpZip\ZipFile;
use think\Exception;
/**
 * 插件服务
 * Class Service
 * @package think\addons
 */
class Service extends \think\Service
{
    protected $addons_path;

    public function register()
    {
        $this->addons_path = $this->getAddonsPath();
        // 加载系统语言包
        Lang::load([
            $this->app->getRootPath() . '/vendor/bb-studio/by-addons/src/lang/zh-cn.php'
        ]);
        // 自动载入插件
        $this->autoload();
        // 加载插件事件
        $this->loadEvent();
        // 加载插件系统服务
        $this->loadService();
        // 绑定插件容器
        $this->app->bind('addons', Service::class);
    }

    public function boot()
    {
        $this->registerRoutes(function (Route $route) {
            // 路由脚本
            $execute = '\\think\\addons\\Route::execute';

            // 注册插件公共中间件
            if (is_file($this->app->addons->getAddonsPath() . 'middleware.php')) {
                $this->app->middleware->import(include $this->app->addons->getAddonsPath() . 'middleware.php', 'route');
            }

            // 注册控制器路由
            $route->rule("addons/:addon/[:controller]/[:action]", $execute)->middleware(Addons::class);
            // 自定义路由
            $routes = (array) Config::get('addons.route', []);
            foreach ($routes as $key => $val) {
                if (!$val) {
                    continue;
                }
                if (is_array($val)) {
                    $domain = $val['domain'];
                    $rules = [];
                    foreach ($val['rule'] as $k => $rule) {
                        [$addon, $controller, $action] = explode('/', $rule);
                        $rules[$k] = [
                            'addons'        => $addon,
                            'controller'    => $controller,
                            'action'        => $action,
                            'indomain'      => 1,
                        ];
                    }
                    $route->domain($domain, function () use ($rules, $route, $execute) {
                        // 动态注册域名的路由规则
                        foreach ($rules as $k => $rule) {
                            $route->rule($k, $execute)
                                ->name($k)
                                ->completeMatch(true)
                                ->append($rule);
                        }
                    });
                } else {
                    list($addon, $controller, $action) = explode('/', $val);
                    $route->rule($key, $execute)
                        ->name($key)
                        ->completeMatch(true)
                        ->append([
                            'addons' => $addon,
                            'controller' => $controller,
                            'action' => $action
                        ]);
                }
            }
        });
    }

    /**
     * 插件事件
     */
    private function loadEvent()
    {
        $hooks = $this->app->isDebug() ? [] : Cache::get('hooks', []);
        if (empty($hooks)) {
            $hooks = (array) Config::get('addons.hooks', []);
            // 初始化钩子
            foreach ($hooks as $key => $values) {
                if (is_string($values)) {
                    $values = explode(',', $values);
                } else {
                    $values = (array) $values;
                }
                $hooks[$key] = array_filter(array_map(function ($v) use ($key) {
                    return [get_addons_class($v), $key];
                }, $values));
            }
            Cache::set('hooks', $hooks);
        }
        //如果在插件中有定义 AddonsInit，则直接执行
        if (isset($hooks['AddonsInit'])) {
            foreach ($hooks['AddonsInit'] as $k => $v) {
                Event::trigger('AddonsInit', $v);
            }
        }
        Event::listenEvents($hooks);
    }

    /**
     * 挂载插件服务
     */
    private function loadService()
    {
        $results = scandir($this->addons_path);
        $bind = [];
        foreach ($results as $name) {
            if ($name === '.' or $name === '..') {
                continue;
            }
            if (is_file($this->addons_path . $name)) {
                continue;
            }
            $addonDir = $this->addons_path . $name . DIRECTORY_SEPARATOR;
            if (!is_dir($addonDir)) {
                continue;
            }

            if (!is_file($addonDir . ucfirst($name) . '.php')) {
                continue;
            }

            $service_file = $addonDir . 'service.ini';
            if (!is_file($service_file)) {
                continue;
            }
            $info = parse_ini_file($service_file, true, INI_SCANNER_TYPED) ?: [];
            $bind = array_merge($bind, $info);
        }
        $this->app->bind($bind);
    }

    /**
     * 自动载入插件
     * @return bool
     */
    private  function autoload()
    {
        // 是否处理自动载入
        if (!Config::get('addons.autoload', true)) {
            return true;
        }
        $config = Config::get('addons');
        // 读取插件目录及钩子列表
        $base = get_class_methods("\\think\\Addons");
        // 读取插件目录中的php文件
        foreach (glob($this->getAddonsPath() . '*/*.php') as $addons_file) {
            // 格式化路径信息
            $info = pathinfo($addons_file);
            // 获取插件目录名
            $name = pathinfo($info['dirname'], PATHINFO_FILENAME);
            // 找到插件入口文件
            if (strtolower($info['filename']) === 'plugin') {
                // 读取出所有公共方法
                $methods = (array)get_class_methods("\\addons\\" . $name . "\\" . $info['filename']);
                // 跟插件基类方法做比对，得到差异结果
                $hooks = array_diff($methods, $base);
                // 循环将钩子方法写入配置中
                foreach ($hooks as $hook) {
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
            }
        }
        Config::set($config, 'addons');
    }

    /**
     * 获取 addons 路径
     * @return string
     */
    public function getAddonsPath()
    {
        // 初始化插件目录
        $addons_path = $this->app->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
        // 如果插件目录不存在则创建
        if (!is_dir($addons_path)) {
            @mkdir($addons_path, 0755, true);
        }
        return $addons_path;
    }

    /**
     * 获取插件的配置信息
     * @param string $name
     * @return array
     */
    public function getAddonsConfig()
    {
        $name = $this->app->request->addon;
        $addon = get_addons_instance($name);
        if (!$addon) {
            return [];
        }
        return $addon->getConfig();
    }

    /*****************/
    /**
     * 插件列表
     */
    public static function addons($params = [])
    {
        $params['domain'] = request()->host(true);
        return self::sendRequest('/addon/index', $params, 'GET');
    }

    /**
     * 检测插件是否购买授权
     */
    public static function isBuy($name, $extend = [])
    {
        $params = array_merge(['name' => $name, 'domain' => request()->host(true)], $extend);
        return self::sendRequest('/addon/isbuy', $params, 'POST');
    }

    /**
     * 检测插件是否授权
     *
     * @param string $name   插件名称
     * @param string $domain 验证域名
     */
    public static function isAuthorization($name, $domain = '')
    {
        $config = self::config($name);
        $request = request();
        $domain = self::getRootDomain($domain ? $domain : $request->host(true));
        if (isset($config['domains']) && isset($config['domains']) && isset($config['validations']) && isset($config['licensecodes'])) {
            $index = array_search($domain, $config['domains']);
            if ((in_array($domain, $config['domains']) && in_array(md5(md5($domain) . ($config['licensecodes'][$index] ?? '')), $config['validations'])) || $request->isCli()) {
                return true;
            }
        }
        return false;
    }

    /**
     * 远程下载插件
     *
     * @param string $name   插件名称
     * @param string $url  远程链接
     * @return  string
     */
    public static function download($name,$url='', $extend = [])
    {
        $addonsTempDir = self::getAddonsBackupDir();
        $tmpFile = $addonsTempDir . $name . ".zip";
        try {
            $url=urldecode($url);
            if(false === @file_put_contents($tmpFile, file_get_contents($url))){
                return false;
            }
            return $tmpFile;
        } catch (TransferException $e) {
            throw new Exception("插件下载失败");
        }
    }

    /**
     * 解压插件
     *
     * @param string $name 插件名称
     * @return  string
     * @throws  Exception
     */
    public static function unzip($name)
    {
        if (!$name) {
            throw new Exception('无效参数');
        }
        $addonsBackupDir = self::getAddonsBackupDir();
        $file = $addonsBackupDir . $name . '.zip';

        // 打开插件压缩包
        $zip = new ZipFile();
        $zip->openFile($file);

        try {
            $zip->openFile($file);
        } catch (ZipException $e) {
            $zip->close();
            throw new Exception('无法打开zip文件');
        }

        $dir = self::getAddonDir($name);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755);
        }

        // 解压插件压缩包
        try {
            $zip->extractTo($dir);
        } catch (ZipException $e) {
            throw new Exception('无法解压ZIP文件');
        } finally {
            $zip->close();
        }
        return $dir;
    }

    /**
     * 离线安装
     * @param string $file 插件压缩包
     * @param array  $extend
     */
    public static function local($file, $extend = [])
    {
        $addonsTempDir = self::getAddonsBackupDir();
        $tmpFile = $file;
        $info = [];
        $zip = new ZipFile();
        try {
            // 打开插件压缩包
            try {
                $zip->openFile($tmpFile);
            } catch (ZipException $e) {
                @unlink($tmpFile);
                throw new Exception('无法打开ZIP文件');
            }
            $config = self::getInfoIni($zip);
            // 判断插件标识
            $name = isset($config['name']) ? $config['name'] : '';
            if (!$name) {
                throw new Exception('插件配置信息不正确');
            }

            // 判断插件是否存在
            if (!preg_match("/^[a-zA-Z0-9]+$/", $name)) {
                throw new Exception('插件名称不正确');
            }

            // 判断新插件是否存在
            $newAddonDir = self::getAddonDir($name);

            if (is_dir($newAddonDir)) {
                throw new Exception('插件已经存在');
            }

            // 追加MD5和Data数据
            $extend['md5'] = md5_file($tmpFile);
            $extend['data'] = $zip->getArchiveComment();
            $extend['unknownsources'] = config('app_debug') && config('app.byadmin.unknownsources');
            $extend['faversion'] = config('fastadmin.version');
            $params = array_merge($config, $extend);

            // 压缩包验证、版本依赖判断
            //Service::valid($params);

            //创建插件目录
            @mkdir($newAddonDir, 0755, true);

            // 解压到插件目录
            try {
                $zip->extractTo($newAddonDir);
            } catch (ZipException $e) {
                @unlink($newAddonDir);
                throw new Exception('无法解压ZIP文件');
            }

            Db::startTrans();
            try {
                //默认禁用该插件
                $info = get_addon_info($name);
                if ($info['state']) {
                    $info['state'] = 0;
                    set_addon_info($name, $info);
                }
                //执行插件的安装方法
                $class = get_addons_class($name);
                if (class_exists($class)) {
                    $addon = new $class(app());
                    $addon->install();
                }
                Db::commit();
            } catch (\Exception $e) {
                Db::rollback();
                @rmdirs($newAddonDir);
                throw new Exception($e->getMessage());
            }
            //导入SQL
            Service::importsql($name);
        } catch (AddonException $e) {
            throw new AddonException($e->getMessage(), $e->getCode(), $e->getData());
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        } finally {
            $zip->close();
            unset($uploadFile);
            @unlink($tmpFile);
        }
        $info['config'] = get_addon_config($name) ? 1 : 0;
        $info['bootstrap'] = is_file(Service::getBootstrapFile($name));
        $info['testdata'] = is_file(Service::getTestdataFile($name));
        return $info;
    }

    /**
     * 验证压缩包、依赖验证
     * @param array $params
     * @return bool
     * @throws Exception
     */
    public static function valid($params = [])
    {
        $json = self::sendRequest('/addon/valid', $params, 'POST');
        if ($json && isset($json['code'])) {
            if ($json['code']) {
                return true;
            } else {
                throw new Exception($json['msg'] ?? "未验证的插件");
            }
        } else {
            throw new Exception("未知数据格式");
        }
    }

    /**
     * 备份插件
     * @param string $name 插件名称
     * @return bool
     * @throws Exception
     */
    public static function backup($name)
    {
        $addonsBackupDir = self::getAddonsBackupDir();
        $file = $addonsBackupDir . $name . '-backup-' . date("YmdHis") . '.zip';
        $zipFile = new ZipFile();
        try {
            $zipFile
                ->addDirRecursive(self::getAddonDir($name))
                ->saveAsFile($file)
                ->close();
        } catch (ZipException $e) {

        } finally {
            $zipFile->close();
        }

        return true;
    }

    /**
     * 检测插件是否完整
     *
     * @param string $name 插件名称
     * @return  boolean
     * @throws  Exception
     */
    public static function check($name)
    {
        $addon_path = app()->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
        if (!$name || !is_dir($addon_path . $name)) {
            throw new Exception('插件不存在');
        }
        $addonClass = get_addons_class($name);
        if (!$addonClass) {
            throw new Exception("插件主启动程序不存在");
        }
        $addon = new $addonClass(app());
        if (!$addon->checkInfo()) {
            throw new Exception("配置文件不完整");
        }
        return true;
    }

    /**
     * 是否有冲突
     *
     * @param string $name 插件名称
     * @return  boolean
     * @throws  AddonException
     */
    public static function noconflict($name)
    {
        // 检测冲突文件
        $list = self::getGlobalFiles($name, true);
        if ($list) {
            //发现冲突文件，抛出异常
            throw new AddonException("发现冲突文件", -3, ['conflictlist' => $list]);
        }
        return true;
    }

    /**
     * 导入SQL
     *
     * @param string $name     插件名称
     * @param string $fileName SQL文件名称
     * @return  boolean
     */
    public static function importsql($name, $fileName = null)
    {
        $fileName = is_null($fileName) ? 'install.sql' : $fileName;
        $sqlFile = self::getAddonDir($name) . $fileName;
        if (is_file($sqlFile)) {
            $lines = file($sqlFile);
            $templine = '';
            foreach ($lines as $line) {
                if (substr($line, 0, 2) == '--' || $line == '' || substr($line, 0, 2) == '/*') {
                    continue;
                }
                $templine .= $line;
                if (substr(trim($line), -1, 1) == ';') {
                    $templine = str_ireplace('__PREFIX__', config('database.connections.mysql.prefix'), $templine);
                    $templine = str_ireplace('INSERT INTO ', 'INSERT IGNORE INTO ', $templine);
//                    echo $templine;
                    try {
                        Db::execute($templine);
//                        Db::getPdo()->exec($templine);
                    } catch (\PDOException $e) {
                        $e->getMessage();
                    }
                    $templine = '';
                }
            }
        }
        return true;
    }

    /**
     * 刷新插件缓存文件
     *
     * @return  boolean
     * @throws  Exception
     */
    public static function refresh()
    {
        $addon_path = app()->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
        //刷新addons.js
        $addons = get_addon_list();
//        $bootstrapArr = [];
//        foreach ($addons as $name => $addon) {
//            $bootstrapFile = self::getBootstrapFile($name);
//            if ($addon['state'] && is_file($bootstrapFile)) {
//                $bootstrapArr[] = file_get_contents($bootstrapFile);
//            }
//        }
//        $addonsFile = $addon_path . str_replace("/", DIRECTORY_SEPARATOR, "public/assets/js/addons.js");
//        if ($handle = fopen($addonsFile, 'w')) {
//            $tpl = <<<EOD
//define([], function () {
//    {__JS__}
//});
//EOD;
//            fwrite($handle, str_replace("{__JS__}", implode("\n", $bootstrapArr), $tpl));
//            fclose($handle);
//        } else {
//            throw new Exception(__("Unable to open file '%s' for writing", "addons.js"));
//        }

//        Cache::rm("addons");
//        Cache::rm("hooks");

        $file = self::getExtraAddonsFile();

        $config = get_addon_autoload_config(true);

        //自动加载也是自动写入，没啥意义
//        if ($config['autoload']) {
//            return;
//        }

        if (!is_really_writable($file)) {
            throw new Exception("无法打开 addons.php写入");
        }

        file_put_contents($file, "<?php\n\n" . "return " . VarExporter::export($config) . ";\n", LOCK_EX);
        return true;
    }

    /**
     * 安装插件
     *
     * @param string  $name   插件名称
     * @param boolean $force  是否覆盖
     * @param array   $extend 扩展参数
     * @return  boolean
     * @throws  Exception
     * @throws  AddonException
     */
    public static function install($name, $url='',$force = false, $extend = [])
    {
        $addon_path = app()->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
        if (!$name || (is_dir($addon_path . $name) && !$force)) {
            throw new Exception('插件目录已存在');
        }

        $extend['domain'] = request()->host(true);

        // 远程下载插件
        $tmpFile = Service::download($name,$url);
        if(!$tmpFile) exit;
        $addonDir = self::getAddonDir($name);
        try {
            // 解压插件压缩包到插件目录
            Service::unzip($name);
            // 检查插件是否完整
            Service::check($name);
            if (!$force) {
                Service::noconflict($name);
            }
        } catch (AddonException $e) {
            @rmdirs($addonDir);
            throw new AddonException($e->getMessage(), $e->getCode(), $e->getData());
        } catch (Exception $e) {
            @rmdirs($addonDir);
            throw new Exception($e->getMessage());
        } finally {
            // 移除临时文件
            @unlink($tmpFile);
        }

        // 默认启用该插件
        $info = get_addon_info($name);
        Db::startTrans();
        try {
            if (!$info['state']) {
                $info['state'] = 1;
                set_addon_info($name, $info);
            }
            // 执行安装脚本
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class(app());
                $addon->install();
            }
            Db::commit();
        } catch (Exception $e) {
            @rmdirs($addonDir);
            Db::rollback();
            throw new Exception($e->getMessage());
        }
        // 导入
        Service::importsql($name);
        // 启用插件
        Service::enable($name, true);
        $info['config'] = get_addon_config($name) ? 1 : 0;
        $info['bootstrap'] = is_file(Service::getBootstrapFile($name));
        $info['testdata'] = is_file(Service::getTestdataFile($name));
        return $info;
    }

    /**
     * 卸载插件
     *
     * @param string  $name
     * @param boolean $force 是否强制卸载
     * @return  boolean
     * @throws  Exception
     */
    public static function uninstall($name, $force = false)
    {
        $addon_path = app()->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
        if (!$name || !is_dir($addon_path . $name)) {
            throw new Exception('插件不存在');
        }

        if (!$force) {
            Service::noconflict($name);
        }

        // 移除插件全局资源文件
        if ($force) {
            $list = Service::getGlobalFiles($name);
            foreach ($list as $k => $v) {
                @unlink($addon_path . $v);
            }
        }

        // 执行卸载脚本
        try {
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class(app());
                $addon->uninstall();
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        // 移除插件目录
        rmdirs($addon_path . $name);

        // 刷新
        Service::refresh();
        return true;
    }

    /**
     * 启用
     * @param string  $name  插件名称
     * @param boolean $force 是否强制覆盖
     * @return  boolean
     */
    public static function enable($name, $force = false)
    {
        $addon_path = app()->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
        if (!$name || !is_dir($addon_path . $name)) {
            throw new Exception('插件不存在');
        }

//        if (!$force) {
//            Service::noconflict($name);
//        }

        //备份冲突文件
        if (config('app.byadmin.backup_global_files')) {
            $conflictFiles = self::getGlobalFiles($name, true);
            if ($conflictFiles) {
                $zip = new ZipFile();
                try {
                    foreach ($conflictFiles as $k => $v) {
                        $zip->addFile($addon_path . $v, $v);
                    }
                    $addonsBackupDir = self::getAddonsBackupDir();
                    $zip->saveAsFile($addonsBackupDir . $name . "-conflict-enable-" . date("YmdHis") . ".zip");
                } catch (Exception $e) {

                } finally {
                    $zip->close();
                }
            }
        }

        $addonDir = self::getAddonDir($name);
        $sourceAssetsDir = self::getSourceAssetsDir($name);
        $destAssetsDir = self::getDestAssetsDir($name);
        $files = self::getGlobalFiles($name);
        if ($files) {
            //刷新插件配置缓存
            Service::config($name, ['files' => $files]);
        }

        // 复制文件
        if (is_dir($sourceAssetsDir)) {
            copydirs($sourceAssetsDir, $destAssetsDir);
        }

        // 复制application和public到全局
        foreach (self::getCheckDirs() as $k => $dir) {
            if (is_dir($addonDir . $dir)) {
                copydirs($addonDir . $dir, root_path() . $dir);
            }
        }

        //插件纯净模式时将插件目录下的application、public和assets删除
        if (config('app.byadmin.addon_pure_mode')) {
            // 删除插件目录已复制到全局的文件
            @rmdirs($sourceAssetsDir);
            foreach (self::getCheckDirs() as $k => $dir) {
                @rmdirs($addonDir . $dir);
            }
        }

        //执行启用脚本
        try {
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class(app());
                if (method_exists($class, "enable")) {
                    $addon->enable();
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        $info = get_addon_info($name);
        $info['state'] = 1;
        unset($info['url']);

        set_addon_info($name, $info);

        // 刷新
        Service::refresh();
        return true;
    }

    /**
     * 禁用
     *
     * @param string  $name  插件名称
     * @param boolean $force 是否强制禁用
     * @return  boolean
     * @throws  Exception
     */
    public static function disable($name, $force = false)
    {
        $addon_path = app()->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
        if (!$name || !is_dir($addon_path . $name)) {
            throw new Exception('插件不存在');
        }

        $file = self::getExtraAddonsFile();
        if (!is_really_writable($file)) {
            throw new Exception("无法打开addons.php编写");
        }

        if (!$force) {
            Service::noconflict($name);
        }

        if (config('app.byadmin.backup_global_files')) {
            //仅备份修改过的文件
            $conflictFiles = Service::getGlobalFiles($name, true);
            if ($conflictFiles) {
                $zip = new ZipFile();
                try {
                    foreach ($conflictFiles as $k => $v) {
                        $zip->addFile(ROOT_PATH . $v, $v);
                    }
                    $addonsBackupDir = self::getAddonsBackupDir();
                    $zip->saveAsFile($addonsBackupDir . $name . "-conflict-disable-" . date("YmdHis") . ".zip");
                } catch (Exception $e) {

                } finally {
                    $zip->close();
                }
            }
        }

        $config = Service::config($name);
        $addonDir = self::getAddonDir($name);
        //插件资源目录
        $destAssetsDir = self::getDestAssetsDir($name);

        // 移除插件全局文件
        $list = Service::getGlobalFiles($name);
        //插件纯净模式时将原有的文件复制回插件目录
        //当无法获取全局文件列表时也将列表复制回插件目录
        if (config('app.byadmin.addon_pure_mode') || !$list) {
            if ($config && isset($config['files']) && is_array($config['files'])) {
                foreach ($config['files'] as $index => $item) {
                    //避免切换不同服务器后导致路径不一致
                    $item = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $item);
                    //插件资源目录，无需重复复制
                    if (stripos($item, str_replace(root_path(), '', $destAssetsDir)) === 0) {
                        continue;
                    }
                    //检查目录是否存在，不存在则创建
                    $itemBaseDir = dirname($addonDir . $item);
                    if (!is_dir($itemBaseDir)) {
                        @mkdir($itemBaseDir, 0755, true);
                    }
                    if (is_file(root_path() . $item)) {
                        @copy(root_path() . $item, $addonDir . $item);
                    }
                }
                $list = $config['files'];
            }
            //复制插件目录资源
            if (is_dir($destAssetsDir)) {
                @copydirs($destAssetsDir, $addonDir . 'assets' . DIRECTORY_SEPARATOR);
            }
        }

        $dirs = [];
        foreach ($list as $k => $v) {
            $file = root_path() . $v;
            $dirs[] = dirname($file);
            @unlink($file);
        }

        // 移除插件空目录
        $dirs = array_filter(array_unique($dirs));
        foreach ($dirs as $k => $v) {
            remove_empty_folder($v);
        }

        $info = get_addon_info($name);
        $info['state'] = 0;
        unset($info['url']);

        set_addon_info($name, $info);

        // 执行禁用脚本
        try {
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class(app());

                if (method_exists($class, "disable")) {
                    $addon->disable();
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        // 刷新
        Service::refresh();
        return true;
    }

    /**
     * 升级插件
     *
     * @param string $name   插件名称
     * @param array  $extend 扩展参数
     */
    public static function upgrade($name, $extend = [])
    {
        $info = get_addon_info($name);
        if ($info['state']) {
            throw new Exception('请先禁用插件');
        }
        $config = get_addon_config($name);
        if ($config) {
            //备份配置
        }

        // 远程下载插件
        $tmpFile = Service::download($name, $extend);

        // 备份插件文件
        Service::backup($name);

        $addonDir = self::getAddonDir($name);

        // 删除插件目录下的application和public
        $files = self::getCheckDirs();
        foreach ($files as $index => $file) {
            @rmdirs($addonDir . $file);
        }

        try {
            // 解压插件
            Service::unzip($name);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        } finally {
            // 移除临时文件
            @unlink($tmpFile);
        }

        if ($config) {
            // 还原配置
            set_addon_config($name, $config);
        }

        // 导入
        Service::importsql($name);
        // 执行升级脚本
        try {
            $addonName = ucfirst($name);
            //创建临时类用于调用升级的方法
            $sourceFile = $addonDir . $addonName . ".php";
            $destFile = $addonDir . $addonName . "Upgrade.php";

            $classContent = str_replace("class {$addonName} extends", "class {$addonName}Upgrade extends", file_get_contents($sourceFile));

            //创建临时的类文件
            file_put_contents($destFile, $classContent);

            $className = "\\addons\\" . $name . "\\" . $addonName . "Upgrade";
            $addon = new $className($name);

            //调用升级的方法
            if (method_exists($addon, "upgrade")) {
                $addon->upgrade();
            }

            //移除临时文件
            @unlink($destFile);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        // 刷新
        Service::refresh();

        //必须变更版本号
        $info['version'] = isset($extend['version']) ? $extend['version'] : $info['version'];

        $info['config'] = get_addon_config($name) ? 1 : 0;
        $info['bootstrap'] = is_file(Service::getBootstrapFile($name));
        return $info;
    }

    /**
     * 读取或修改插件配置
     * @param string $name
     * @param array  $changed
     * @return array
     */
    public static function config($name, $changed = [])
    {
        $addonDir = self::getAddonDir($name);
        $addonConfigFile = $addonDir . '.addonrc';
        $config = [];
        if (is_file($addonConfigFile)) {
            $config = (array)json_decode(file_get_contents($addonConfigFile), true);
        }
        $config = array_merge($config, $changed);
        if ($changed) {
            file_put_contents($addonConfigFile, json_encode($config, JSON_UNESCAPED_UNICODE));
        }
        return $config;
    }

    /**
     * 获取插件在全局的文件
     *
     * @param string  $name         插件名称
     * @param boolean $onlyconflict 是否只返回冲突文件
     * @return  array
     */
    public static function getGlobalFiles($name, $onlyconflict = false)
    {
        $list = [];
        $addonDir = self::getAddonDir($name);
        $checkDirList = self::getCheckDirs();
        $checkDirList = array_merge($checkDirList, ['assets']);

        $assetDir = self::getDestAssetsDir($name);

        // 扫描插件目录是否有覆盖的文件
        foreach ($checkDirList as $k => $dirName) {
            //检测目录是否存在
            if (!is_dir($addonDir . $dirName)) {
                continue;
            }
//            $dir = new RecursiveDirectoryIterator($addonDir . $dirName, RecursiveDirectoryIterator::SKIP_DOTS);
//            $files= new RecursiveIteratorIterator($dir,RecursiveIteratorIterator::CHILD_FIRST);
//            var_dump($ff);exit();
//            var_dump(get_files($dir));exit();
//            //匹配出所有的文件
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($addonDir . $dirName, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST
            );

//            $files = get_files($dir);

            foreach ($files as $fileinfo) {
                if ($fileinfo->isFile()) {
                    $filePath = $fileinfo->getPathName();
                    //如果名称为assets需要做特殊处理
                    if ($dirName === 'assets') {
                        $path = str_replace(root_path(), '', $assetDir) . str_replace($addonDir . $dirName . DIRECTORY_SEPARATOR, '', $filePath);
                    } else {
                        $path = str_replace($addonDir, '', $filePath);
                    }
//                    var_dump($path);exit();
                    if ($onlyconflict) {
                        $destPath = root_path() . $path;
                        if (is_file($destPath)) {//
                            if (filesize($filePath) != filesize($destPath) || md5_file($filePath) != md5_file($destPath)) {
                                $list[] = $path;
                            }
                        }
                    } else {
                        $list[] = $path;
                    }
                }
            }
        }
        $list = array_filter(array_unique($list));
        return $list;
    }

    /**
     * 更新本地应用插件授权
     */
    public static function authorization($params = [])
    {
        $addonList = get_addon_list();
        $result = [];
        $domain = request()->host(true);
        $addons = [];
        foreach ($addonList as $name => $item) {
            $config = self::config($name);
            $addons[] = ['name' => $name, 'domains' => $config['domains'] ?? [], 'licensecodes' => $config['licensecodes'] ?? [], 'validations' => $config['validations'] ?? []];
        }
        $params = array_merge($params, [
            'faversion' => config('fastadmin.version'),
            'domain'    => $domain,
            'addons'    => $addons
        ]);
        $result = self::sendRequest('/addon/authorization', $params, 'POST');
        if (isset($result['code']) && $result['code'] == 1) {
            $json = $result['data']['addons'] ?? [];
            foreach ($addonList as $name => $item) {
                self::config($name, ['domains' => $json[$name]['domains'] ?? [], 'licensecodes' => $json[$name]['licensecodes'] ?? [], 'validations' => $json[$name]['validations'] ?? []]);
            }
            return true;
        } else {
            throw new Exception($result['msg'] ??'网络错误');
        }
    }

    /**
     * 验证插件授权，应用插件需要授权使用，移除或绕过授权验证，保留追究法律责任的权利
     * @param $name
     * @return bool
     */
    public static function checkAddonAuthorization($name)
    {
        $request = request();
        $config = self::config($name);
        $domain = self::getRootDomain($request->host(true));
        //应用插件需要授权使用，移除或绕过授权验证，保留追究法律责任的权利
        if (isset($config['domains']) && isset($config['domains']) && isset($config['validations']) && isset($config['licensecodes'])) {
            $index = array_search($domain, $config['domains']);
            if ((in_array($domain, $config['domains']) && in_array(md5(md5($domain) . ($config['licensecodes'][$index] ?? '')), $config['validations'])) || $request->isCli()) {
                $request->bind('authorized', $domain ?: 'cli');
                return true;
            } elseif ($config['domains']) {
                foreach ($config['domains'] as $index => $item) {
                    if (substr_compare($domain, "." . $item, -strlen("." . $item)) === 0 && in_array(md5(md5($item) . ($config['licensecodes'][$index] ?? '')), $config['validations'])) {
                        $request->bind('authorized', $domain);
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * 获取顶级域名
     * @param $domain
     * @return string
     */
    public static function getRootDomain($domain)
    {
        $host = strtolower(trim($domain));
        $hostArr = explode('.', $host);
        $hostCount = count($hostArr);
        $cnRegex = '/\w+\.(gov|org|ac|mil|net|edu|com|bj|tj|sh|cq|he|sx|nm|ln|jl|hl|js|zj|ah|fj|jx|sd|ha|hb|hn|gd|gx|hi|sc|gz|yn|xz|sn|gs|qh|nx|xj|tw|hk|mo)\.cn$/i';
        $countryRegex = '/\w+\.(\w{2}|com|net)\.\w{2}$/i';
        if ($hostCount > 2 && (preg_match($cnRegex, $host) || preg_match($countryRegex, $host))) {
            $host = implode('.', array_slice($hostArr, -3, 3, true));
        } else {
            $host = implode('.', array_slice($hostArr, -2, 2, true));
        }
        return $host;
    }

    /**
     * 获取插件行为、路由配置文件
     * @return string
     */
    public static function getExtraAddonsFile()
    {
        $config_path = app()->getRootPath() . 'config' . DIRECTORY_SEPARATOR;
        return $config_path. 'addons.php';
    }

    /**
     * 获取bootstrap.js路径
     * @return string
     */
    public static function getBootstrapFile($name)
    {
        $addon_path = app()->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
        return $addon_path . $name . DIRECTORY_SEPARATOR . 'bootstrap.js';
    }

    /**
     * 获取testdata.sql路径
     * @return string
     */
    public static function getTestdataFile($name)
    {
        $addon_path = app()->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
        return $addon_path . $name . DIRECTORY_SEPARATOR . 'testdata.sql';
    }

    /**
     * 获取指定插件的目录
     */
    public static function getAddonDir($name)
    {
        $addon_path = app()->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
        $dir = $addon_path . $name . DIRECTORY_SEPARATOR;
        return $dir;
    }

    /**
     * 获取插件备份目录
     */
    public static function getAddonsBackupDir()
    {
        $runtime_path = app()->getRootPath() . 'runtime' . DIRECTORY_SEPARATOR;
        $dir = $runtime_path . 'addons' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * 获取插件源资源文件夹
     * @param string $name 插件名称
     * @return  string
     */
    protected static function getSourceAssetsDir($name)
    {
        $addon_path = app()->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
        return $addon_path . $name . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR;
    }

    /**
     * 获取插件目标资源文件夹
     * @param string $name 插件名称
     * @return  string
     */
    protected static function getDestAssetsDir($name)
    {
        $assetsDir = root_path() . str_replace("/", DIRECTORY_SEPARATOR, "public/assets/addons/{$name}/");
        return $assetsDir;
    }

    /**
     * 获取远程服务器
     * @return  string
     */
    protected static function getServerUrl()
    {
        return config('app.web_market.official_url');
    }

    /**
     * 获取检测的全局文件夹目录
     * @return  array
     */
    protected static function getCheckDirs()
    {
        return [
            'app',
            'public'
        ];
    }

    /**
     * 获取请求对象
     * @return Client
     */
    public static function getClient()
    {
        $options = [
            'base_uri'        => self::getServerUrl(),
            'timeout'         => 30,
            'connect_timeout' => 30,
            'verify'          => false,
            'http_errors'     => false,
            'headers'         => [
                'X-REQUESTED-WITH' => 'XMLHttpRequest',
                'Referer'          => dirname(request()->root(true)),
                'User-Agent'       => 'FastAddon',
            ]
        ];
        static $client;
        if (empty($client)) {
            $client = new Client($options);
        }
        return $client;
    }

    /**
     * 发送请求
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public static function sendRequest($url, $params = [], $method = 'POST')
    {
        $json = [];
        try {
            $client = self::getClient();
            $options = strtoupper($method) == 'POST' ? ['form_params' => $params] : ['query' => $params];
            $response = $client->request($method, $url, $options);
            $body = $response->getBody();
            $content = $body->getContents();
            $json = (array)json_decode($content, true);
        } catch (TransferException $e) {
            throw new Exception('网络错误');
        } catch (\Exception $e) {
            throw new Exception('未知数据格式');
        }
        return $json;
    }

    /**
     * 匹配配置文件中info信息
     * @param ZipFile $zip
     * @return array|false
     * @throws Exception
     */
    protected static function getInfoIni($zip)
    {
        $config = [];
        // 读取插件信息
        try {
            $info = $zip->getEntryContents('info.ini');
            $config = parse_ini_string($info);
        } catch (ZipException $e) {
            throw new Exception('无法解压文件');
        }
        return $config;
    }


}
