<?php

namespace TypechoPlugin\CfImageProxy;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Utils\Helper;
use Widget\Archive;
use Widget\Base\Contents;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 利用 Cloudflare Worker 实现图片代理
 *
 * @package Cloudflare 图片代理插件
 * @author joyqi
 * @version %version%
 * @link https://github.com/joyqi/typecho-plugin-cfimageproxy
 */
class Plugin implements PluginInterface
{
    public static function activate()
    {
        Contents::pluginHandle()->contentEx = __CLASS__ . '::filter';
        Contents::pluginHandle()->excerptEx = __CLASS__ . '::filter';
        Archive::pluginHandle()->header = __CLASS__ . '::header';
    }

    public static function deactivate()
    {
    }

    public static function config(Form $form)
    {
        $workerUrl = new Form\Element\Text('workerUrl', null, '',
            _t('Cloudflare Worker 地址'), _t('例如：https://example.com/imgproxy/'));
        $workerUrl->addRule('required', _t('必须填写 Worker 地址'));
        $workerUrl->addRule('url', _t('Worker 地址格式错误'));

        $form->addInput($workerUrl);

        $secretKey = new Form\Element\Text('secretKey', null, '',
            _t('加密密钥（hex 格式）'), _t('加密密钥，64 字节的十六进制字符串'));
        $secretKey->addRule('required', '必须填写加密密钥');
        $secretKey->addRule(function ($secretKey) {
            if (preg_match('/^[0-9a-f]{64}$/', $secretKey)) {
                return true;
            }

            return false;
        }, _t( '加密密钥格式错误，必须是 64 字节的十六进制字符串'));
        $form->addInput($secretKey);

        $maxWidth = new Form\Element\Text('maxWidth', null, 1024,
            _t('最大宽度'), _t('最大宽度，单位 px'));
        $maxWidth->addRule('required', _t('必须填写最大宽度'));
        $maxWidth->addRule('isInteger', _t('最大宽度必须是整数'));
        $form->addInput($maxWidth);

        $maxHeight = new Form\Element\Text('maxHeight', null, 0,
            _t('最大高度'), _t('最大高度，单位 px'));
        $maxHeight->addRule('required', _t('必须填写最大高度'));
        $maxHeight->addRule('isInteger', _t('最大高度必须是整数'));
        $form->addInput($maxHeight);

        $quality = new Form\Element\Text('quality', null, 80,
            _t('图片质量'), _t('图片质量，范围 1-100'));
        $quality->addRule('required', _t('必须填写图片质量'));
        $quality->addRule('isInteger', _t('图片质量必须是整数'));
        $form->addInput($quality);
    }

    public static function personalConfig(Form $form)
    {
    }

    public static function header()
    {
        echo '<style>
        body:has(dialog[open]) {
            overflow: hidden;
        }

        img[data-cfimageproxy] {
            cursor: pointer;
        }
        
        .cf-image-dialog {
            box-sizing: border-box;
            max-width: 100vw;
            max-height: 100vh;
            padding: 40px;
            border: none;
            background: transparent;
            overflow: auto;
            overscroll-behavior: contain;
            outline: none;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .cf-image-loading {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 40px;
            height: 40px;
            border: 4px solid rgba(255, 255, 255, 0.2);
            border-top-color: rgba(255, 255, 255, 0.8);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }
        
        .cf-image-dialog img {
            display: block;
        }
        
        .cf-image-dialog button {
            position: fixed;
            top: 10px;
            right: 10px;
            background: transparent;
            border: none;
            padding: 5px 10px;
            cursor: pointer;
        }
        
        .cf-image-dialog button:after {
            content: "✕";
            font-size: 20px;
            font-weight: bold;
            color: rgba(255, 255, 255, 0.7);
        }
        
        ::backdrop {
            background: rgba(0, 0, 0, 0.8);
            overscroll-behavior: contain;
        }
        </style>';

        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                const images = document.querySelectorAll("img[data-cfimageproxy]");
                images.forEach(img => {
                    img.addEventListener("click", function() {
                        const dialog = document.createElement("dialog");
                        const closeButton = document.createElement("button");
                        const image = document.createElement("img");
                        const loading = document.createElement("div");
                        
                        closeButton.setAttribute("autoFocus", "true");
                        image.src = img.dataset.originalSrc;
                        loading.classList.add("cf-image-loading");
                        dialog.classList.add("cf-image-dialog");
                        dialog.appendChild(loading);
                        document.body.appendChild(dialog);
                        
                        closeButton.addEventListener("click", function() {
                            dialog.close("cancel");
                            dialog.remove();
                        });
                        
                        image.addEventListener("load", function() {
                            const padding = parseInt(getComputedStyle(dialog).padding) || 0;
                            const pixelRatio = window.devicePixelRatio > 1 ? 2 : 1;
                            const w = image.naturalWidth / pixelRatio;
                            const h = image.naturalHeight / pixelRatio;
                            
                            const ratio = Math.min(
                                (window.innerWidth - padding * 2) / w,
                                (window.innerHeight - padding * 2) / h 
                            );
                            
                            image.style.width = Math.min(w, w * ratio) + "px";
                            image.style.height = Math.min(h, h * ratio) + "px";
                            dialog.removeChild(loading);
                            dialog.appendChild(closeButton);
                            dialog.appendChild(image);
                        });
                        
                        dialog.showModal();
                    });
                });
            });
        </script>';
    }

    public static function filter($content, $widget, $last)
    {
        $workerUrl = Helper::options()->plugin('CfImageProxy')->workerUrl;
        $secretKey = Helper::options()->plugin('CfImageProxy')->secretKey;
        $maxWidth = Helper::options()->plugin('CfImageProxy')->maxWidth;
        $maxHeight = Helper::options()->plugin('CfImageProxy')->maxHeight;
        $quality = Helper::options()->plugin('CfImageProxy')->quality;

        if (empty($workerUrl) || empty($secretKey)) return $content;

        $buildUrl = function ($ratio, $originalUrl) use ($workerUrl, $secretKey, $maxWidth, $maxHeight, $quality) {
            $metaData = json_encode([
                'maxWidth' => $maxWidth * $ratio,
                'maxHeight' => $maxHeight * $ratio,
                'quality' => $quality,
            ]);
            $data = $metaData . '|' . $originalUrl;

            $encrypted = self::encrypt($data, $secretKey);
            $encoded = rtrim(strtr(base64_encode($encrypted), '+/', '-_'), '=');
            return $workerUrl . '?u=' . $encoded;
        };

        // 匹配所有 <img> 标签
        return preg_replace_callback(
            '/(<img\s+[^>]*)src=["\']([^"\']+)["\']([^>]*>)/i',
            function ($matches) use ($buildUrl) {
                $originalUrl = $matches[2];

                return $matches[1]
                    . 'src="' . $buildUrl(1, $originalUrl) . '"'
                    . ' srcset="' . $buildUrl(2, $originalUrl) . ' 2x"'
                    . ' data-original-src="' . $buildUrl(0, $originalUrl) . '"'
                    . ' data-cfimageproxy="true"'
                    . $matches[3];
            },
            $content);
    }

    /**
     * AES-GCM 加密
     */
    private static function encrypt($data, $keyHex)
    {
        $key = hex2bin($keyHex);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $iv . $ciphertext . $tag;
    }
}

