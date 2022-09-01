<?php

namespace Organik\ImageResizer\Classes;

use Exception;
use WP_Post;

class WordpressHandler
{
    /**
     * The single instance of OrganikProjects
     */
    private static WordpressHandler $instance;

    /**
     * Stores image sizes derived from custom
     */
    private static ?array $imageSizes = null;

    /**
     * Metadata cache
     */
    private static array $metadataCache = [];

    /**
     * Main class instance
     * Ensures only one instance of this class is loaded or can be loaded
     */
    public static function instance(): static
    {
        if (!isset(static::$instance)) {
            static::$instance = new static;

            if (method_exists(static::$instance, 'init')) {
                static::$instance->init();
            }
        }

        return static::$instance;
    }

    /**
     * Initialises the instance and connects hooks and actions.
     *
     * @return void
     */
    public function init(): void
    {
        // Handle image attributes in media library
        add_filter('wp_get_attachment_image_attributes', [$this, 'imageAttributes'], 10, 2);

        // Disable default image sizes
        add_action('intermediate_image_sizes_advanced', [$this, 'setImageSizes']);

        // Disable scaled image sizes and rotated image sizing
        add_filter('big_image_size_threshold', '__return_false');
        add_filter('wp_image_maybe_exif_rotate', '__return_false');

        // Generate pre-resized images on upload
        add_filter('wp_generate_attachment_metadata', [$this, 'generateImageMetadata'], 10, 2);

        // Delete pre-resized images on delete
        add_action('delete_attachment', [$this, 'deleteImageMetadata']);

        // Handle special cases for images added to Advanced Custom Fields
        add_filter('acf/update_value/type=image', [$this, 'updateImageMetadata'], 10, 4);
    }

    /**
     * Manipulates image attributes.
     *
     * We use this only in the admin panel to control preview images for images.
     *
     * @param array $attributes
     * @param WP_Post $attachment
     * @return void
     */
    public function imageAttributes($attributes, $attachment)
    {
        if (!is_admin()) {
            return $attributes;
        }

        $defaultThumb = apply_filters('orgnk_image_resize_default_thumbnail', '');
        if (empty($defaultThumb)) {
            return $attributes;
        }

        $metadata = wp_get_attachment_metadata($attachment->ID);

        $attributes['src'] = WP_CONTENT_URL . $metadata['sizes'][$defaultThumb]['url'];
        unset($attributes['srcset']);

        return $attributes;
    }

    /**
     * Sets available image sizes.
     *
     * This removes the default image sizes and replaces them with any custom sizes defined
     * by other plugins, or the theme itself.
     *
     * @param array $sizes
     * @return array
     */
    public function setImageSizes($sizes)
    {
        unset($sizes['thumbnail']);
        unset($sizes['thumb']);
        unset($sizes['medium']);
        unset($sizes['large']);
        unset($sizes['medium_large']);
        unset($sizes['1536x1536']);
        unset($sizes['2048x2048']);

        // Store other sizes available
        foreach ($sizes as $name => $size) {
            static::$imageSizes[$name] = [
                'width' => $size['width'] ?: null,
                'height' => $size['height'] ?: null,
                'options' => [
                    'mode' => ((bool) $size['crop']) ? 'crop' : 'auto',
                ],
            ];
            unset($sizes[$name]);
        }

        static::$imageSizes = apply_filters('orgnk_image_resize_sizes', static::$imageSizes);

        return $sizes;
    }

    /**
     * Generates image metadata for the given image
     *
     * @param array $metadata
     * @param int $attachmentId
     * @return array
     */
    public function generateImageMetadata($metadata, $attachmentId)
    {
        $filePath = get_attached_file($attachmentId);

        if (!file_exists($filePath)) {
            throw new Exception(
                sprintf(
                    'The uploaded file "%s" does not exist on this server',
                    $filePath
                )
            );
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif'])) {
            // For other types of files, return metadata as is
            return $metadata;
        }

        // Generate a list of sizes
        $sizes = apply_filters('orgnk_image_resize_sizes', static::$imageSizes);
        $metadata = apply_filters('orgnk_image_resize_metadata', $metadata, $attachmentId);

        // Add standard metadata
        $metadata['path'] = str_replace(WP_CONTENT_DIR, '', $filePath);
        $metadata['url'] = str_replace(WP_CONTENT_URL, '', wp_get_attachment_url($attachmentId));

        // Create resized directory if it does not exist
        if (!is_dir(WP_CONTENT_DIR . '/resized-uploads')) {
            if (!@mkdir(WP_CONTENT_DIR . '/resized-uploads')) {
                throw new Exception('Could not create resized images directory');
            }
        }

        // Generate resized images and create resized metadata
        foreach ($sizes as $name => $size) {
            $handler = new ImageHandler($filePath, $size['width'], $size['height'], $size['options']);
            $resizedPath = $handler->getPathToResizedImage();
            $resizedUrl = $handler->getResizedUrl();
            $relativePath = str_replace(WP_CONTENT_DIR, '', $resizedPath);
            $relativeUrl = str_replace(WP_CONTENT_URL, '', $resizedUrl);

            Resizer::open($filePath)
                ->resize($size['width'], $size['height'], $size['options'])
                ->save($resizedPath);

            $config = $handler->getConfig();
            $imageSize = getimagesize($resizedPath);
            $fileSize = filesize($resizedPath);

            $metadata['sizes'][$name] = [
                'file' => basename($resizedPath),
                'path' => $relativeUrl,
                'url' => $relativePath,
                'width' => $config['width'],
                'height' => $config['height'],
                'realWidth' => $imageSize[0],
                'realHeight' => $imageSize[1],
                'mime-type' => $this->getMimeType($config['extension'] ?? $handler->getExtension()),
                'filesize' => $fileSize,
            ];
        }

        return $metadata;
    }

    public function updateImageMetadata($value, $postId, $field, $original)
    {
        if (empty($value)) {
            return;
        }

        $metadata = wp_get_attachment_metadata($value);
        if ($metadata === false) {
            return;
        }
        $filePath = WP_CONTENT_DIR . $metadata['path'];

        // Determine field
        $fieldInfo = [
            'id' => $field['ID'],
            'label' => $field['label'] ?? '',
            'name' => $field['name'] ?? '',
        ];
        $formInfo = [
            'id' => null,
            'name' => null,
        ];

        while (!empty($field['parent'])) {
            $parentId = $field['parent'];

            if (str_starts_with($parentId, 'field_')) {
                $field = acf_get_field($parentId);
            } elseif (str_starts_with($parentId, 'group_')) {
                $form = acf_get_field_group($parentId);
                $formInfo = [
                    'id' => $form['key'],
                    'name' => $form['title'],
                ];
                break;
            }

            if ($field === false) {
                $form = get_post($parentId);
                $formInfo = [
                    'id' => $parentId,
                    'name' => $form->post_title,
                ];
                break;
            }
        }

        $sizes = apply_filters('orgnk_image_resize_acf_sizes', [], $formInfo, $fieldInfo);

        // Generate resized images and create resized metadata
        foreach ($sizes as $name => $size) {
            // Skip sizes that have already been created
            if (isset($metadata['sizes'][$name])) {
                continue;
            }

            $handler = new ImageHandler($filePath, $size['width'], $size['height'], $size['options']);
            $resizedPath = $handler->getPathToResizedImage();
            $resizedUrl = $handler->getResizedUrl();
            $relativePath = str_replace(WP_CONTENT_DIR, '', $resizedPath);
            $relativeUrl = str_replace(WP_CONTENT_URL, '', $resizedUrl);

            Resizer::open($filePath)
                ->resize($size['width'], $size['height'], $size['options'])
                ->save($resizedPath);

            $config = $handler->getConfig();
            $imageSize = getimagesize($resizedPath);
            $fileSize = filesize($resizedPath);

            $metadata['sizes'][$name] = [
                'file' => basename($resizedPath),
                'path' => $relativeUrl,
                'url' => $relativePath,
                'width' => $config['width'],
                'height' => $config['height'],
                'realWidth' => $imageSize[0],
                'realHeight' => $imageSize[1],
                'mime-type' => $this->getMimeType($config['extension'] ?? $handler->getExtension()),
                'filesize' => $fileSize,
            ];
        }

        wp_update_attachment_metadata($value, $metadata);

        return $value;
    }

    /**
     * Maps extensions to MIME types.
     *
     * @param string $extension
     * @return string
     */
    protected function getMimeType(string $extension): string
    {
        switch (strtolower($extension)) {
            case 'jpg':
            case 'jpeg':
                return 'image/jpeg';
            case 'png':
                return 'image/png';
            case 'gif':
                return 'image/gif';
            case 'webp':
                return 'image/webp';
        }
    }

    /**
     * Deletes the resized images on image deletion.
     *
     * @param int $attachmentId
     * @return void
     */
    public function deleteImageMetadata($attachmentId)
    {
        $metadata = wp_get_attachment_metadata($attachmentId);

        if (isset($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size) {
                $filePath = WP_CONTENT_DIR . $size['path'];
                if (is_file($filePath)) {
                    unlink($filePath);
                }
            }
        }
    }

    /**
     * Gets the resized image URLs for a given attachment.
     *
     * If `$size` is `null`, all available URLs are returned, keyed by their size. If a size is
     * provided, a single URL will be returned if that size is available, otherwise `null` will
     * be returned.
     *
     * @param int|WP_Post $attachmentId
     * @param string|null $size
     * @return array|string|null
     */
    public function getImageUrl($attachmentId, $size = null)
    {
        $metadata = wp_get_attachment_metadata($attachmentId);

        if (empty($metadata)) {
            return null;
        }

        if (is_null($size)) {
            $urls = [
                'full' => $metadata['url'],
            ];
            foreach ($metadata['sizes'] as $size => $data) {
                $urls[$size] = WP_CONTENT_URL . $data['url'];
            }
            return $urls;
        }

        return $metadata['sizes'][$size]['url'] ?? null;
    }
}
