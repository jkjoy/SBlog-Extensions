# 菜单管理

统一管理站点主导航，支持最多三级菜单。内置主题、Starter、Sunny 及其他已接入的自定义布局主题可共用配置，包含桌面与移动菜单；主题的搜索、外观切换、账户及 RSS 等辅助入口继续由主题提供。

## 使用

1. 安装并启用插件，通过「后台 → 扩展设置 → 菜单管理」打开编辑器，也可从插件管理中的「设置」进入。
2. 选择内置页面、已发布独立页面、分类或自定义链接，点击「添加菜单项」。通过「上级菜单」将项目放到父项下，最多三级、合计 100 项。支持自定义名称、显示开关、新窗口打开、上移、下移和删除。
3. 勾选「接管主题主菜单」并保存。配置独立于主题保存，切换主题后继续使用同一菜单。
4. 点击「恢复主题默认菜单」或取消接管开关可恢复主题原导航，并保留已保存的统一配置。停用插件也会恢复原导航。

启用接管并保存空菜单会隐藏主导航链接。首次打开编辑器会预填首页、归档、标签、友链与核心原导航中的独立页面，但不会自动接管。

独立页面和分类以 ID 关联，修改名称或 Slug 后自动更新；自定义名称填写后保持原值。未发布、预约发布或已删除的页面不会出现在前台，已删除分类也会隐藏；编辑器会提示无效目标。自定义链接允许 HTTP(S)、以单个 `/` 开头的站内路径或 `#` 锚点，站内路径需包含站点的子目录（如 `/blog/about`）。新窗口链接自动添加 `noopener noreferrer`。

前台父项保留自身链接，点击其名称可以访问目标，点击旁边的展开区域可显示子菜单；原生折叠控件支持键盘和触屏。父项隐藏、页面未发布或目标删除时，其全部子项也隐藏，避免变成无上级的链接。父项包含当前页面时会显示所在分支，只有实际当前页面链接标记 `aria-current="page"`。

编辑器按层级缩进显示菜单项。上移、下移调整同级顺序，整个子树一起移动；删除父项会把直接子项提升到它原来的上级，保留子项。不能把自己或后代设为上级，也不能超过三级。原有单层配置可直接使用，无需重新添加。

Sunny 1.0.15 起在桌面导航、手机抽屉和手机底部入口使用同一份菜单。菜单接管开关关闭、或插件停用时恢复 Sunny 默认导航；接管后不会额外加入原来的分类、更多或独立页面链接。

页面与分类在菜单中只形成链接，内容访问权限继续由核心控制。不改变页脚站点信息、社交链接、内容分类组件或文章上一篇/下一篇导航。

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

`sblog_menu_items($themeContext)` 保留平面数组接口，返回已解析、可见的项目，包含 `id`、`type`、`reference`、`label`、`url`、`enabled`、`new_tab`、`active`、`route`、`target`，并增加 `parent_id`、`depth`。原有配置缺少 `parent_id` 时按顶级项目处理。

`sblog_menu_tree($themeContext)` 返回同样项目组成的树，每项增加 `children` 和 `has_active_child`；`active` 仅代表当前项目，`has_active_child` 表示当前页面位于子孙中。需要保留主题专属菜单结构时可递归渲染此接口。自行渲染应转义名称、URL，并为新窗口链接添加安全关系属性。

`sblog_menu_render()` 不包含外层 `nav`/`ul`，选项支持 `item_tag`（`li`/`div`）、`item_class`、`active_item_class`、`link_class`（字符串或回调）、`active_class`、`label_tag`（`span`）、`label_prefix`、`label_suffix`、`icon` 回调和 `url` 回调。`active_class` 用于链接，`active_item_class` 用于外层项目；回调由可信主题代码提供。桌面和移动分别渲染时必须使用同一配置。

通用渲染器用原生 `details`/`summary` 输出子菜单，并只在实际出现分支时添加插件的前台样式，无需额外 JavaScript。单层项目沿用原有输出。主题可用 `sblog-menu-branch`、`sblog-menu-summary`、`sblog-menu-children` 定制外观，或像 Sunny 一样使用树接口输出自己的样式结构。

核心默认布局暂未提供主导航钩子，所以内置主题与 Starter 使用限定在公开页面 `.text-header > … > .text-nav` 的输出兼容处理；其他自定义布局需显式接入以上 API。后台导航与正文中的链接不会被接管。

保存后提供 `menu_changed` action，context 包含 `managed`；其他缓存或插件可监听它刷新自己的状态。

## 验证

```bash
php scripts/test-menu-manager.php
php scripts/test-menu-manager-themes.php
node scripts/test-menu-manager-admin.cjs
```
