<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
class Utils
{
    const THEME_VERSION = '1.0.5';
    const VERSION_OPTION = 'theme:ITEM::version';
    const RANKED_ITEM_TEMPLATE = <<<HTML
    <div class="list-item">
        <div class="list-content">
            <div class="list-body">
                <div class="list-title h-1x">%s</div>
            </div>
        </div>
        <a href="%s" target="_blank" cid="%d" title="%s" class="list-goto nav-item"></a>
    </div>
    HTML;

    public static function init()
    {
        try {
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();

            // 获取当前版本
            $row = $db->fetchRow($db->select()->from('table.options')->where('name = ?', self::VERSION_OPTION));
            $version = $row ? $row['value'] : null;

            if ($version !== self::THEME_VERSION) {
                error_log('检测到主题更新, 重新初始化数据库...');

                // 浏览量字段
                if (!array_key_exists('views', $db->fetchRow($db->select()->from('table.contents')->limit(1)))) {
                    $db->query('ALTER TABLE `' . $prefix . 'contents` ADD `views` INT(10) NOT NULL DEFAULT 0;');
                }

                // 点赞字段
                if (!array_key_exists('agree', $db->fetchRow($db->select()->from('table.contents')->limit(1)))) {
                    $db->query('ALTER TABLE `' . $prefix . 'contents` ADD `agree` INT(10) NOT NULL DEFAULT 0;');
                }

                // 写入版本信息
                if ($version) {
                    $db->query($db->update('table.options')
                        ->rows(['value' => self::THEME_VERSION])
                        ->where('name = ?', self::VERSION_OPTION));
                } else {
                    $db->query($db->insert('table.options')->rows([
                        'name' => self::VERSION_OPTION,
                        'value' => self::THEME_VERSION,
                        'user' => 0
                    ]));
                }
                error_log('已从 ' . ($version ? $version : '未知版本') . ' 更新至 ' . self::THEME_VERSION);
            }
        } catch (Exception $e) {
            error_log('初始化数据库失败: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 获取文章缓存
     * @param int $cid 文章ID
     * @return Cache 文章缓存
     */
    public static function getCache($cid)
    {
        $data = Typecho_Cookie::get($cid);
        return $data ? unserialize($data) : new Cache(null, null);
    }

    /**
     * 设置文章缓存
     * @param int $cid 文章ID
     * @param Cache $cache 文章缓存
     */
    public static function putCache($cid, $cache)
    {
        Typecho_Cookie::set($cid, serialize($cache), time() + 600);
    }

    /**
     * 获取文章浏览量<br>
     * 如果文章缓存不存在，则视为有效访问，并更新浏览量<br>
     * 如果文章缓存存在，则直接返回浏览量<br>
     * @param int $cid 文章ID
     * @param bool $display 是否直接输出
     * @param bool $format 是否格式化
     * @return int|string 浏览量
     */
    public static function views($cid, $display = true, $format = true)
    {
        $cache = self::getCache($cid);

        if (!$cache->views) {
            //缓存不存在，视为有效访问
            $db = Typecho_Db::get();
            $row = $db->fetchRow($db->select('views')->from('table.contents')->where('cid = ?', $cid));

            // 更新数据
            $cache->views = $row['views'] + 1;
            $db->query($db->update('table.contents')->rows(['views' => $cache->views])->where('cid = ?', $cid));
            self::putCache($cid, $cache);
        }

        $views = $cache->views;
        if ($format) {
            $views = self::formatNumber($views);
        }

        if ($display) {
            echo $views;
            return;
        }
        return $views;
    }

    /**
     * 获取文章点赞状态
     * @param int $cid 文章ID
     * @return string|null 点赞状态
     */
    public static function agreed($cid)
    {
        $agreed = Typecho_Cookie::get('agreed');
        if (empty($agreed)) {
            Typecho_Cookie::set('agreed', json_encode(array()));
        } else {
            $agreed = json_decode($agreed);
            if (in_array($cid, $agreed)) {
                return "disabled";
            }
        }
    }

    /**
     * 获取文章点赞数
     * @param int $cid 文章ID
     * @param bool $display 是否直接输出
     * @param bool $format 是否格式化
     * @return int|string 点赞数
     */
    public static function agree($cid, $display = true, $format = true)
    {
        $cache = self::getCache($cid);

        if (!$cache->agree) {
            $db = Typecho_Db::get();
            $row = $db->fetchRow($db->select('agree')->from('table.contents')->where('cid = ?', $cid));

            $cache->agree = $row['agree'];
            self::putCache($cid, $cache);
        }

        $agree = $cache->agree;
        if ($format) {
            $agree = self::formatNumber($agree);
        }

        if ($display) {
            echo $agree;
            return;
        }
        return $agree;
    }

    /**
     * 更新文章点赞数
     * @param int $cid 文章ID
     * @return int 更新后的点赞数
     */
    public static function updateAgree($cid)
    {

        //判断用户是否已点赞
        $agree = self::agreed($cid);
        if ($agree === "disabled") {
            return;
        }

        // 获取文章当前点赞数
        $db = Typecho_Db::get();
        $cache = self::getCache($cid);
        if (!$cache->agree) {
            $row = $db->fetchRow($db->select('agree')->from('table.contents')->where('cid = ?', $cid));
            $cache->agree = $row['agree'] + 1;
        } else {
            $cache->agree = $cache->agree + 1;
        }

        // 更新数据
        $db->query($db->update('table.contents')->rows(['agree' => $cache->agree])->where('cid = ?', $cid));
        self::putCache($cid, $cache);

        // 记录点赞状态
        $agreed = Typecho_Cookie::get('agreed');
        $agreed = json_decode($agreed);
        array_push($agreed, $cid);
        Typecho_Cookie::set('agreed', json_encode($agreed));
        return $cache->agree;
    }

    /**
     * 输出浏览量排名前n的文章列表
     * @param int $limit 显示数量
     * @param int $cacheTime 缓存时间（秒）
     */
    public static function ranked($limit = 5, $cacheTime = 3600)
    {
        $cacheKey = 'mostViewed';
        $limit = max(1, min(20, intval($limit))); // 限制范围在1-20之间

        // 尝试获取缓存
        $cachedData = Typecho_Cookie::get($cacheKey);
        if ($cachedData) {
            self::printRankedPosts(explode('.', $cachedData));
            return;
        }

        // 获取排名前N的文章
        $db = Typecho_Db::get();
        $posts = $db->fetchAll($db->select('cid')
            ->from('table.contents')
            ->where('type = ? AND status = ? AND password IS NULL', 'post', 'publish')
            ->order('views', Typecho_Db::SORT_DESC)
            ->limit($limit));

        // 提取文章ID并处理
        $cids = $posts ? array_column($posts, 'cid') : [];
        if (!empty($cids)) {
            self::printRankedPosts($cids);
            Typecho_Cookie::set($cacheKey, implode('.', $cids), time() + $cacheTime);
        }
    }

    /**
     * 输出排行榜文章列表
     * @param array $cids 文章ID数组
     * @return void
     */
    private static function printRankedPosts($cids)
    {
        if (empty($cids)) {
            return;
        }

        foreach ($cids as $cid) {
            $item = Typecho_Widget::widget("Widget_Archive@post-$cid", "pageSize=1&type=post", "cid=$cid");
            if ($item->have()) {
                $url = $item->fields->url ?: $item->permalink;
                $title = $item->title;
                $subtitle = $item->fields->text ? ' - ' . $item->fields->text : '';

                echo sprintf(
                    self::RANKED_ITEM_TEMPLATE,
                    $title . $subtitle,
                    $url,
                    $cid,
                    $item->fields->text
                );
            }
        }
    }

    public static function timeago($timestamp)
    {
        $diff = time() - $timestamp;
        $units = array(
            '年前' => 31536000,
            '个月前' => 2592000,
            '天前' => 86400,
            '小时前' => 3600,
            '分钟前' => 60,
            '秒前' => 1,
        );
        foreach ($units as $unit => $value) {
            if ($diff >= $value) {
                $time = floor($diff / $value);
                return $time . $unit;
            }
        }
    }

    public static function favicon($posts)
    {
        $logo = $posts->fields->logo;
        $url = $posts->fields->url;
        if (empty($logo) && $url) {
            $logo = 'https://favicon.im/' . parse_url($url, PHP_URL_HOST);
        }
        return $logo;
    }

    public static function printStars($score)
    {
        $score = max(0, min(5, $score));

        $fullStars = floor($score);
        $halfStars = ($score - $fullStars) >= 0.5 ? 1 : 0;
        $emptyStars = 5 - $fullStars - $halfStars;

        $stars = '';
        for ($i = 0; $i < $fullStars; $i++) {
            $stars .= '<i class="fas fa-star" style="color: #FFD43B;"></i>';
        }

        if ($halfStars) {
            $stars .= '<i class="fas fa-star-half-alt" style="color: #FFD43B;"></i>';
        }

        for ($i = 0; $i < $emptyStars; $i++) {
            $stars .= '<i class="far fa-star" style="color: #FFD43B;"></i>';
        }

        echo $stars;
    }

    /**
     * 格式化数字（大于1000转化为K，大于1000000转化为M）
     * @param int $number 要格式化的数字
     * @return string|int 格式化后的数字
     */
    public static function formatNumber($number)
    {
        if (!is_numeric($number)) {
            return $number;
        }

        $number = intval($number);
        if ($number >= 1000000) {
            return round($number / 1000000, 1) . 'M';
        } else if ($number >= 1000) {
            return round($number / 1000, 1) . 'K';
        }
        return $number;
    }

    /**
     * 生成分页导航
     * @param string $baseUrl 基础URL
     * @param int $currentPage 当前页码
     * @param int $totalPages 总页数
     * @return string 分页HTML
     */
    public static function pagination($baseUrl, $currentPage, $totalPages)
    {
        $html = '<nav class="navigation pagination" aria-label="Posts Navigation"><div class="nav-links">';
        if ($currentPage > 1) {
            $html .= '<a class="prev page-numbers" href="' . $baseUrl . ($currentPage - 1) . '">上一页</a>';
        }

        for ($i = 1; $i <= $totalPages; $i++) {
            if ($i == $currentPage) {
                $html .= '<span aria-current="page" class="page-numbers current">' . $i . '</span>';
            } else {
                if ($i == 1 || $i == $totalPages || ($i >= $currentPage - 2 && $i <= $currentPage + 2)) {
                    $html .= '<a class="page-numbers" href="' . $baseUrl . $i . '">' . $i . '</a>';
                } elseif ($i == $currentPage - 3 || $i == $currentPage + 3) {
                    $html .= '<span class="page-numbers dots">...</span>';
                }
            }
        }

        if ($currentPage < $totalPages) {
            $html .= '<a class="next page-numbers" href="' . $baseUrl . ($currentPage + 1) . '">下一页</a>';
        }

        $html .= '</div></nav>';
        return $html;
    }

    /**
     * 分页查询文章
     * @param int $pageSize 每页文章数
     * @param int $currentPage 当前页码
     * @return array 包含文章cid列表和分页信息的数组
     */
    public static function page($pageSize, $currentPage)
    {
        $db = Typecho_Db::get();
        $totalPosts = $db->fetchObject($db->select(array('COUNT(cid)' => 'num'))
            ->from('table.contents')
            ->where('type = ?', 'post')
            ->where('status = ?', 'publish')
            ->order('modified', Typecho_Db::SORT_DESC))->num;

        $totalPages = ceil($totalPosts / $pageSize);
        if ($currentPage > $totalPages) {
            $currentPage = $totalPages;
        } elseif ($currentPage < 1) {
            $currentPage = 1;
        }

        $offset = ($currentPage - 1) * $pageSize;
        $result = $db->fetchAll($db->select('cid')
            ->from('table.contents')
            ->where('type = ?', 'post')
            ->where('status = ?', 'publish')
            ->order('modified', Typecho_Db::SORT_DESC)
            ->limit($pageSize)
            ->offset($offset));

        return array(
            'data' => array_column($result, 'cid'),
            'currentPage' => $currentPage,
            'totalPages' => $totalPages
        );
    }
}

/**
 * 文章统计数据缓存类
 */
class Cache
{
    public $views;
    public $agree;

    public function __construct($views = 0, $agree = 0)
    {
        $this->views = $views;
        $this->agree = $agree;
    }
}
