<?php
/**
 * FNNAS Releases File List and Proxy
 * 遍历FNNAS最新发布文件列表并代理下载
 */

// 设置内容类型为HTML
header('Content-Type: text/html; charset=utf-8');

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 缓存设置
//define('CACHE_DIR', sys_get_temp_dir() . '/github_cache');
define('CACHE_DIR', __DIR__ . '/cache');
define('CACHE_DURATION', 1 * 3600); // 1小时，以秒为单位

// 访问量和下载量统计目录
define('STATS_DIR', __DIR__ . '/stats');
if (!is_dir(STATS_DIR)) {
    mkdir(STATS_DIR, 0755, true);
}

// 记录网站访问量
function recordVisit() {
    $visits_file = STATS_DIR . '/visits.txt';
    $current_count = 0;

    if (file_exists($visits_file)) {
        $current_count = (int)file_get_contents($visits_file);
    }

    $current_count++;
    file_put_contents($visits_file, $current_count);

    return $current_count;
}

// 获取网站总访问量
function getTotalVisits() {
    $visits_file = STATS_DIR . '/visits.txt';
    if (file_exists($visits_file)) {
        return (int)file_get_contents($visits_file);
    }
    return 0;
}

// 记录下载量
function recordDownload($asset_name) {
    $downloads_file = STATS_DIR . '/downloads.txt';
    $current_count = 0;

    if (file_exists($downloads_file)) {
        $current_count = (int)file_get_contents($downloads_file);
    }

    $current_count++;
    file_put_contents($downloads_file, $current_count);

    return $current_count;
}

// 获取网站总下载量
function getTotalDownloads() {
    $downloads_file = STATS_DIR . '/downloads.txt';
    if (file_exists($downloads_file)) {
        return (int)file_get_contents($downloads_file);
    }
    return 0;
}

// 获取当前访问者IP
function getCurrentIP() {
    $ip = '';

    // 检查各种可能包含真实IP的HTTP头
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // 如果有X-Forwarded-For头，取第一个IP（客户端原始IP）
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }

    // 验证IP格式
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    } else {
        return 'Unknown';
    }
}

// 获取当前访问者IP
$currentIP = getCurrentIP();

// 仅在非AJAX请求时记录访问量，避免双倍计数
if (!isset($_GET['action']) || $_GET['action'] !== 'get_github_data') {
    // 记录本次访问
    $totalVisits = recordVisit();
}

// 确保缓存目录存在
if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

// 获取缓存文件路径
function getCacheFilePath($key) {
    return CACHE_DIR . '/' . md5($key) . '.cache';
}

// 检查缓存是否有效
function isCacheValid($key) {
    $cache_file = getCacheFilePath($key);
    if (!file_exists($cache_file)) {
        return false;
    }

    $cache_time = filemtime($cache_file);
    return (time() - $cache_time) < CACHE_DURATION;
}

// 检查缓存是否存在（无论是否过期）
function cacheExists($key) {
    $cache_file = getCacheFilePath($key);
    return file_exists($cache_file);
}

// 读取缓存
function readCache($key) {
    if (isCacheValid($key)) {
        $cache_file = getCacheFilePath($key);
        return json_decode(file_get_contents($cache_file), true);
    }
    return null;
}

// 写入缓存
function writeCache($key, $data) {
    $cache_file = getCacheFilePath($key);
    file_put_contents($cache_file, json_encode($data));
}

// 检查是否需要强制刷新缓存
// 为了防止滥用，请设置刷新密钥
if (isset($_GET['refresh']) && $_GET['refresh] === '请在这里设置刷新密钥') {
    // 清除所有缓存
    $files = glob(CACHE_DIR . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    // 重定向到首页，不带刷新参数
    header('Location: /');
    exit;
}

// 检查是否是AJAX请求获取GitHub数据
if (isset($_GET['action']) && $_GET['action'] === 'get_github_data') {
    header('Content-Type: application/json');

    $tag = isset($_GET['tag']) ? $_GET['tag'] : 'latest';

    // GitHub API URL for FNNAS releases
    if ($tag === 'latest') {
        $api_url = 'https://api.github.com/repos/ophub/fnnas/releases/latest';
    } else {
        $api_url = 'https://api.github.com/repos/ophub/fnnas/releases/tags/' . urlencode($tag);
    }

    // 获取当前版本的发布数据
    $releases_data = getReleasesData($api_url);

    // 检查API请求是否成功
    if (!$releases_data) {
        echo json_encode(['error' => '无法获取发布信息，请稍后重试。', 'releases' => []]);
        exit;
    } else {
        $assets = getAssetsList($releases_data);

        // 计算每个资产的下载次数
        foreach ($assets as &$asset) {
            $asset['download_count'] = $asset['download_count'] ?? 0;
        }

        // 获取所有发布版本用于选择
        // 如果请求的是latest版本，强制更新缓存以确保获取最新数据
        $all_releases = getAllReleases($tag === 'latest');

        if (empty($all_releases)) {
            $all_releases = [];
        }

        echo json_encode([
            'release_data' => $releases_data,
            'assets' => array_values($assets), // 重新索引数组
            'all_releases' => $all_releases,
            'error' => null
        ]);
        exit;
    }
}

// 处理下载记录请求
if (isset($_GET['action']) && $_GET['action'] === 'record_download') {
    header('Content-Type: application/json');

    if (isset($_GET['asset_name'])) {
        $asset_name = $_GET['asset_name'];
        $new_count = recordDownload($asset_name);

        echo json_encode([
            'success' => true,
            'message' => '下载记录已保存',
            'new_count' => $new_count,
            'asset_name' => $asset_name
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => '缺少asset_name参数'
        ]);
    }
    exit;
}

// 获取所有发布版本（带缓存）
function getAllReleases($force_update = false) {
    $cache_key = 'all_releases_ophub_fnnas';
    $cache_summary_key = 'all_releases_summary_ophub_fnnas'; // 用于存储摘要信息的缓存键

    // 检查缓存
    $cached_data = readCache($cache_key);
    if ($cached_data !== null && !$force_update) {
        // 检查是否超过1小时，如果超过则进行智能更新检查
        $cache_file = getCacheFilePath($cache_key);
        $cache_time = filemtime($cache_file);
        $time_since_cache = time() - $cache_time;

        // 如果缓存未过期（1小时内），直接返回缓存数据
        if ($time_since_cache < CACHE_DURATION) {
            return $cached_data;
        }

        // 缓存已过期，进行智能更新检查
        $should_update = shouldUpdateCache($cache_summary_key);
        if (!$should_update) {
            // 摘要信息没有变化，返回缓存数据但更新缓存时间
            writeCache($cache_key, $cached_data); // 更新缓存时间
            return $cached_data;
        }
    }

    $url = 'https://api.github.com/repos/ophub/fnnas/releases';

    // 使用cURL获取数据
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 如果API请求失败，尝试返回过期的缓存
    if ($response === false || $httpCode !== 200) {
        if (cacheExists($cache_key)) {
            $cache_file = getCacheFilePath($cache_key);
            $data = json_decode(file_get_contents($cache_file), true);
            return is_array($data) ? $data : [];
        }
        return [];
    }

    $data = json_decode($response, true);
    $result = is_array($data) ? $data : [];

    // 获取真正的latest release来确定哪个版本是标记为latest的
    $latest_release = getReleasesData('https://api.github.com/repos/ophub/fnnas/releases/latest');
    $latest_tag_name = isset($latest_release['tag_name']) ? $latest_release['tag_name'] : null;

    // 将latest版本的信息也保存到结果中，便于前端识别
    if ($latest_tag_name) {
        foreach ($result as &$release) {
            if ($release['tag_name'] === $latest_tag_name) {
                $release['is_latest_tag'] = true;
            } else {
                $release['is_latest_tag'] = false;
            }
        }
    }

    // 写入缓存
    writeCache($cache_key, $result);

    // 创建并保存摘要信息用于后续比较
    $summary = createReleasesSummary($result);
    writeCache($cache_summary_key, $summary);

    return $result;
}

// 创建发布版本摘要信息用于缓存比较
function createReleasesSummary($releases) {
    $summary = [];
    foreach ($releases as $release) {
        $summary[] = [
            'tag_name' => $release['tag_name'],
            'name' => $release['name'],
            'published_at' => $release['published_at'],
            'draft' => $release['draft'],
            'prerelease' => $release['prerelease'],
            'is_latest_tag' => $release['is_latest_tag'] ?? false,
            'assets_count' => count($release['assets'] ?? [])
        ];
    }
    return $summary;
}

// 检查是否需要更新缓存
function shouldUpdateCache($cache_summary_key) {
    $url = 'https://api.github.com/repos/ophub/fnnas/releases';

    // 使用cURL获取最新的摘要信息
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 如果API请求失败，返回false（不更新缓存）
    if ($response === false || $httpCode !== 200) {
        return false;
    }

    $data = json_decode($response, true);
    $latest_summary = createReleasesSummary(is_array($data) ? $data : []);

    // 读取本地缓存的摘要
    $cached_summary = readCache($cache_summary_key);

    // 比较摘要信息
    if ($cached_summary === null) {
        // 如果没有缓存的摘要，则需要更新
        return true;
    }

    // 比较两个摘要数组是否相同
    return json_encode($latest_summary) !== json_encode($cached_summary);
}

// 根据标签获取发布数据（带缓存）

function getReleasesData($url) {
    $cache_key = 'release_data_' . md5($url);

    // 检查缓存
    $cached_data = readCache($cache_key);
    if ($cached_data !== null) {
        return $cached_data;
    }

    // 使用cURL获取数据，更可靠
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 如果API请求失败，尝试返回过期的缓存
    if ($response === false || $httpCode !== 200) {
        if (cacheExists($cache_key)) {
            $cache_file = getCacheFilePath($cache_key);
            return json_decode(file_get_contents($cache_file), true);
        }
        return null;
    }

    $result = json_decode($response, true);

    // 写入缓存
    writeCache($cache_key, $result);

    return $result;
}

// 获取非源码包的资产列表
function getAssetsList($release_data) {
    if (!isset($release_data['assets']) || !is_array($release_data['assets'])) {
        return [];
    }

    $valid_assets = [];
    foreach ($release_data['assets'] as $asset) {
        $name = strtolower($asset['name']);
        // 排除源码包（包含src或source）和压缩包格式的源码
        if (!preg_match('/(src|source)/', $name) && !preg_match('/\.(zip|tar\.gz)$/', $name)) {
            $valid_assets[] = $asset;
        }
    }
    return $valid_assets;
}

// 获取GitHub仓库信息（带缓存）
function getGithubRepoInfo() {
    $cache_key = 'repo_info_ophub_fnnas';

    // 检查缓存
    $cached_data = readCache($cache_key);
    if ($cached_data !== null) {
        return $cached_data;
    }

    $url = 'https://api.github.com/repos/ophub/fnnas';

    // 使用cURL获取数据
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return null;
    }

    $result = json_decode($response, true);

    // 写入缓存
    writeCache($cache_key, $result);

    return $result;
}

// 从缓存中获取特定版本的数据
function getCachedReleaseData($tag) {
    $cache_key = 'release_' . $tag . '_ophub_fnnas';

    // 检查缓存
    $cached_data = readCache($cache_key);
    if ($cached_data !== null) {
        return $cached_data;
    }

    // 如果缓存不存在，尝试获取数据并缓存
    if ($tag === 'latest') {
        $api_url = 'https://api.github.com/repos/ophub/fnnas/releases/latest';
    } else {
        $api_url = 'https://api.github.com/repos/ophub/fnnas/releases/tags/' . urlencode($tag);
    }

    $data = getReleasesData($api_url);
    if ($data) {
        writeCache($cache_key, $data);
    }

    return $data;
}

// 获取当前标签用于初始化页面
$current_tag = isset($_GET['tag']) ? $_GET['tag'] : 'latest';

// 如果URL参数为'latest'，则从所有发布版本中获取GitHub API标记的latest版本
if ($current_tag === 'latest') {
    $all_releases = getAllReleases();
    $latest_release = null;

    // 从所有发布版本中找到标记为latest的版本
    foreach ($all_releases as $release) {
        if (isset($release['is_latest_tag']) && $release['is_latest_tag']) {
            $latest_release = $release;
            break;
        }
    }

    // 如果找到了latest版本，使用它的tag_name
    if ($latest_release && isset($latest_release['tag_name'])) {
        $current_tag = $latest_release['tag_name'];
    }
}

// 从缓存中获取当前版本的发布数据
$current_release_data = getCachedReleaseData($current_tag);
$current_assets = $current_release_data ? getAssetsList($current_release_data) : [];
?>

    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>飞牛ARM社区版镜像列表 - Powered by AnkiMod Framework</title>
        <link rel="icon" href="https://toolb.cn/favicon/fnnas.com" type="image/x-icon">
        <script>console.warn = function () { };</script>
        <script>console.error = function () { };</script>
        <meta name="description" content="<?php
        $repo_info = getGithubRepoInfo();
        echo isset($repo_info['description']) ? htmlspecialchars($repo_info['description'], ENT_QUOTES, 'UTF-8') : '来自社区发布的飞牛ARM版镜像列表。';
        ?>" />
        <meta name="keywords" content="ophub, fnnas, 镜像, 下载, ARM64, ARMv7, FnOS, FnNas, 飞牛私有云, 飞牛NAS, 飞牛OS, 飞牛ARM" />
        <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
        <style>
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                margin: 0;
                padding: 20px;
                background-color: #f5f5f5;
                color: #333;
            }

            .container {
                max-width: 1200px;
                margin: 0 auto;
                background: white;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                padding: 20px;
            }

            h1 {
                color: #2c3e50;
                padding-bottom: 10px;
                margin: 0;
                flex: 1;
            }

            .header-underline {
                height: 2px;
                background-color: #3498db;
                margin: 10px 0;
            }

            .version-selector {
                margin: 20px 0;
            }

            .version-selector select {
                padding: 8px 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 16px;
            }

            .release-info {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 5px;
                margin: 20px 0;
                border-left: 4px solid #3498db;
            }
            .release-error {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 5px;
                margin: 20px 0;
                border-left: 4px solid #e74c3c;
            }
            .release-warning {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 5px;
                margin: 20px 0;
                border-left: 4px solid #f39c12;
            }

            .file-list {
                margin-top: 20px;
            }

            .file-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 15px;
                border: 1px solid #eee;
                border-radius: 5px;
                margin-bottom: 10px;
                background: white;
                transition: box-shadow 0.3s ease;
            }

            .file-item:hover {
                box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            }

            .file-info {
                flex-grow: 1;
            }

            .file-name {
                font-weight: bold;
                color: #2c3e50;
                display: block;
                word-wrap: break-word;
                word-break: break-all;
                overflow-wrap: break-word;
            }

            .file-size, .download-count {
                font-size: 0.9em;
                color: #7f8c8d;
                margin-top: 5px;
            }

            .download-buttons {
                display: flex;
                gap: 8px;
            }

            .download-btn {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                padding: 6px 12px;
                border-radius: 6px;
                font-size: 13px;
                font-weight: 500;
                transition: all 0.2s ease;
                text-decoration: none;
                color: white;
            }

            .download-btn.original {
                background: linear-gradient(135deg, #333 0%, #555 100%);
            }

            .download-btn.proxy {
                background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
            }

            .download-btn.local-proxy {
                background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
            }

            .download-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            }

            .download-btn i {
                margin-right: 15px;
            }

            .download-btn span {
                vertical-align: 25%;
            }

            .github-btn {
                display: inline-block;
                padding: 5px 10px;
                background-color: #333;
                color: white;
                text-decoration: none;
                border-radius: 4px;
                transition: background-color 0.3s ease;
                font-size: 16px;
                margin-left: 10px;
                vertical-align: -20%;
            }

            .github-btn:hover {
                background-color: #555;
                color: white;
            }

            .github-btn i {
                margin-right: 5px;
            }

            .github-btn span {
                vertical-align: 15%;
            }

            .readme-btn {
                display: inline-block;
                padding: 5px 10px;
                background-color: #f0e68c;
                color: #333;
                text-decoration: none;
                border-radius: 4px;
                transition: background-color 0.3s ease;
                font-size: 16px;
                margin-left: 10px;
                vertical-align: -20%;
            }

            .readme-btn:hover {
                background-color: #d2c270;
                color: #333;
            }

            .readme-btn i {
                margin-right: 5px;
            }

            .readme-btn span {
                vertical-align: 15%;
            }

            .search-container {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin: 20px 0;
            }

            #searchInput {
                width: 300px;
                padding: 10px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 16px;
                margin-left: auto;
            }

            .loading {
                text-align: center;
                padding: 40px;
                font-size: 18px;
                color: #7f8c8d;
            }

            .error {
                text-align: center;
                padding: 40px;
                color: #e74c3c;
                background-color: #fdeded;
                border: 1px solid #f5c6cb;
                border-radius: 5px;
                margin: 10px 0;
            }

            .no-files {
                text-align: center;
                padding: 40px;
                color: #7f8c8d;
                font-style: italic;
            }

            .latest-badge {
                background-color: #27ae60;
                color: white;
                padding: 4px 8px;
                border-radius: 12px;
                font-size: 12px;
                margin-left: 10px;
                vertical-align: middle;
            }

            .file-count-badge {
                background-color: #3498db;
                color: white;
                padding: 4px 8px;
                border-radius: 12px;
                font-size: 12px;
                margin-left: 10px;
                vertical-align: middle;
            }

            .header-container {
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
            }

            .copyright-info {
                color: #7f8c8d;
                font-size: 0.9em;
                text-align: right;
                min-width: fit-content;
                overflow-wrap: break-word;
            }
            .logo {
                height: 72px; margin: 0px 20px 0 0;
            }

            @media screen and (max-width: 768px) {
                body {
                    padding: 10px;
                }

                .container {
                    padding: 15px;
                }

                .header-container {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 10px;
                }

                .copyright-info {
                    width: 100%;
                    text-align: left;
                    overflow-wrap: break-word;
                    font-size: 0.8em;
                }

                h1 {
                    font-size: 1.5em;
                }

                .search-container {
                    flex-direction: column;
                    align-items: stretch;
                    gap: 10px;
                }

                #searchInput {
                    width: 100%;
                    margin-left: 0;
                    box-sizing: border-box;
                }

                .file-item {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 10px;
                }

                .download-buttons {
                    width: 100%;
                    justify-content: space-between;
                    flex-wrap: wrap;
                }

                .download-btn {
                    flex: 1;
                    text-align: center;
                    margin-right: 5px;
                    min-width: 80px;
                    margin-bottom: 5px;
                }

                .download-btn:last-child {
                    margin-right: 0;
                }

                .ad-badge {
                    font-size: 10px;
                    padding: 2px 5px;
                    border-radius: 4px;
                }

                button[onclick*="closeAd"] {
                    width: 14px;
                    height: 14px;
                    font-size: 6px;
                    line-height: 14px;
                }
            }

            @media screen and (max-width: 480px) {
                body {
                    padding: 5px;
                }

                .container {
                    padding: 10px;
                }

                h1 {
                    font-size: 1.3em;
                }

                .version-selector {
                    display: flex;
                    flex-direction: column;
                    gap: 10px;
                    align-items: flex-start;
                }

                .version-selector select {
                    width: 100%;
                    padding: 10px;
                    box-sizing: border-box;
                }

                .github-btn {
                    align-self: flex-start;
                }

                .file-item {
                    padding: 10px;
                }

                .download-buttons {
                    flex-direction: column;
                    gap: 5px;
                }

                .download-btn {
                    flex: 1 1 auto;
                    margin-right: 0;
                    margin-bottom: 5px;
                    min-width: auto;
                    padding: 6px 8px;
                    font-size: 12px;
                }

                .download-btn:last-child {
                    margin-bottom: 0;
                }

                .release-info, .release-error {
                    padding: 10px;
                }

                footer {
                    font-size: 0.8em;
                }

                .ad-badge {
                    font-size: 8px;
                    padding: 1px 4px;
                    border-radius: 3px;
                }

                button[onclick*="closeAd"] {
                    width: 14px;
                    height: 14px;
                    font-size: 6px;
                    line-height: 14px;
                }
            }

            @media screen and (min-width: 481px) and (max-width: 992px) {
                .container {
                    max-width: 100%;
                    margin: 0 10px;
                }

                .version-selector {
                    display: flex;
                    flex-direction: column;
                    gap: 10px;
                    align-items: flex-start;
                }

                .version-selector select {
                    width: 100%;
                    padding: 10px;
                    box-sizing: border-box;
                }

                .github-btn {
                    align-self: flex-start;
                }

                .download-buttons {
                    flex-wrap: wrap;
                    gap: 5px;
                }

                .download-btn {
                    flex: 1 1 auto;
                    min-width: 80px;
                    margin-right: 5px;
                    margin-bottom: 5px;
                    text-align: center;
                }

                .ad-badge {
                    font-size: 10px;
                    padding: 2px 5px;
                    border-radius: 4px;
                }

                button[onclick*="closeAd"] {
                    width: 14px;
                    height: 14px;
                    font-size: 6px;
                    line-height: 14px;
                }
            }

            @media screen and (min-width: 993px) {
                .ad-badge {
                    font-size: 16px;
                    padding: 6px 12px;
                    border-radius: 6px;
                }

                button[onclick*="closeAd"] {
                    width: 20px;
                    height: 20px;
                    font-size: 10px;
                    line-height: 20px;
                }
            }
        </style>
    </head>
    <body>
    <a
      style="position: absolute; top: 0; right: 0"
      href="https://github.com/MoeLuoYu/FnNasCommunityImagesList"
      target="_blank"><img
        width="149"
        height="149"
        referrerpolicy="no-referrer"
        src="https://inews.gtimg.com/newsapp_ls/0/12025455907/0"
        alt="Fork me on GitHub"
        data-recalc-dims="1"
    /></a>
    <div class="container">
        <div class="header-container">
            <img src="https://static2.fnnas.com/official/fnos-logo.png" alt="FnNas Logo" class="logo">
            <h1>ophub/fnnas 镜像列表</h1>
            <div class="copyright-info">
                <p></p>
                <p>本镜像站仅用于学习和研究，不提供任何形式的技术支持，FnOS和FnNas均为 <a href="https://fnnas.com/" target="_blank">飞牛私有云</a> 的项目</p>
                <p>本镜像站由 <a href="https://github.com/MoeLuoYu" target="_blank">MoeLuoYu</a> 开发，页面内容为直接拉取Github Release列表并缓存整理展示，每 <?php echo CACHE_DURATION / 60 / 60; ?> 小时更新一次</p>
            </div>
        </div>
        <div class="header-underline"></div>
        <div class="release-error">
            <b>温馨提示：当前 FnOS On Arm 仍处于内测阶段，请您酌情下载！</b>
            <br>
            <b style="color: red;">如您不能承受任何因BUG所带来的风险，请勿刷机并关闭本页面！</b>
        </div>

        <div class="version-selector">
            <label for="versionSelect">选择Release版本:</label>
            <select id="versionSelect" onchange="changeVersion()" <?php echo empty($all_releases) ? 'disabled' : ''; ?>>
                <?php if (empty($all_releases)): ?>
                    <option value="">Loading...</option>
                <?php else: ?>
                    <?php
                    // 按发布时间排序版本（最新的在前）
                    $sorted_releases = $all_releases;
                    usort($sorted_releases, function($a, $b) {
                        return strtotime($b['published_at']) - strtotime($a['published_at']);
                    });

                    // 获取原始URL参数中的tag
                    $original_tag = isset($_GET['tag']) ? $_GET['tag'] : 'latest';

                    foreach ($sorted_releases as $release): ?>
                        <option value="<?php echo htmlspecialchars($release['tag_name']); ?>"
                            <?php
                            // 如果URL中的tag是'latest'，则选择GitHub API标记的latest版本
                            if ($original_tag === 'latest' && isset($release['is_latest_tag']) && $release['is_latest_tag']) {
                                echo 'selected';
                            } else if ($release['tag_name'] === $current_tag) {
                                echo 'selected';
                            }
                            ?>>
                            <?php
                            // 检查此版本是否是GitHub标记的latest版本
                            if (isset($release['is_latest_tag']) && $release['is_latest_tag']) {
                                echo htmlspecialchars($release['tag_name']) . ' (Latest)';
                            } else {
                                echo htmlspecialchars($release['tag_name']);
                            }
                            ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <a href="https://github.com/ophub/fnnas" target="_blank" class="github-btn"><i data-feather="github"></i><span>GitHub 原仓库地址</span></a>
            <a href="./readme" target="_blank" class="readme-btn"><i data-feather="book-open"></i><span>查看自述文件</span></a>
        </div>
        <?php if (isset($error_msg)): ?>
            <div class="error">
                <p><?php echo $error_msg; ?></p>
            </div>
        <?php else: ?>
            <div class="release-info">
                <h2>
                    <span id="releaseName"><?php echo $current_release_data ? htmlspecialchars($current_release_data['name'] ?? 'Unknown') : 'Loading...'; ?></span>
                    <span id="latestBadge" class="latest-badge" <?php
                    // 检查当前版本是否为GitHub标记的latest版本
                    $is_latest_version = false;
                    if (!empty($all_releases) && $current_release_data) {
                        foreach ($all_releases as $release) {
                            if ($release['tag_name'] === $current_release_data['tag_name'] && isset($release['is_latest_tag']) && $release['is_latest_tag']) {
                                $is_latest_version = true;
                                break;
                            }
                        }
                    }
                    echo $is_latest_version ? '' : 'style="display: none;"';
                    ?>>Latest</span>
                </h2>
                <p><strong>发布时间:</strong> <span id="releaseDate"><?php
                        if ($current_release_data && isset($current_release_data['published_at'])) {
                            echo date('Y-m-d H:i:s', strtotime($current_release_data['published_at']));
                        } else {
                            echo 'Loading...';
                        }
                        ?></span></p>
                <div id="releaseBody" class="release-description">
                    <?php
                    if ($current_release_data && isset($current_release_data['body'])) {
                        echo nl2br(htmlspecialchars($current_release_data['body']));
                    } else {
                        echo 'Loading...';
                    }
                    ?>
                </div>
            </div>

            <div class="search-container">
                <span style="font-size: 1.5em; font-weight: bold;">资源列表 <span id="fileCountBadge" class="file-count-badge" <?php echo empty($current_assets) ? 'style="display: none;"' : ''; ?>><?php echo count($current_assets); ?> 个项目</span> <span style="font-size: 0.5em;">本站仅做数据记录，不对下载可用性与文件内容做任何保证</span></span>
                <input type="text" id="searchInput" placeholder="搜索文件名..." class="search-input" <?php echo empty($current_assets) ? 'disabled' : ''; ?> />
            </div>
            <div class="file-list" id="fileList">
                <?php if (empty($current_assets)): ?>
                    <div class="loading">正在加载GitHub信息...</div>
                <?php else: ?>
                    <?php foreach ($current_assets as $asset): ?>
                        <div class="file-item" data-name="<?php echo strtolower(htmlspecialchars($asset['name'])); ?>">
                            <div class="file-info">
                                <span class="file-name"><?php echo htmlspecialchars($asset['name']); ?></span>
                                <span class="file-size"><?php echo formatFileSize($asset['size'] ?? 0); ?></span>
                                <span class="download-count">下载次数: <?php echo $asset['download_count'] ?? 0; ?></span>
                            </div>
                            <div class="download-buttons">
                                <a href="<?php echo htmlspecialchars($asset['browser_download_url']); ?>" class="download-btn original" target="_blank" onclick="recordDownloadClick('<?php echo htmlspecialchars($asset['name']); ?>')">
                                    <i data-feather="github"></i>
                                    <span>Github下载</span>
                                </a>
                                <a href="https://gh-proxy.org/<?php echo htmlspecialchars($asset['browser_download_url']); ?>" class="download-btn proxy" target="_blank" onclick="recordDownloadClick('<?php echo htmlspecialchars($asset['name']); ?>')">
                                    <i data-feather="send"></i>
                                    <span>GH-Proxy下载</span>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <footer style="text-align: center; margin-top: 20px;">
        <p class="p-all-gray">Powered By <a href="https://moeluoyu.xyz/ankimod" target="_blank">AnkiMod Framework</a> | <?php
            // 计算页面处理时间
            $processing_time = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
            // 获取当前时间（GMT+8）
            $current_time_gmt8 = date('Y-m-d H:i', time());
            // 获取缓存更新时间
            $cache_update_time = 'Unknown';
            $cache_key = 'all_releases_ophub_fnnas';
            $cache_file = getCacheFilePath($cache_key);
            if (file_exists($cache_file)) {
                $cache_update_time = date('Y-m-d H:i:s', filemtime($cache_file));
            }
            echo 'GMT+8, ' . $current_time_gmt8 . ', Updated at ' . $cache_update_time . ', Processed in ' . number_format($processing_time, 6) . ' second(s).';
            ?></p>
        <p class="p-all-gray">您当前访问IP：<?php echo $currentIP; ?> | 网站总访问量：<?php echo getTotalVisits(); ?> | 网站总下载量：<?php echo getTotalDownloads(); ?></p>
    </footer>

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
            color: #333;
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
        // 页面加载完成后立即显示基本结构
        document.addEventListener('DOMContentLoaded', function() {
            // 检查页面是否已有缓存数据
            const versionSelect = document.getElementById('versionSelect');
            const releaseName = document.getElementById('releaseName');
            const releaseDate = document.getElementById('releaseDate');
            const releaseBody = document.getElementById('releaseBody');
            const fileList = document.getElementById('fileList');
            const fileCountBadge = document.getElementById('fileCountBadge');

            // 如果页面已经有缓存数据，则初始化搜索功能并跳过AJAX请求
            if (versionSelect && versionSelect.options.length > 1 &&
                releaseName && releaseName.textContent !== 'Loading...' &&
                fileList && !fileList.querySelector('.loading')) {

                // 初始化搜索功能
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.disabled = false;
                    searchInput.placeholder = '搜索文件...';

                    // 防抖功能-避免频繁搜索造成性能问题
                    let searchTimeout;
                    searchInput.addEventListener('input', function() {
                        clearTimeout(searchTimeout);
                        searchTimeout = setTimeout(() => {
                            const searchTerm = this.value.toLowerCase();
                            const fileItems = document.querySelectorAll('.file-item');

                            // 计算并显示匹配的项目数量
                            let visibleCount = 0;

                            fileItems.forEach(function(item) {
                                const fileName = item.getAttribute('data-name').toLowerCase();
                                if (fileName.includes(searchTerm)) {
                                    item.style.display = 'flex';
                                    visibleCount++;
                                } else {
                                    item.style.display = 'none';
                                }
                            });

                            // 更新计数器显示
                            if (fileCountBadge) {
                                fileCountBadge.textContent = `${visibleCount} 个项目`;
                                fileCountBadge.style.display = 'inline';
                            }
                        }, 300); // 300ms防抖延迟
                    });
                }

                // 初始化Feather Icons
                if (typeof feather !== 'undefined') {
                    feather.replace();
                }

                return; // 跳过AJAX请求
            }

            // 如果没有缓存数据，则进行AJAX请求
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.disabled = true; // 初始禁用搜索框
                searchInput.placeholder = '正在与Github同步中...';
            }

            // 显示加载状态
            if (fileList) {
                fileList.innerHTML = '<div class="loading">正在与Github同步文件列表中...</div>';
            }

            // 使用AJAX获取GitHub数据
            const originalTag = new URLSearchParams(window.location.search).get('tag') || 'latest';
            let currentTag = originalTag;
            // 如果URL参数中包含"(Latest)"标记，则移除它以获取实际版本号
            if (currentTag.includes(' (Latest)')) {
                currentTag = currentTag.replace(' (Latest)', '');
            }
            fetch(`?action=get_github_data&tag=${encodeURIComponent(originalTag)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        if (fileList) {
                            fileList.innerHTML = `<div class="error">错误: ${data.error}</div>`;
                        }
                        return;
                    }

                    // 更新版本选择器
                    if (data.all_releases && data.all_releases.length > 0) {
                        if (versionSelect) {
                            // 找到GitHub API标记的latest版本
                            const latestRelease = data.all_releases.find(release => release.is_latest_tag);

                            // 按发布时间排序版本（最新的在前）
                            const sortedReleases = [...data.all_releases].sort((a, b) => {
                                return new Date(b.published_at) - new Date(a.published_at);
                            });

                            // 清空现有选项
                            versionSelect.innerHTML = '';

                            // 添加版本选项（按时间排序）
                            sortedReleases.forEach((release) => {
                                const option = document.createElement('option');
                                option.value = release.tag_name;

                                // 检查此版本是否是GitHub标记的latest版本
                                if (release.is_latest_tag) {
                                    option.textContent = `${release.tag_name} (Latest)`;
                                } else {
                                    option.textContent = release.tag_name;
                                }

                                // 如果URL中的tag是'latest'，则选择GitHub API标记的latest版本
                                if (originalTag === 'latest' && release.is_latest_tag) {
                                    option.selected = true;
                                } else if (release.tag_name === currentTag) {
                                    option.selected = true;
                                } else {
                                    option.selected = false;
                                }

                                versionSelect.appendChild(option);
                            });

                            // 启用版本选择器
                            versionSelect.disabled = false;
                        }
                    }

                    // 更新发布信息
                    if (data.release_data) {
                        if (releaseName) {
                            releaseName.textContent = data.release_data.name || 'Unknown';
                        }

                        if (releaseDate) {
                            const date = new Date(data.release_data.published_at);
                            releaseDate.textContent = date.toLocaleString();
                        }

                        if (releaseBody) {
                            // 简单地将发布说明显示为文本
                            releaseBody.innerHTML = data.release_data.body ?
                                data.release_data.body.replace(/\n/g, '<br>') : '';
                        }

                        const latestBadge = document.getElementById('latestBadge');
                        // 检测当前版本是否为GitHub标记的latest版本并显示badge
                        let isLatestVersion = false;

                        // 检查当前显示的版本是否是GitHub标记的latest版本
                        if (data.all_releases) {
                            const currentRelease = data.all_releases.find(release => release.tag_name === data.release_data.tag_name);
                            if (currentRelease && currentRelease.is_latest_tag) {
                                isLatestVersion = true;
                            }
                        }

                        // 显示或隐藏badge
                        if (isLatestVersion && latestBadge) {
                            latestBadge.style.display = 'inline';
                        } else if (latestBadge) {
                            latestBadge.style.display = 'none';
                        }
                    }

                    // 渲染文件列表
                    renderFileList(data.assets);

                    // 启用搜索功能
                    if (searchInput) {
                        searchInput.disabled = false;
                        searchInput.placeholder = '搜索文件...';

                        // 添加防抖功能，避免频繁搜索造成性能问题
                        let searchTimeout;
                        searchInput.addEventListener('input', function() {
                            clearTimeout(searchTimeout);
                            searchTimeout = setTimeout(() => {
                                const searchTerm = this.value.toLowerCase();
                                const fileItems = document.querySelectorAll('.file-item');

                                // 计算并显示匹配的项目数量
                                let visibleCount = 0;

                                fileItems.forEach(function(item) {
                                    const fileName = item.getAttribute('data-filename').toLowerCase();
                                    if (fileName.includes(searchTerm)) {
                                        item.style.display = 'flex';
                                        visibleCount++;
                                    } else {
                                        item.style.display = 'none';
                                    }
                                });

                                // 更新计数器显示
                                if (fileCountBadge) {
                                    fileCountBadge.textContent = `${visibleCount} 个项目`;
                                    fileCountBadge.style.display = 'inline';
                                }
                            }, 300); // 300ms防抖延迟
                        });
                    }
                })
                .catch(error => {
                    console.error('Error fetching GitHub data:', error);
                    if (fileList) {
                        fileList.innerHTML = '<div class="error">加载失败，请刷新页面重试。</div>';
                    }
                });
        });

        function renderFileList(assets) {
            const fileList = document.getElementById('fileList');
            const fileCountBadge = document.getElementById('fileCountBadge');
            if (!fileList) return;

            if (assets.length === 0) {
                fileList.innerHTML = '<div class="no-files">没有找到文件</div>';
                // 即使没有文件也要更新计数
                if (fileCountBadge) {
                    fileCountBadge.textContent = `0 个项目`;
                    fileCountBadge.style.display = 'inline';
                }
                return;
            }

            fileList.innerHTML = '';

            // 统计文件数量并更新徽章
            const fileCount = assets.length;
            if (fileCountBadge) {
                fileCountBadge.textContent = `${fileCount} 个项目`;
                fileCountBadge.style.display = 'inline';
            }

            assets.forEach(function(asset) {
                const fileItem = document.createElement('div');
                fileItem.className = 'file-item';
                fileItem.setAttribute('data-filename', asset.name.toLowerCase());

                const fileSize = formatFileSize(asset.size);
                const downloadCount = asset.download_count || 0;

                fileItem.innerHTML = `
                    <div class="file-info">
                        <span class="file-name">${asset.name}</span>
                        <span class="file-size">${fileSize}</span>
                        <span class="download-count">下载: ${downloadCount}</span>
                    </div>
                    <div class="download-buttons">
                        <a href="${asset.browser_download_url}" class="download-btn original" target="_blank" onclick="recordDownloadClick('${asset.name}')"><i data-feather="github"></i><span>Github下载</span></a>
                        <a href="https://gh-proxy.org/${asset.browser_download_url}" class="download-btn proxy" target="_blank" onclick="recordDownloadClick('${asset.name}')"><i data-feather="send"></i><span>GH-Proxy下载</span></a>
                    </div>
                `;

                fileList.appendChild(fileItem);
            });

            // 为新添加的下载按钮添加点击事件监听器
            const downloadButtons = document.querySelectorAll('.download-btn');
            downloadButtons.forEach(function(button) {
                button.addEventListener('click', function(e) {
                    // 对于本地代理链接，我们希望在当前窗口打开而不是新窗口
                    if (this.classList.contains('local-proxy')) {
                        e.preventDefault();
                        window.open(this.href, '_blank');
                    }
                });
            });

            // 初始化新添加的Feather Icons图标
            if (typeof feather !== 'undefined') {
                feather.replace();
            }
        }

        // 记录下载点击的函数
        function recordDownloadClick(assetName) {
            // 使用fetch API发送异步请求记录下载
            fetch('?action=record_download&asset_name=' + encodeURIComponent(assetName), {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                }
            })
                .then(response => response.json())
                .then(data => {
                    console.log('下载记录已保存:', data);
                })
                .catch(error => {
                    console.error('记录下载失败:', error);
                });
        }

        function changeVersion() {
            const versionSelect = document.getElementById('versionSelect');
            let selectedVersion = versionSelect.value;
            let urlTag = selectedVersion; // 默认使用版本号作为URL参数

            if (selectedVersion) {
                // 获取选中的选项文本
                const selectedOption = versionSelect.options[versionSelect.selectedIndex];
                const selectedText = selectedOption.textContent;

                // 如果选中的是标记为Latest的版本，则URL参数设置为'latest'
                if (selectedText.includes(' (Latest)')) {
                    urlTag = 'latest';
                }

                // 添加时间戳参数以避免浏览器缓存
                const timestamp = new Date().getTime();
                window.location.href = `?tag=${encodeURIComponent(urlTag)}&_t=${timestamp}`;
            }
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
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

        // 初始化Feather Icons
        if (typeof feather !== 'undefined') {
            feather.replace();
        }

        // 关闭广告的函数
        function closeAd(button) {
            const adContainer = button.closest('form');
            // 添加淡出效果
            adContainer.style.transition = 'opacity 0.5s ease';
            adContainer.style.opacity = '0';

            // 在淡出完成后，移除元素以释放空间
            setTimeout(function() {
                adContainer.style.display = 'none';
                // 触发页面重排，让其他内容滑动填补空缺
                adContainer.style.height = '0';
                adContainer.style.overflow = 'hidden';
                adContainer.style.margin = '0';
                adContainer.style.padding = '0';
            }, 500);
        }
    </script>
    </body>
    </html>

<?php
// 格式化文件大小的辅助函数
function formatFileSize($size) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $unitIndex = 0;

    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }

    return round($size, 2) . ' ' . $units[$unitIndex];
}
?>
