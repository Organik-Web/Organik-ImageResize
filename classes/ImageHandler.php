<?php

namespace Organik\ImageResizer\Classes;

use Exception;

/**
 * Image handler class.
 *
 * Processes the resizing of an image to the given dimensions, type and quality.
 *
 * This will also handle URL generation and caching of resized images.
 *
 * Based off the implementation in Winter CMS, originally developed by Luke Towers
 * (https://github.com/wintercms/winter/blob/develop/modules/system/classes/ImageResizer.php).
 *
 * @author Luke Towers <hello@wintercms.com>
 * @author Ben Thomson <git@alfreido.com> - WordPress plugin only
 * @copyright 2022 Winter CMS
 */
class ImageHandler
{
    /**
     * The cache key prefix for resizer configs
     */
    public const CACHE_PREFIX = 'orgnk.imageresizer.';

    /**
     * @var string Unique identifier for the current configuration
     */
    protected $identifier;

    /**
     * @var string The path to the image
     */
    protected $image;

    /**
     * @var integer Desired width
     */
    protected $width = 0;

    /**
     * @var integer Desired height
     */
    protected $height = 0;

    /**
     * @var array Image resizing configuration data
     */
    protected $options = [];

    /**
     * Prepare the resizer instance
     *
     * @param string $image The path to the image
     * @param integer|string|bool|null $width Desired width of the resized image
     * @param integer|string|bool|null $height Desired height of the resized image
     * @param array|null $options Array of options to pass to the resizer
     */
    public function __construct($image, $width = 0, $height = 0, $options = [])
    {
        $this->image = $image;
        $this->width = (int) (($width === 'auto') ? 0 : $width);
        $this->height = (int) (($height === 'auto') ? 0 : $height);
        $this->options = array_merge($this->getDefaultOptions(), $options);
    }

    /**
     * Get the default options for the resizer
     */
    public function getDefaultOptions(): array
    {
        return [
            'mode'      => 'auto',
            'offset'    => [0, 0],
            'sharpen'   => 0,
            'interlace' => false,
            'quality'   => 90,
            'extension' => $this->getExtension(),
        ];
    }

    /**
     * Get the current config
     */
    public function getConfig(): array
    {
        return [
            'image' => [
                'path' => $this->image,
                'mtime' => filemtime($this->image),
            ],
            'width' => $this->width,
            'height' => $this->height,
            'options' => $this->options,
        ];
    }

    /**
     * Process the resize request
     */
    public function resize(): void
    {
        if ($this->isResized()) {
            return;
        }

        // Copy the image to be resized to the temp directory
        $tempPath = $this->getLocalTempPath();

        // Get the details for the target image
        $resizedPath = $this->getPathToResizedImage();

        try {
            // Process the resize with the default image resizer
            Resizer::open($tempPath)
                ->resize($this->width, $this->height, $this->options)
                ->save($tempPath);

            // Create resized directory if it does not exist
            if (!is_dir($this->getResizedPath())) {
                if (!@mkdir($this->getResizedPath())) {
                    throw new Exception('Could not create resized images directory');
                }
            }

            // Store the resized image
            rename($tempPath, $resizedPath);
        } catch (Exception $ex) {
            // Pass the exception up
            throw $ex;
        } finally {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * Process the crop request
     */
    public function crop(): void
    {
        if ($this->isResized()) {
            return;
        }

        // Copy the image to be resized to the temp directory
        $tempPath = $this->getLocalTempPath();

        // Get the details for the target image
        $resizedPath = $this->getPathToResizedImage();

        try {
            // Process the resize with the default image resizer
            Resizer::open($tempPath)
                ->crop(
                    $this->options['offset'][0],
                    $this->options['offset'][1],
                    $this->width,
                    $this->height
                )
                ->save($tempPath);

            // Create resized directory if it does not exist
            if (!is_dir($this->getResizedPath())) {
                if (!@mkdir($this->getResizedPath())) {
                    throw new Exception('Could not create resized images directory');
                }
            }

            // Store the resized image
            rename($tempPath, $resizedPath);
        } catch (Exception $ex) {
            // Pass the exception up
            throw $ex;
        } finally {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * Stores the current source image in the temp directory and returns the path to it
     */
    protected function getLocalTempPath(): string
    {
        if (!function_exists('wp_tempnam')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        $tempPath = wp_tempnam($this->image);

        file_put_contents($tempPath, $this->getSourceFileContents());

        return $tempPath;
    }

    /**
     * Returns the file extension.
     */
    public function getExtension(): string
    {
        return pathinfo($this->image, PATHINFO_EXTENSION);
    }

    /**
     * Get the contents of the image file to be resized
     */
    public function getSourceFileContents()
    {
        return file_get_contents($this->image);
    }

    /**
     * Get the details for the target image
     */
    protected function getTargetDetails()
    {
        return $this->getPathToResizedImage();
    }

    /**
     * Get the reference to the resized image if the requested resize exists
     */
    public function isResized(): bool
    {
        // Get the details for the target image
        $targetPath = $this->getTargetDetails();

        // Return true if the path is a file and it exists on the target disk
        return !empty(pathinfo($targetPath, PATHINFO_EXTENSION)) && is_file($targetPath);
    }

    /**
     * Get the path to that resized images will be stored within.
     *
     * @param string $path
     * @return string
     */
    public function getResizedPath($path = '')
    {
        return WP_CONTENT_DIR . '/resized-uploads/' . ((!empty($path)) ? ltrim($path, '/\\') : '');
    }

    /**
     * Gets the path of the resized image.
     *
     * @return void
     */
    public function getResizedImage()
    {
        // Generate the unique file identifier for the resized image
        $fileIdentifier = hash_hmac('sha1', serialize($this->getConfig()), AUTH_KEY);

        // Generate the filename for the resized image
        return pathinfo($this->image, PATHINFO_FILENAME)
            . '_resized_'
            . $fileIdentifier
            . '.'
            . $this->options['extension'];
    }

    /**
     * Get the full path of the resized image
     */
    public function getPathToResizedImage()
    {
        return $this->getResizedPath($this->getResizedImage());
    }

    /**
     * Gets the current useful URL to the resized image
     * (resizer if not resized, resized image directly if resized)
     */
    public function getUrl(): string
    {
        if ($this->isResized()) {
            return $this->getResizedUrl();
        } else {
            return $this->getResizerUrl();
        }
    }

    /**
     * Get the URL to the system resizer route for this instance's configuration
     */
    public function getResizerUrl()
    {
        $resizedUrl = rawurlencode($this->getResizedUrl());

        // Get the current configuration's identifier
        $identifier = $this->getIdentifier();

        // Store the current configuration
        $this->storeConfig();

        return home_url('/orgnk-imageresize/' . $identifier . '/' . $resizedUrl);
    }

    /**
     * Get the URL to the resized image
     */
    public function getResizedUrl(): string
    {
        $url = content_url('resized-uploads/' . $this->getResizedImage());

        // Ensure that a properly encoded URL is returned
        $segments = explode('/', $url);
        $lastSegment = array_pop($segments);
        $url = implode('/', $segments) . '/' . rawurlencode(rawurldecode($lastSegment));

        return $url;
    }

    /**
     * Check if the provided identifier looks like a valid identifier
     *
     * @param string $id
     * @return bool
     */
    public static function isValidIdentifier($id): bool
    {
        return is_string($id) && ctype_alnum($id) && strlen($id) === 40;
    }

    /**
     * Gets the identifier for provided resizing configuration
     *
     * @return string 40 character string used as a unique reference to the provided configuration
     */
    public function getIdentifier(): string
    {
        if ($this->identifier) {
            return $this->identifier;
        }

        // Generate & return the identifier
        return $this->identifier = hash_hmac('sha1', $this->getResizedUrl(), AUTH_KEY);
    }

    /**
     * Stores the resizer configuration if the resizing hasn't been completed yet
     */
    public function storeConfig(): void
    {
        // If the image hasn't been resized yet, then store the config data for the resizer to use
        if (!$this->isResized()) {
            set_transient(static::CACHE_PREFIX . $this->getIdentifier(), $this->getConfig());
        }
    }

    /**
     * Instantiate a resizer instance from the provided identifier
     *
     * @param string $identifier The 40 character cache identifier for the desired resizer configuration
     * @throws Exception If the identifier is unable to be loaded
     * @return static
     */
    public static function fromIdentifier(string $identifier)
    {
        // Attempt to retrieve the resizer configuration
        $config = get_transient(static::CACHE_PREFIX . $identifier);

        // Validate that the desired config was able to be loaded
        if ($config === false) {
            throw new Exception('Unable to retrieve the configuration for ' . esc_html($identifier));
        }

        $resizer = new static($config['image']['path'], $config['width'], $config['height'], $config['options']);

        // Remove the data from the cache only after successfully instantiating the resizer
        // in order to make it easier to debug should any issues occur during the instantiation
        // since the browser will "steal" the configuration with the first request it makes
        // if we pull the configuration data out immediately.
        delete_transient(static::CACHE_PREFIX . $identifier);

        return $resizer;
    }

    /**
     * Check the provided encoded URL to verify its signature and return the decoded URL
     *
     * @return string|null Returns null if the provided value was invalid
     */
    public static function getValidResizedUrl(string $identifier, string $encodedUrl)
    {
        // Slashes in URL params have to be double encoded to survive Laravel's router
        // @see https://github.com/octobercms/october/issues/3592#issuecomment-671017380
        $decodedUrl = rawurldecode($encodedUrl);
        $url = null;

        // The identifier should be the signed version of the decoded URL
        if (static::isValidIdentifier($identifier) && $identifier === hash_hmac('sha1', $decodedUrl, AUTH_KEY)) {
            $url = $decodedUrl;
        }

        return $url;
    }

    /**
     * Converts supplied input into a URL that will return the desired resized image
     *
     * @param mixed $image Supported values below:
     *              ['disk' => FilesystemAdapter, 'path' => string, 'source' => string, 'fileModel' => FileModel|void],
     *              instance of Winter\Storm\Database\Attach\File,
     *              string containing URL or path accessible to the application's filesystem manager
     * @param integer|string|bool|null $width Desired width of the resized image
     * @param integer|string|bool|null $height Desired height of the resized image
     * @param array|null $options Array of options to pass to the resizer
     * @throws Exception If the provided image was unable to be processed
     */
    public static function filterGetUrl($image, $width = null, $height = null, $options = []): string
    {
        // Attempt to process the provided image
        try {
            $resizer = new static($image, $width, $height, $options);
        } catch (Exception $ex) {
            // Ignore processing this URL if the resizer is unable to identify it
            if (is_scalar($image) || empty($image)) {
                return (string) $image;
            } else {
                throw $ex;
            }
        }

        return $resizer->getUrl();
    }

    /**
     * Gets the dimensions of the provided image file
     * NOTE: Doesn't currently support being passed a FileModel image that has already been resized
     *
     * @param mixed $image Supported values below:
     *              ['disk' => FilesystemAdapter, 'path' => string, 'source' => string, 'fileModel' => FileModel|void],
     *              instance of Winter\Storm\Database\Attach\File,
     *              string containing URL or path accessible to the application's filesystem manager
     * @throws SystemException If the provided input was unable to be processed
     */
    public static function filterGetDimensions($image): array
    {
        $resizer = new static($image);

        $cacheFound = false;
        $cached = get_transient(static::CACHE_PREFIX . '.dimensions.' . $resizer->getIdentifier());

        if ($cacheFound === true) {
            return $cached;
        }

        // Prepare the local file for assessment
        $tempPath = $resizer->getLocalTempPath();
        $dimensions = [];

        // Attempt to get the image size
        try {
            $size = getimagesize($tempPath);
            $dimensions['width'] = $size[0];
            $dimensions['height'] = $size[1];
        } catch (\Exception $ex) {
            throw $ex;
        } finally {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }
        }

        set_transient(static::CACHE_PREFIX . '.dimensions.' . $resizer->getIdentifier(), $dimensions);

        return $dimensions;
    }

}
