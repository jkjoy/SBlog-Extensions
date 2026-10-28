<?php

declare(strict_types=1);

if (!defined('APP_VERSION')) { http_response_code(403); exit; }

/** Shared by the theme and its optional settings plugin. No theme files are written. */
function document_settings_schema(): array
{
    $imageHint = '填写完整 HTTP(S) 图片地址或以 / 开头的站内路径。';
    return [
        'primary_color' => ['section' => 'appearance', 'label' => '主题主要颜色', 'type' => 'color', 'default' => '#3eaf7c'],
        'background_color' => ['section' => 'appearance', 'label' => '全局背景颜色', 'type' => 'color', 'default' => '#f5f7fd'],
        'darkmode' => ['section' => 'appearance', 'label' => '默认阅读模式', 'type' => 'select', 'default' => 'auto', 'options' => ['auto' => '跟随系统', 'light' => '白天模式', 'dark' => '暗黑模式'], 'hint' => '访客手动切换后，优先使用其浏览器中保存的选择。'],
        'show_theme_toggle' => ['section' => 'appearance', 'label' => '显示白天 / 暗黑模式切换', 'type' => 'checkbox', 'default' => true],
        'show_color_picker' => ['section' => 'appearance', 'label' => '显示主题调色板', 'type' => 'checkbox', 'default' => true],
        'show_logo' => ['section' => 'appearance', 'label' => '导航栏显示 Logo', 'type' => 'checkbox', 'default' => true],
        'logo_url' => ['section' => 'appearance', 'label' => 'Logo 图片', 'type' => 'image', 'default' => '', 'hint' => $imageHint . ' 留空使用站点 Logo。'],
        'show_title' => ['section' => 'appearance', 'label' => '导航栏显示站点标题', 'type' => 'checkbox', 'default' => true],
        'navbar_title' => ['section' => 'appearance', 'label' => '导航栏标题', 'type' => 'text', 'default' => '', 'limit' => 100, 'hint' => '留空使用站点名称。'],
        'show_search' => ['section' => 'appearance', 'label' => '显示文章搜索', 'type' => 'checkbox', 'default' => true],
        'list_columns' => ['section' => 'home', 'label' => '文章列表栏数', 'type' => 'select', 'default' => '1', 'options' => ['1' => '单栏', '2' => '双栏'], 'hint' => '小屏幕自动改为单栏。'],
        'thumbnail_position' => ['section' => 'home', 'label' => '文章缩略图位置', 'type' => 'select', 'default' => 'right', 'options' => ['left' => '文章左侧', 'right' => '文章右侧', 'none' => '不显示'], 'hint' => '使用文章正文中的第一张图片；受保护的正文不会用于提取图片。'],
        'show_excerpt' => ['section' => 'home', 'label' => '显示文章摘要', 'type' => 'checkbox', 'default' => true],
        'excerpt_length' => ['section' => 'home', 'label' => '文章摘要字数', 'type' => 'number', 'default' => 120, 'min' => 30, 'max' => 500],
        'show_home_banner' => ['section' => 'home', 'label' => '显示首页横幅', 'type' => 'checkbox', 'default' => false],
        'banner_title' => ['section' => 'home', 'label' => '首页横幅标题', 'type' => 'text', 'default' => '', 'limit' => 200, 'hint' => '留空使用站点名称。'],
        'banner_subtitle' => ['section' => 'home', 'label' => '首页横幅描述', 'type' => 'textarea', 'default' => '', 'limit' => 1000, 'hint' => '留空使用站点简介。'],
        'banner_image' => ['section' => 'home', 'label' => '首页横幅背景图', 'type' => 'image', 'default' => '', 'hint' => $imageHint],
        'banner_mobile' => ['section' => 'home', 'label' => '移动端显示首页横幅', 'type' => 'checkbox', 'default' => true],
        'sidebar_left' => ['section' => 'sidebar', 'label' => '显示左侧分类导航', 'type' => 'checkbox', 'default' => true],
        'sidebar_right' => ['section' => 'sidebar', 'label' => '显示右侧资料栏', 'type' => 'checkbox', 'default' => true],
        'avatar' => ['section' => 'sidebar', 'label' => '作者头像', 'type' => 'image', 'default' => '', 'hint' => $imageHint],
        'author_name' => ['section' => 'sidebar', 'label' => '作者昵称', 'type' => 'text', 'default' => '', 'limit' => 100, 'hint' => '留空使用站点名称。'],
        'author_description' => ['section' => 'sidebar', 'label' => '作者简介', 'type' => 'textarea', 'default' => '', 'limit' => 1000, 'hint' => '纯文本，支持换行。'],
        'announcement' => ['section' => 'sidebar', 'label' => '站点公告', 'type' => 'textarea', 'default' => '', 'limit' => 3000, 'hint' => '纯文本，支持换行；留空隐藏公告。'],
        'social_github' => ['section' => 'sidebar', 'label' => 'GitHub 地址', 'type' => 'url', 'default' => ''],
        'social_email' => ['section' => 'sidebar', 'label' => '联系邮箱', 'type' => 'email', 'default' => ''],
        'social_rss' => ['section' => 'sidebar', 'label' => '显示 RSS 订阅链接', 'type' => 'checkbox', 'default' => true],
        'recent_posts_count' => ['section' => 'sidebar', 'label' => '最新文章数量', 'type' => 'number', 'default' => 5, 'min' => 0, 'max' => 20, 'hint' => '0 表示隐藏最新文章。'],
        'show_toc' => ['section' => 'reading', 'label' => '显示文章阅读目录', 'type' => 'checkbox', 'default' => true],
        'toc_depth' => ['section' => 'reading', 'label' => '目录收录标题深度', 'type' => 'select', 'default' => '3', 'options' => ['2' => '一级、二级标题', '3' => '一级至三级标题', '4' => '一级至四级标题']],
        'toc_collapsed' => ['section' => 'reading', 'label' => '默认折叠子级目录', 'type' => 'checkbox', 'default' => false],
        'show_post_navigation' => ['section' => 'reading', 'label' => '显示上一篇 / 下一篇文章', 'type' => 'checkbox', 'default' => true],
        'show_copyright' => ['section' => 'reading', 'label' => '显示文章版权信息', 'type' => 'checkbox', 'default' => true],
        'copyright_text' => ['section' => 'reading', 'label' => '文章版权信息', 'type' => 'textarea', 'default' => "本文由 {author} 发布，转载请注明出处。\n文章标题：{title}\n本文链接：{url}", 'limit' => 3000, 'hint' => '支持纯文本与占位符 {title}（文章标题）、{url}（文章链接）、{author}（文章作者）；不支持 HTML。'],
        'donation_image' => ['section' => 'reading', 'label' => '赞赏收款码', 'type' => 'image', 'default' => '', 'hint' => $imageHint . ' 留空隐藏赞赏按钮。'],
        'footer_text' => ['section' => 'footer', 'label' => '页脚文字', 'type' => 'textarea', 'default' => '', 'limit' => 2000, 'hint' => '纯文本，支持换行。'],
        'footer_since' => ['section' => 'footer', 'label' => '建站年份', 'type' => 'number', 'default' => (int)date('Y'), 'min' => 1970, 'max' => 2100],
        'icp_text' => ['section' => 'footer', 'label' => '备案号', 'type' => 'text', 'default' => '', 'limit' => 100],
        'icp_url' => ['section' => 'footer', 'label' => '备案查询地址', 'type' => 'url', 'default' => 'https://beian.miit.gov.cn/', 'hint' => '填写完整 HTTP(S) 地址。'],
        'custom_css' => ['section' => 'footer', 'label' => '自定义 CSS', 'type' => 'css', 'default' => '', 'limit' => 30000, 'hint' => '仅填写 CSS 规则，不要包含 <style> 标签。'],
    ];
}

/** Restrict database and submitted options to schema fields with safe, bounded values. */
function document_settings_validate(array $input): array
{
    $values = [];
    $errors = [];
    foreach (document_settings_schema() as $key => $field) {
        $value = $input[$key] ?? ($field['type'] === 'checkbox' ? false : $field['default']);
        $invalid = !is_scalar($value) && $value !== null;
        if ($invalid) { $value = $field['default']; }
        if ($field['type'] === 'checkbox') {
            $invalid = $invalid || !in_array($value, [true, false, 0, 1, '0', '1', 'on'], true);
            $value = in_array($value, [true, 1, '1', 'on'], true);
        } else {
            $value = trim((string)$value);
            switch ($field['type']) {
                case 'color':
                    $invalid = $invalid || preg_match('/^#[0-9a-f]{6}$/iD', $value) !== 1;
                    $value = strtolower($value);
                    break;
                case 'select':
                    $invalid = $invalid || !array_key_exists($value, $field['options']);
                    break;
                case 'number':
                    $invalid = $invalid || preg_match('/^\d+$/D', $value) !== 1
                        || (int)$value < $field['min'] || (int)$value > $field['max'];
                    $value = (int)$value;
                    break;
                case 'image':
                case 'url':
                    $local = $field['type'] === 'image' && str_starts_with($value, '/') && !str_starts_with($value, '//');
                    $invalid = $invalid || strlen($value) > 2048
                        || ($value !== '' && !$local && !valid_http_url($value))
                        || preg_match('/[\x00-\x20\x7f\\\\]/', $value) === 1;
                    break;
                case 'email':
                    $invalid = $invalid || strlen($value) > 254 || ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false);
                    break;
                case 'css':
                    $invalid = $invalid || strlen($value) > $field['limit'] || str_contains($value, "\0")
                        || preg_match('/<\s*\/?\s*(?:style|script)\b/i', $value) === 1;
                    break;
                default:
                    $value = trim(strip_tags($value));
                    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
                    $invalid = $invalid || $length > $field['limit'] || str_contains($value, "\0");
            }
        }
        if ($invalid) {
            $errors[] = $field['label'] . '的格式或范围不正确。';
            $value = $field['default'];
        }
        $values[$key] = $value;
    }
    return [$values, $errors];
}

function document_settings_values(): array
{
    $defaults = array_map(static fn(array $field): mixed => $field['default'], document_settings_schema());
    $stored = json_decode(setting('document_options', '{}'), true);
    [$values] = document_settings_validate(array_replace($defaults, is_array($stored) ? $stored : []));
    return $values;
}
