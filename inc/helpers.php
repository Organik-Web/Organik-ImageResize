<?php

use Organik\ImageResizer\Classes\ImageHandler;
use Organik\ImageResizer\Classes\WordpressHandler;

if (!function_exists('orgnk_image_resize')) {
    /**
     * Resizes a given image in a template
     *
     * The URL of the resized image will be returned.
     *
     * @param string $path
     * @param int $width
     * @param int $height
     * @param array $options
     * @return string
     */
    function orgnk_image_resize($path, $width, $height, ?array $options = null)
    {
        $resizer = new ImageHandler($path, $width, $height, $options ?? []);
        return $resizer->getUrl();
    }
}

if (!function_exists('orgnk_picture')) {
    /**
     * Generates a picture tag with images of the given resizing options.
     *
     * Each size should be an array with the following keys:
     *  - `breakpoint`: The breakpoint at which the image should be displayed. A value of `0` will
     *      make that image the "default" image.
     *  - `width`: The width of the image at that breakpoint.
     *  - `height`: The height of the image at that breakpoint.
     *  - `format`: The format of the image. Must be 'jpg', 'png', 'gif' or 'webp'.
     *  - `options`: Options to pass through to the resizer.
     *
     * This method echoes directory to the template.
     *
     * @param string $path
     * @param array $breakpoints
     * @param array $attributes Additional attributes to include with the default image
     * @return void
     */
    function orgnk_picture($path, array $breakpoints, array $attributes = [])
    {
        $default = null;
        $template = '<picture>%s%s</picture>';
        $images = [];

        foreach ($breakpoints as $breakpoint) {
            if (!in_array($breakpoint['format'], ['jpg', 'png', 'gif', 'webp'])) {
                throw new \Exception('Invalid image format.');
            }

            $image = orgnk_image_resize(
                $path,
                $breakpoint['width'],
                $breakpoint['height'],
                array_replace($breakpoint['options'] ?? [], ['extension' => $breakpoint['format']]),
            );

            if ($breakpoint['breakpoint'] === 0) {
                $default = $image;
                continue;
            }

            $images[] = [
                'image' => $image,
                'breakpoint' => $breakpoint,
            ];
        }

        if ($default === null) {
            $default = array_shift($images);
        }

        if (count($images) < 0) {
            // Return an image tag
            echo sprintf('<img src="%s"%s>', $default, implode(' ', array_map(function ($key, $value) {
                return $key . '="' . $value . '"';
            }, array_keys($attributes), array_values($attributes))));
        }

        echo sprintf(
            $template,
            implode('', array_map(function ($image) {
                return sprintf(
                    '<source srcset="%s" media="(min-width: %dpx)" type="image/%s">',
                    $image['image'],
                    $image['breakpoint']['breakpoint'],
                    $image['breakpoint']['format'],
                );
            }, $images)),
            sprintf('<img src="%s"%s>', $default, implode(' ', array_map(function ($key, $value) {
                return $key . '="' . $value . '"';
            }, array_keys($attributes), array_values($attributes))))
        );
    }
}

// if (!function_exists('orgnk_get_image_path')) {

//     function orgnk_get_image_path($attachmentId, $size = null)
//     {
//         $wpHandler = new WordpressHandler();
//         return $wpHandler->getImagePath($path);
//     }
// }
