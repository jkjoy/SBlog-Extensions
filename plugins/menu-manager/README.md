# 菜单管理

统一管理站点主导航。内置主题、Starter 和本仓库的全部 18 个自定义布局主题均支持，包含桌面与移动菜单；主题的搜索、外观切换、账户及 RSS 等辅助入口继续由主题提供。

## 使用

1. 安装并启用插件，通过「后台 → 插件管理 → 菜单管理 → 设置」打开编辑器。
2. 选择内置页面、已发布独立页面、分类或自定义链接，点击「添加菜单项」。支持自定义名称、显示开关、新窗口打开、上移、下移和删除，最多 100 项。
3. 勾选「接管主题主菜单」并保存。配置独立于主题保存，切换主题后继续使用同一菜单。
4. 点击「恢复主题默认菜单」或取消接管开关可恢复主题原导航，并保留已保存的统一配置。停用插件也会恢复原导航。

启用接管并保存空菜单会隐藏主导航链接。首次打开编辑器会预填首页、归档、标签、友链与核心原导航中的独立页面，但不会自动接管。

独立页面和分类以 ID 关联，修改名称或 Slug 后自动更新；自定义名称填写后保持原值。未发布、预约发布或已删除的页面不会出现在前台，已删除分类也会隐藏；编辑器会提示无效目标。自定义链接允许 HTTP(S)、以单个 `/` 开头的站内路径或 `#` 锚点，站内路径需包含站点的子目录（如 `/blog/about`）。新窗口链接自动添加 `noopener noreferrer`。

页面与分类在菜单中只形成链接，内容访问权限继续由核心控制。菜单为单层主导航，不改变页脚站点信息、社交链接、内容分类组件或文章上一篇/下一篇导航。

所有写操作都要求后台登录、POST 和 CSRF 校验；整个菜单在校验通过后写入一个核心设置 `menu_manager_config`。保存失败保留原配置并回显可编辑的表单。表单尾部完整标记防止服务器 `max_input_vars` 截断造成部分保存。已启用静态页面缓存时，合法后台 POST 会触发其既有缓存失效流程。

后台可不依赖 JavaScript 编辑已有项目；添加、删除、排序按钮会提交并保存当前表单。无 JavaScript 时新增自定义链接会先创建可编辑的首页项，请再改为自定义链接并填写名称与地址。

## 第三方主题接入

主题保持自己的导航容器、移动菜单按钮及辅助控件，只将主导航项目交给以下 API：

```php
<?php if (function_exists('sblog_menu_is_managed') && sblog_menu_is_managed()): ?>
  <?= sblog_menu_render($themeContext, [
      'item_tag' => 'li',
      'link_class' => 'nav-link',
      'active_class' => 'is-active',
  ]) ?>
<?php else: ?>
  <!-- 原主题菜单 -->
<?php endif; ?>
```

`sblog_menu_items($themeContext)` 返回已解析、可见的项目，包含 `id`、`type`、`reference`、`label`、`url`、`enabled`、`new_tab`、`active`、`route` 和 `target`。自行渲染时应转义名称、URL，并为新窗口链接添加安全关系属性。

`sblog_menu_render()` 不包含外层 `nav`/`ul`，选项支持 `item_tag`（`li`/`div`）、`item_class`、`active_item_class`、`link_class`（字符串或回调）、`active_class`、`label_tag`（`span`）、`label_prefix`、`label_suffix`、`icon` 回调和 `url` 回调。`active_class` 用于链接，`active_item_class` 用于外层项目；回调由可信主题代码提供。桌面和移动分别渲染时必须使用同一配置。

核心默认布局暂未提供主导航钩子，所以内置主题与 Starter 使用限定在公开页面 `.text-header > … > .text-nav` 的输出兼容处理；其他自定义布局需显式接入以上 API。后台导航与正文中的链接不会被接管。

保存后提供 `menu_changed` action，context 包含 `managed`；其他缓存或插件可监听它刷新自己的状态。

## 验证

```bash
php scripts/test-menu-manager.php
php scripts/test-menu-manager-themes.php
node scripts/test-menu-manager-admin.cjs
```
