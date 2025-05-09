<?php

namespace TypechoPlugin\CfImageProxy;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Utils\Helper;
use Widget\Base\Contents;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 
 *
 * @package typecho-plugin-cfimageproxy
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
    }

    public static function deactivate()
    {
    }

    public static function config(Form $form)
    {
        $workerUrl = new Form\Element\Text('workerUrl', null, '',
            'Cloudflare Worker 地址', '例如：https://imgproxy.example.workers.dev/');
        $form->addInput($workerUrl->addRule('required', '必须填写 Worker 地址'));

        $secretKey = new Form\Element\Text('secretKey', null, '',
            '加密密钥（hex 格式）', '32字节（256位）十六进制字符串，用于 AES-GCM 加密');
        $form->addInput($secretKey->addRule('required', '必须填写加密密钥'));
    }

    public static function personalConfig(Form $form)
    {
    }

    public static function filter($content, $widget, $last)
    {
        $workerUrl = Helper::options()->plugin('CfImageProxy')->workerUrl;
        $secretHex = Helper::options()->plugin('CfImageProxy')->secretKey;
        if (empty($workerUrl) || empty($secretHex)) return $content;

        // 匹配所有 <img> 标签
        return preg_replace_callback('/<img\s+[^>]*src=["\']([^"\']+)["\'][^>]*>/i', function ($matches) use ($workerUrl, $secretHex) {
            $originalUrl = $matches[1];
            $encrypted = self::encrypt($originalUrl, $secretHex);
            $encoded = rtrim(strtr(base64_encode($encrypted), '+/', '-_'), '=');
            $proxyUrl = rtrim($workerUrl, '/') . '?u=' . $encoded;
            return str_replace($originalUrl, $proxyUrl, $matches[0]);
        }, $content);
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

