# 链接通讯接口（Link Communication Interface）

一个轻量的 WordPress 插件：把后台「链接」中保存的站点，通过带密钥校验的 REST API 安全地提供给外部程序使用。

[![CI](https://github.com/XINYUCloud/link-hank/actions/workflows/ci.yml/badge.svg)](https://github.com/XINYUCloud/link-hank/actions/workflows/ci.yml)

> 插件由 Hank[谶航辰] 开发，**不收费**。如遇到收费被骗请自行负责。

## 功能特性

- **安全接口**：`POST /wp-json/linkinterface/v1/get-links`，请求头携带 `X-Comm-Key` 通讯密钥
- **分类筛选**：可在后台勾选需要对外返回的链接分类，不勾选则返回全部分类
- **TDK 信息**：可选返回链接名称、描述、图片地址
- **IP 白名单**：支持 IPv4 / IPv6，支持 CIDR（如 `192.168.1.0/24`、`2001:db8::/32`）
- **Redis 缓存**：可选开启，缓存时间 1–60 分钟，支持一键清除缓存
- **配置导出/导入**：后台一键导出 JSON 配置，方便备份与迁移
- **总开关**：可随时停用接口，中断所有外部请求

## 环境要求

| 项目 | 要求 |
| --- | --- |
| WordPress | 5.0 及以上 |
| PHP | 7.4 及以上 |
| Redis | 可选，需安装 `php-redis` 扩展 |

## 安装

1. 下载本仓库代码（或从 Release 页面下载 `link-hank.zip`）。
2. 将整个目录放入 `wp-content/plugins/`，目录名建议为 `link-hank`。
3. 在 WordPress 后台「插件」页面启用「链接通讯接口」。
4. 进入「设置 → 链接通讯接口」，配置通讯密钥与各项功能。

## 使用

### 请求地址

```
POST https://your-site.com/wp-json/linkinterface/v1/get-links
```

### 请求头

```
X-Comm-Key: 您的通讯密钥
```

通讯密钥可在后台设置页查看或重新生成。

### cURL 示例

```bash
curl -X POST https://your-site.com/wp-json/linkinterface/v1/get-links \
  -H "X-Comm-Key: 您的通讯密钥"
```

### 返回示例

```json
{
  "success": true,
  "count": 2,
  "links": [
    {
      "link_url": "https://example1.com",
      "rss_url": "https://example1.com/feed",
      "title": "示例站点",
      "description": "这是一个示例站点的描述",
      "image_url": "https://example1.com/logo.png"
    },
    {
      "link_url": "https://example2.com",
      "rss_url": null,
      "title": "另一个站点",
      "description": null,
      "image_url": null
    }
  ]
}
```

开启「TDK 信息返回」后才会包含 `title`、`description`、`image_url` 字段。

### 链接信息填写

请在 WordPress 后台「链接」管理中，为每个链接填写「名称」「描述」「图片地址」等信息，接口会据此生成返回内容。

## 后台设置说明

| 设置项 | 说明 |
| --- | --- |
| 通讯状态 | 停用后所有外部请求将被拒绝 |
| 通讯密钥 | 外部请求需在 `X-Comm-Key` 请求头中携带 |
| 链接分类筛选 | 勾选需要返回的链接分类 |
| TDK信息返回 | 开启后返回站点名称、描述、图片地址 |
| IP白名单 | 开启后仅白名单 IP 可访问，支持 CIDR |
| Redis缓存 | 开启后可降低接口重复请求开销，缓存 1–60 分钟 |

## 项目结构

```
link-hank.php                 # 插件主文件（REST API、后台设置、缓存、导入导出）
material/
└── index/
    ├── css/index.css         # 后台样式
    └── js/index.js           # 后台交互脚本
LICENSE                       # CC BY-NC-SA 4.0
.github/workflows/            # CI 与发布工作流
```

## 开发与发布

- 每次 push / PR 会自动运行 PHP 语法检查（PHP 7.4–8.3）。
- 推送 `v*` 标签会自动构建 `link-hank.zip` 并创建 GitHub Release。

```bash
git tag v1.3.0
git push origin v1.3.0
```

## 许可证

本项目采用 [CC BY-NC-SA 4.0](LICENSE)（署名—非商业性使用—相同方式共享 4.0 国际）许可协议。

- **署名**：使用时需注明原作者 Hank[谶航辰]。
- **非商业性使用**：不得用于商业目的。
- **相同方式共享**：修改后的作品需以相同协议分发。
