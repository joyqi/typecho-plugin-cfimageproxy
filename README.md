# Typecho Cloudflare 图片代理插件

利用 Cloudflare 里 Worker 的计算能力，将远端博客上的原图自动输出为经过处理的图片，以实现如下特性：

1. 去掉 EXIF 信息，去除可能泄漏的隐私信息
2. 自动压缩，根据浏览器特性自动选择 avif / webp / jpeg 格式
3. 缩略图，自由选择最大宽度缩放

** ⚠️ 插件所在的博客必须托管在 Cloudflare 上 **

## 配置 Worker

### 代码

请在 Cloudflare 上创建 Worker 并将如下代码复制进去

```js
export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    const encryptedParam = url.searchParams.get("u");
    if (!encryptedParam) {
      return new Response("Missing 'u' parameter", { status: 400 });
    }

    try {
      // Base64 解码
      const fixedBase64 = base64UrlToBase64(encryptedParam);
      const encryptedData = Uint8Array.from(atob(fixedBase64), c => c.charCodeAt(0));

      // 从 encryptedData 中提取 iv 和密文（此处假设前 12 字节是 IV）
      const iv = encryptedData.slice(0, 12);
      const data = encryptedData.slice(12);

      const key = await crypto.subtle.importKey(
        "raw",
        hexToBytes(env.SECRET_KEY), // 示例：32字节 hex 字符串
        "AES-GCM",
        false,
        ["decrypt"]
      );

      const decrypted = await crypto.subtle.decrypt(
        {
          name: "AES-GCM",
          iv: iv,
        },
        key,
        data
      );
      const [metaData, decryptedUrl] = new TextDecoder().decode(decrypted).split('|');
      const { maxWidth, maxHeight, quality } = JSON.parse(metaData);

      const options = {
        cf: {
          image: {
            quality
          }
        }
      };

      if (maxWidth > 0) {
        options.cf.image.width = maxWidth;
      }

      if (maxHeight > 0) {
        options.cf.image.height = maxHeight;
      }

      options.cf.image.fit = 'scale-down';
      options.cf.image.metadata = 'none';
      options.cf.image.format = 'jpeg';

      const accept = request.headers.get("Accept");
      if (/image\/avif/.test(accept)) {
        options.cf.image.format = 'avif';
      } else if (/image\/webp/.test(accept)) {
        options.cf.image.format = 'webp';
      }

      const imageRequest = new Request(decryptedUrl, {
        headers: request.headers
      });

      console.log(options);
      return fetch(imageRequest, options);
    } catch (err) {
      return new Response("Invalid encrypted parameter or decryption failed", { status: 400 });
    }
  }
};

// 工具函数：将十六进制字符串转为 Uint8Array
function hexToBytes(hex) {
  const bytes = new Uint8Array(hex.length / 2);
  for (let i = 0; i < bytes.length; i++) {
    bytes[i] = parseInt(hex.substr(i * 2, 2), 16);
  }
  return bytes;
}

function base64UrlToBase64(base64url) {
  let base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
  while (base64.length % 4 !== 0) {
    base64 += '=';
  }
  return base64;
}
```

### 环境变量

到 Wokrer 的设置选项卡里创建环境变量 `SECRET_KEY` 并放进去一个随机的 64 字节长度 HEX 值作为密钥。

## 配置路由

转到 Cloudflare 上当前插件所在域名的 Workers 路由页面，添加一个路由。

- 路由：`example.com/imgproxy/*`（将 `example.com` 替换为你的域名，`imgproxy` 可以替换为你喜欢的路径）
- 选择 Worker：你刚刚创建的 Worker

## 配置插件

在 Typecho 的后台管理界面，转到插件设置页面，找到 Cloudflare 图片代理插件，点击设置。在设置页面中：

1. 输入你刚刚创建的 Worker 路由地址，例如 `https://example.com/imgproxy/`。确保这个地址是可以公开访问的。
2. 在加密密钥输入框中，输入你在 Worker 中设置的 `SECRET_KEY` 的值。
3. 设置默认的最大宽度、最大高度和质量。可以根据需要进行调整。
4. 点击保存按钮。
