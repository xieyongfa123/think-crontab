<?php
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: xieyongfa <xieyongfa@ecarde.com>
// +----------------------------------------------------------------------

namespace think;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Cache;
use think\facade\Evn;
use think\facade\Config;
use think\Db;
use think\Exception;
use think\exception\Handle;
use think\exception\ThrowableError;
use Throwable;


class Crontab extends Command
{
    protected $options = [
        'sleep'  => 60,
        'memory' => 32,
        'table'  => 'crontab'
    ];

    protected function configure()
    {
        $this->setName('crontab')
             ->addArgument('name', Argument::OPTIONAL, "your name")
             ->addOption('memory', null, Option::VALUE_OPTIONAL, 'The memory limit in megabytes', $this->options['memory'])
             ->addOption('sleep', null, Option::VALUE_OPTIONAL, 'Number of seconds to sleep when no job is available', $this->options['sleep'])
             ->setDescription('Do Crontab Job');
    }

    protected function execute(Input $input, Output $output)
    {
        //防止页面超时,实际上CLI命令行模式下 本身无超时时间
        ignore_user_abort(true);
        set_time_limit(0);
        $lastRestart = $this->getTimestampOfLastQueueRestart();
        $sleep       = $input->getOption('sleep');
        $memory      = $input->getOption('memory');
        $output->writeln("crontab is started successfully");
        while (true) {
            try {
                $this->runNextJobForDaemon($lastRestart, $memory);
            } catch (\Exception $e) {
                sleep($sleep); //单位为秒 //防止死循环
            }
        }
    }

    /**
     * 以守护进程的方式执行下个任务.
     *
     * @param  string $lastRestart
     * @param  int $memory
     * @return void
     */
    protected function runNextJobForDaemon($lastRestart, $memory)
    {
        try {
            $time                  = time();
            $map                   = [
                ['status', '=', 1],
                ['next_execute_time', '<=', date('Y-m-d H:i:s', $time)]
            ];
            $list                  = Db::name($this->options['table'])->where($map)->select();
            $next_execute_time_arr = [];
            $sleep                 = 60; //默认休眠60秒
            foreach ($list as $key => $val) {
                if ($this->memoryExceeded($memory) || $this->queueShouldRestart($lastRestart)) {
                    $this->stop();
                }
                $next_execute_time       = date("Y-m-d H:i:s", (strtotime($val['next_execute_time']) + $val['interval_sec']));
                $next_execute_time_arr[] = strtotime($next_execute_time);
                try {
                    $this->output($val['class']);
                    $payload = json_decode($val['payload'], true);
                    $this->resolveAndFire($val['class'], $payload);
                } catch (\Exception $e) {
                    $this->output($e->getMessage());
                    sleep(3); //异常休眠3秒 防止死循环
                }
                //无论是否出现异常 本周期都不再执行了
                Db::name($this->options['table'])->where('id', $val['id'])->update([
                    'last_execute_time' => date('Y-m-d H:i:s', $time),
                    'next_execute_time' => $next_execute_time,
                    'update_time'       => date('Y-m-d H:i:s')
                ]);
            }
            if (!empty($next_execute_time_arr)) {
                $min_next_execute_time_arr = min($next_execute_time_arr);
                $diff                      = $min_next_execute_time_arr - time() < $sleep;
                if ($diff < 3) {
                    //小于三秒按照三秒计算
                    $sleep = 3;
                } elseif ($diff < $sleep) {
                    //小于默认时间则按照实际时间
                    $sleep = $diff;
                }
            }
            sleep($sleep);
        } catch (\Exception $e) {
            $this->output($e->getMessage());
            $this->getExceptionHandler()->report($e);
            sleep(3); //异常休眠3秒 防止死循环
        } catch (Throwable $e) {
            $this->output($e->getMessage());
            $this->getExceptionHandler()->report(new ThrowableError($e));
            sleep(3); //异常休眠3秒 防止死循环
        }
    }

    public function push($name, $class, $payload = [], $interval_sec = 60)
    {
        return Db::name($this->options['table'])->insert([
            'name'              => $name,
            'class'             => $class,
            'payload'           => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'interval_sec'      => $interval_sec,
            'next_execute_time' => date('Y-m-d H:i:s'),
            'create_time'       => date('Y-m-d H:i:s'),
            'update_time'       => date('Y-m-d H:i:s'),
        ]);
    }

    protected function output($response)
    {
        $this->output->writeln($response);
    }

    /**
     * 获取异常处理实例
     *
     * @return \think\exception\Handle
     */
    protected function getExceptionHandler()
    {
        static $handle;

        if (!$handle) {

            if ($class = Config::get('exception_handle')) {
                if (class_exists($class) && is_subclass_of($class, "\\think\\exception\\Handle")) {
                    $handle = new $class;
                }
            }
            if (!$handle) {
                $handle = new Handle();
            }
        }

        return $handle;
    }

    /**
     * 获取缓存重启的key名称
     *
     * @return string 缓存key名称
     */
    protected function getQueueRestartCacheKeyName()
    {
        return 'think:crontab:restart';
    }

    /**
     * 获取上次重启守护进程的时间
     *
     * @return int|null
     */
    protected function getTimestampOfLastQueueRestart()
    {
        return Cache::get($this->getQueueRestartCacheKeyName());
    }

    /**
     * 检查是否要重启守护进程
     *
     * @param  int|null $lastRestart
     * @return bool
     */
    protected function queueShouldRestart($lastRestart)
    {
        $TimestampOfLastQueueRestart = $this->getTimestampOfLastQueueRestart();
        if (time() - $TimestampOfLastQueueRestart > 3600) {
            //超过一个小时也重启,释放内存
            return Cache::set($this->getQueueRestartCacheKeyName(), time());
        }
        return $TimestampOfLastQueueRestart != $lastRestart;
    }

    protected function stop()
    {
        die();
    }

    /**
     * 检查内存是否超出
     * @param  int $memoryLimit
     * @return bool
     */
    protected function memoryExceeded($memoryLimit = 32)
    {
        return $this->get_memory_get_usage() >= $memoryLimit;
    }

    /**
     * 获取当前占用内存,保留四位小数
     * @return float
     */
    protected function get_memory_get_usage()
    {
        return round(memory_get_usage() / 1024 / 1024, 4);
    }

    protected function resolveAndFire($name, array $payload)
    {
        list($class, $method) = $this->parseJob($name);
        $instance = $this->resolve($class);
        if ($instance) {
            $instance->{$method}($payload);
        }
    }

    /**
     * Parse the job declaration into class and method.
     * @param  string $job
     * @return array
     */
    protected function parseJob($job)
    {
        $segments = explode('@', $job);

        return count($segments) > 1 ? $segments : [$segments[0], 'fire'];
    }

    /**
     * Resolve the given job handler.
     * @param  string $name
     * @return mixed
     */
    protected function resolve($name)
    {
        if (strpos($name, '\\') === false) {

            if (strpos($name, '/') === false) {
                $module = '';
            } else {
                list($module, $name) = explode('/', $name, 2);
            }

            $name = Env::get('app_namespace') . ($module ? '\\' . strtolower($module) : '') . '\\job\\' . $name;
        }
        if (class_exists($name)) {
            return new $name();
        }
    }
}