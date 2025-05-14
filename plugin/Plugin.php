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

    public static function filter($content, $widget, $last)
    {
        $workerUrl = Helper::options()->plugin('CfImageProxy')->workerUrl;
        $secretKey = Helper::options()->plugin('CfImageProxy')->secretKey;
        $maxWidth = Helper::options()->plugin('CfImageProxy')->maxWidth;
        $maxHeight = Helper::options()->plugin('CfImageProxy')->maxHeight;
        $quality = Helper::options()->plugin('CfImageProxy')->quality;

        if (empty($workerUrl) || empty($secretKey)) return $content;

        // 匹配所有 <img> 标签
        return preg_replace_callback(
            '/<img\s+[^>]*src=["\']([^"\']+)["\'][^>]*>/i',
            function ($matches) use ($workerUrl, $secretKey, $maxHeight, $maxWidth, $quality) {
                $originalUrl = $matches[1];
                $metaData = json_encode([
                    'maxWidth' => $maxWidth,
                    'maxHeight' => $maxHeight,
                    'quality' => $quality,
                ]);
                $data = $metaData . '|' . $originalUrl;

                $encrypted = self::encrypt($data, $secretKey);
                $encoded = rtrim(strtr(base64_encode($encrypted), '+/', '-_'), '=');
                $proxyUrl = $workerUrl . '?u=' . $encoded;
                return str_replace($originalUrl, $proxyUrl, $matches[0]);
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

