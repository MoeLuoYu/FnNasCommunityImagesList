<?php
// 引入Parsedown解析库
require_once '../include/Parsedown.php';

class MarkdownRenderer
{
    // 配置项
    private $mdUrl = 'https://raw.githubusercontent.com/ophub/fnnas/refs/heads/main/README.md';
    private $cacheDir = __DIR__ . '/cache';
    private $cacheFile = __DIR__ . '/cache/readme_content.md';
    private $cacheMetaFile = __DIR__ . '/cache/meta.json';
    private $updateInterval = 3600;
    
    private $imgReplaceMap = [
        'https://github.com/user-attachments/assets/ea86c39b-4ed6-4f14-b7e6-bc551b495e39' => 'https://youke3.picui.cn/s1/2026/01/10/69614cce74986.jpg'
    ];

    // 提示框替换映射
    private $alertReplaceMap = [
        '[!TIP]' => '💡 Tip',
        '[!IMPORTANT]' => '❗ Important'
    ];

    // 初始化
    public function __construct()
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * 核心渲染方法
     * @return string 渲染后的HTML内容
     */
    public function render()
    {
        if ($this->needUpdate()) {
            $this->fetchAndUpdateCache();
        }

        if (file_exists($this->cacheFile)) {
            $markdownContent = file_get_contents($this->cacheFile);
            // 第一步：替换提示框关键词（在解析前处理Markdown原文）
            $markdownContent = $this->replaceAlertKeywords($markdownContent);
            $parsedHtml = $this->parseMarkdown($markdownContent);
            // 第二步：给提示框添加HTML类名（解析后处理HTML）
            $parsedHtml = $this->addAlertClasses($parsedHtml);
            return $this->wrapPageStyle($parsedHtml);
        }

        return $this->wrapPageStyle('<div style="color: red; text-align: center; margin: 50px; padding: 20px; border: 1px solid #ffcccc; border-radius: 8px;">无法获取Markdown内容且无缓存可用</div>');
    }

    /**
     * 替换Markdown中的提示框关键词
     * @param string $content 原始Markdown内容
     * @return string 替换后的内容
     */
    private function replaceAlertKeywords($content)
    {
        foreach ($this->alertReplaceMap as $old => $new) {
            $content = str_replace($old, $new, $content);
        }
        return $content;
    }

    /**
     * 仅剥离P标签，提示框样式由Parsedown.php内部原生实现
     */
    private function addAlertClasses($html)
    {
        // 匹配 💡 Tip 开头的块级内容，只移除p标签
        $html = preg_replace('/<p>(💡 Tip.*?)<\/p>/s', '<b>$1</b>', $html);
        // 匹配 ❗ Important 开头的块级内容，只移除p标签
        $html = preg_replace('/<p>(❗ Important.*?)<\/p>/s', '<b>$1</b>', $html);
        return $html;
    }

    /**
     * 判断是否需要更新缓存
     * @return bool
     */
    private function needUpdate()
    {
        if (!file_exists($this->cacheFile) || !file_exists($this->cacheMetaFile)) {
            return true;
        }

        $meta = json_decode(file_get_contents($this->cacheMetaFile), true);
        if (!$meta || !isset($meta['last_check_time'])) {
            return true;
        }

        return (time() - $meta['last_check_time']) >= $this->updateInterval;
    }

    private function fetchAndUpdateCache()
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->mdUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'User-Agent: PHP-Markdown-Renderer/1.0',
                $this->getIfNoneMatchHeader()
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        if ($httpCode == 200) {
            foreach ($this->imgReplaceMap as $oldUrl => $newUrl) {
                if (!empty($oldUrl) && !empty($newUrl)) {
                    $body = str_replace($oldUrl, $newUrl, $body);
                }
            }
            
            $body = $this->replaceAlertKeywords($body);
            
            file_put_contents($this->cacheFile, $body);
            
            $meta = [
                'last_check_time' => time(),
                'etag' => $this->extractEtagFromHeaders($headers),
                'last_update_time' => time()
            ];
            file_put_contents($this->cacheMetaFile, json_encode($meta, JSON_PRETTY_PRINT));
        } elseif ($httpCode == 304) {
            $meta = json_decode(file_get_contents($this->cacheMetaFile), true);
            $meta['last_check_time'] = time();
            file_put_contents($this->cacheMetaFile, json_encode($meta, JSON_PRETTY_PRINT));
        }
    }

    /**
     * 从响应头提取ETag
     * @param string $headers
     * @return string|null
     */
    private function extractEtagFromHeaders($headers)
    {
        $etag = null;
        $headerLines = explode("\r\n", $headers);
        foreach ($headerLines as $line) {
            if (stripos($line, 'ETag:') === 0) {
                $etag = trim(substr($line, strlen('ETag:')));
                break;
            }
        }
        return $etag;
    }

    /**
     * 构建If-None-Match请求头
     * @return string
     */
    private function getIfNoneMatchHeader()
    {
        if (!file_exists($this->cacheMetaFile)) {
            return '';
        }
        $meta = json_decode(file_get_contents($this->cacheMetaFile), true);
        return $meta && isset($meta['etag']) ? "If-None-Match: {$meta['etag']}" : '';
    }

    /**
     * 解析Markdown
     * @param string $content
     * @return string
     */
    private function parseMarkdown($content)
    {
        $parsedown = new Parsedown();
        $parsedown->setMarkupEscaped(false)
                  ->setUrlsLinked(true)
                  ->setBreaksEnabled(true)
                  ->setSafeMode(false);
        
        $parsedHtml = $parsedown->text($content);
        $parsedHtml = htmlspecialchars_decode($parsedHtml, ENT_QUOTES);
        
        return $parsedHtml;
    }

    private function wrapPageStyle($content)
    {
        $updateTime = '未知';
        if (file_exists($this->cacheMetaFile)) {
            $meta = json_decode(file_get_contents($this->cacheMetaFile), true);
            if ($meta && isset($meta['last_update_time'])) {
                $updateTime = date('Y-m-d H:i:s', $meta['last_update_time']);
            }
        }

        $processing_time = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
        $current_time_gmt8 = date('Y-m-d H:i', time());
        $cache_update_time = 'Unknown';
        $cache_key = 'all_releases_ophub_fnnas';
        $cache_file = __DIR__.'/cache/'.$cache_key;
        if (file_exists($cache_file)) {
            $cache_update_time = date('Y-m-d H:i:s', filemtime($cache_file));
        }
        $footer_info = 'Current time GMT+8, ' . $current_time_gmt8 . ', Updated at ' . $updateTime . ', Processed in ' . number_format($processing_time, 6) . ' second(s).';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FNNAS English README</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif; line-height: 1.8; color: #333; background-color: #f8f9fa; word-wrap: break-word; word-break: break-all; overflow-wrap: break-word; }
        .header { background-color: #2c3e50; color: white; padding: 16px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1); position: sticky; top: 0; z-index: 100; }
        .header-container { max-width: 1200px; margin: 0 auto; padding: 0 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        .header-title { font-size: 20px; font-weight: 600; }
        .update-info { font-size: 14px; color: #ddd; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .content-wrapper { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 2px 20px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .markdown-content { width: 100%; max-width: 100%; word-wrap: break-word; word-break: break-all; overflow-wrap: break-word; }
        .markdown-content h1, h2, h3, h4, h5, h6 { color: #2c3e50; margin: 24px 0 16px; font-weight: 600; line-height: 1.2; }
        .markdown-content h1 { font-size: 28px; border-bottom: 2px solid #e9ecef; padding-bottom: 12px; margin-top: 0; }
        .markdown-content h2 { font-size: 24px; border-bottom: 1px solid #e9ecef; padding-bottom: 8px; }
        .markdown-content p, .markdown-content ul, .markdown-content ol, .markdown-content li, .markdown-content blockquote { margin: 16px 0; font-size: 16px; word-wrap: break-word; word-break: break-word; overflow-wrap: break-word; }
        .markdown-content a { color: #007bff; text-decoration: none; }
        .markdown-content a:hover { color: #0056b3; text-decoration: underline; }
        .markdown-content ul, ol { padding-left: 24px; }
        .markdown-content code { background-color: #f8f9fa; padding: 2px 6px; border-radius: 4px; font-family: "SFMono-Regular", Menlo, Monaco, Consolas, monospace; font-size: 14px; color: #e83e8c; }
        .markdown-content pre { background-color: #f8f9fa; padding: 16px; border-radius: 8px; overflow-x: auto; margin: 16px 0; white-space: pre-wrap; word-wrap: break-word; }
        .markdown-content pre code { background: none; padding: 0; color: #333; }
        .markdown-content blockquote { border-left: 4px solid #dee2e6; padding: 8px 16px; background-color: #f8f9fa; color: #6c757d; }
        .markdown-content hr { border: none; border-top: 1px solid #e9ecef; margin: 32px 0; }
        .markdown-content table { width: 100%; min-width: 100%; border-collapse: collapse; margin: 24px 0; font-size: 16px; display: block; overflow-x: auto; }
        .markdown-content th, .markdown-content td { padding: 12px 16px; border: 1px solid #dee2e6; text-align: left; }
        .markdown-content th { background-color: #f8f9fa; font-weight: 600; color: #2c3e50; }
        .markdown-content tr:nth-child(even) { background-color: #f8f9fa; }
        .markdown-content tr:hover { background-color: #eef2f7; }
        .markdown-content img { max-width: 100%; height: auto; border-radius: 8px; margin: 16px 0; display: block; }
        
        .alert {
            margin: 16px 0;
            padding: 16px;
            border-radius: 8px;
            border-left: 4px solid;
            font-size: 16px;
            line-height: 1.8;
            word-wrap: break-word;
            word-break: break-word;
        }
        .alert-tip {
            background-color: #f0f8fb;
            border-left-color: #4299e1;
            color: #2d3748;
        }
        .alert-important {
            background-color: #fef7fb;
            border-left-color: #e53e3e;
            color: #2d3748;
        }
        .alert-tip::before, .alert-important::before {
            font-weight: bold;
            margin-right: 8px;
        }
        
        @media (max-width: 768px) {
            .content-wrapper { padding: 20px 16px !important; }
            .markdown-content h1 { font-size: 24px; }
            .markdown-content h2 { font-size: 20px; }
            .markdown-content p, .markdown-content li { font-size: 15px; }
            .alert { padding: 12px; font-size: 14px; }
            .header-container { flex-direction: column; align-items: flex-start; }
        }
        .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 14px; border-top: 1px solid #e9ecef; margin-top: 20px; }
        .markdown-content * { box-sizing: inherit; }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-container">
            <div class="header-title">ophub/fnnas README_EN</div>
            <div class="update-info">Last Pulled Update {$updateTime} From <a href="https://github.com/ophub/fnnas/blob/main/README.md" target="_blank" class="gray-link">Github</a></div>
        </div>
    </div>
    <div class="container">
        <div class="content-wrapper markdown-content">
            {$content}
        </div>
    </div>
    <div class="footer" style="text-align: center;">
        <p class="p-all-gray">Powered By <a href="https://moeluoyu.xyz/ankimod" target="_blank">AnkiMod Framework</a></p>
        <p class="p-all-gray">{$footer_info}</p>
    </div>

    <style>
        .p-all-gray {
            color: #999;
        }
        .p-all-gray a {
            color: inherit;
            text-decoration: none;
        }

        .p-all-gray a:hover {
            color: #333;
            text-decoration: underline;
        }

        a.gray-link {
            color: #ccc;
            text-decoration: none;
        }
        a.gray-link:hover {
            color: #999;
            text-decoration: underline;
        }
        a.gray-link:visited {
            color: #888;
        }

        .gray-separator {
            color: #ccc;
        }
    </style>
    <script>
        console.log(" %c Powered By AnkiMod Framework %c v1.0.0 ", "color: #FFFFFF !important; background: #FF6666; padding:5px;", "background: #1c2b36; padding:5px;color:white !important");
        console.log(` %c
        　　　　　　　　　　　　　＿＿＿　　 ~ヽ
        　　　　　　，‘　 ...::::::::::::::::::::::::::｀丶
         　 　 　 　 ／::::::::::::::::::::::::::::::::::::::::::::::＼　’
        　　　　　 /:::::::::/|:::∧:::|Χ:::::::::::::::::::::::::::.　；
               ｛　 |:::::: /＼/　'V⌒Y＼ :::::::::::::::: |
                ；　N:::ｲ,'⌒}　　{　　|　 |::::::::::::::::: |　｝
        　　　　　　| ::| ､_,ﾉ　　 ､__ﾉ　 |::::::::::::::::: |
        　      :　　|::ﾘ　　　　　　 　 ｕ|::::::::::::::::: |　{
          　 　 ｝ 　|:::＞ ゝ,　＿＿_）│::::::/:∧:::|
        　　　　　　∨∨∨ﾚ:ｧャ　ア |人〃⌒∨　:
            　 　 ~''　　　 　 人_{／／／ﾍ（⌒) ）．
        　　　　　　　　　　/　〈__>ｰく　 　 ﾏ二二7
         　 ，'~　　　｀；　/│/　|　~｀∨　　Y⌒)ヽ
        　　　　 (ヽ　　 〈ーl/　(⌒ヽ ├ー‐仁＿ﾉ ，
          ｛　（￣　　ｰ-/￣|　　,>､　　ｰ＜｀ＹV　 ノ
           ' 　 ｀ー- 、　｀　|＼/　 丶、　 　 |│　；
        　 　 　 　 　 ＼ 　_!　　　　　 ＼　　|│　;
            不……不要看啦……
        `, 'font-family: "MS PGothic", "ＭＳ Ｐゴシック", "Trebuchet MS", Verdana, Futura, Arial, Helvetica, sans-serif;line-height: 1;font-size: 12pt;');
    </script>
</body>
</html>
HTML;

        return $html;
    }
}

// 实例化并渲染
$renderer = new MarkdownRenderer();
echo $renderer->render();
?>