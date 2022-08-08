<?php

namespace Organik\ImageResizer\Classes;

class Router
{
    /**
     * The single instance of OrganikProjects
     */
    private static $instance = null;

    /**
     * Main class instance
     * Ensures only one instance of this class is loaded or can be loaded
     */
    public static function instance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    public function __construct()
    {
        $this->createRoute();
    }

    protected function createRoute()
    {
        add_filter('query_vars', function ($vars) {
            $vars[] = 'orgnk-imageresize';
            $vars[] = 'orgnk-imageresize-id';
            $vars[] = 'orgnk-imageresize-url';

            return $vars;
        });
        add_filter('init', function () {
            add_rewrite_rule(
                '^orgnk-imageresize\/([a-zA-Z0-9]+)\/(.*)$',
                'index.php?orgnk-imageresize=1&orgnk-imageresize-id=$matches[1]&orgnk-imageresize-url=$matches[2]',
                'top'
            );
        });
        add_action('parse_request', function ($wp) {
            if (!array_key_exists('orgnk-imageresize', $wp->query_vars)) {
                return;
            }

            $identifier = $wp->query_vars['orgnk-imageresize-id'];
            $encUrl = $wp->query_vars['orgnk-imageresize-url'];

            $resizedUrl = ImageHandler::getValidResizedUrl($identifier, $encUrl);

            if (empty($resizedUrl)) {
                wp_redirect(redirect_guess_404_permalink(), 404);
                exit;
            }

            try {
                $resizer = ImageHandler::fromIdentifier($identifier);
                $resizer->resize();
            } catch (\Exception $e) {
                if (!empty($resizer)) {
                    $resizer->storeConfig();
                }

                throw $e;
            }

            wp_redirect($resizedUrl);
            exit;
        });
    }
}
