
## ibibicloud cloudflare 

### 安装
~~~
composer require ibibicloud/cloudflare
~~~

### 配置文件
~~~
// cloudflare 配置
return [
    'D1' => [
        'accountId' => 'a529401f93143f3b8b2ba33c6123092e16ebb',
        'token'     => 'Bearer cfat_OOUYuXNd123sZBOwURmcDwR123dasd5123HlpKmpFSJFadsaLrFpO3a011256d',
        'dbId1'     => '3da81598-e252-4552-24a5-5b3c86417120',
    ],
];
~~~

### D1 SQL 数据库
~~~
CREATE TABLE IF NOT EXISTS users (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	nickname TEXT NOT NULL,
	avatar TEXT NOT NULL,
	signature TEXT NOT NULL,
	aweme_count INTEGER NOT NULL DEFAULT 0,
	follower_count INTEGER NOT NULL DEFAULT 0,
	following_count INTEGER NOT NULL DEFAULT 0,
	total_favorited INTEGER NOT NULL DEFAULT 0,
	sec_user_id TEXT NOT NULL,
	uid TEXT NOT NULL,
	unique_id TEXT NOT NULL,
	user_age TEXT NOT NULL,
	create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
	update_time DATETIME DEFAULT CURRENT_TIMESTAMP
);
~~~
~~~
CREATE TABLE IF NOT EXISTS author (
	id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
	nickname TEXT NOT NULL,
	avatar TEXT,
	signature TEXT,
	aweme_count TEXT,
	follower_count TEXT,
	total_favorited TEXT,
	sec_user_id TEXT NOT NULL,
	update_time TEXT NOT NULL
);
~~~

~~~
use ibibicloud\cloudflare\facade\D1;

// 查询
D1::table('users')->where('id', 8)->find();
D1::table('users')->where('id', '<', 10)->order('id DESC')->field('id, nickname')->limit(10)->select();		// 10=条数
D1::table('users')->where('id', '<', 10)->order('id DESC')->field('id, nickname')->limit(10, 20)->select();	// 10=条数 20=偏移量
D1::table('users')->where([
	['nickname', '=', '张三'],
	['aweme_count', '=', 125]
])->select());
D1::table('users')->count();

// 新增 支持 单条/多条
D1::table('users')->insert();

// 删除
D1::table('users')->where('id', 3)->delete();

// 更新
D1::table('users')->where('id', 4)->update(['nickname' => '李四']);

// 其他用法
D1::table('users')->where('id', 8)->value('nickname');
D1::table('users')->column('nickname');
D1::table('users')->column('nickname', 'id');

// 列出所有数据库
D1::DataBaseList();

// 连接指定数据库 ID
D1::database($dbId)->more...;

// 获取最后一次 SQL 语句
D1::table('users')->getLastSql();
~~~
