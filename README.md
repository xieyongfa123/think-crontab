# think-crontab for ThinkPHP5.1.*

## 声明

> composer require xieyongfa/think-crontab
## 安装

> composer require xieyongfa/think-crontab

## 开始使用
> 创建如下数据表

```
CREATE TABLE `crontab`  (
  `id` bigint(20) NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT '任务名',
  `class` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT '类名',
  `payload` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL NOT NULL COMMENT '参数',
  `last_execute_time` datetime(0) NOT NULL COMMENT '上次执行时间',
  `next_execute_time` datetime(0) NOT NULL COMMENT '下次执行时间',
  `status` tinyint(2) NOT NULL DEFAULT 1 COMMENT '0禁用 1启用',
  `interval_sec` int(11) NOT NULL DEFAULT 60 COMMENT '执行间隔 单位秒',
  `create_time` datetime(0) NOT NULL COMMENT '创建时间',
  `update_time` datetime(0) NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8 COLLATE = utf8_general_ci ROW_FORMAT = Dynamic;
 ```

## 创建计划任务

> `push_crontab($name, $class, $payload = '{}', $interval_sec = 60)`

`$name` 是任务名  
`$class` 是类名  
`$payload` 是参数 json字符串  
`$interval_sec` 是任务执行周期

## 监听计划并执行,强烈建议配合supervisor使用，保证进程常驻

> php think crontab --sleep=60 --memory=8

sleep参数:间隔多久查询一次 单位秒
memory参数:单个进程消耗内存超过多少M自动退出(配合supervisor可达到自动重启效果,防止内存溢出)