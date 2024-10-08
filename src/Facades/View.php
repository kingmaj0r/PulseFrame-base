<?php

namespace PulseFrame\Facades;

use PulseFrame\Facades\Config;
use PulseFrame\Facades\Env;
use PulseFrame\Http\Handlers\RouteHandler;
use PulseFrame\Facades\Response;
use PulseFrame\Support\Template\Engine;

/**
 * Class View
 * 
 * @category facades
 * @name View
 * 
 * This class handles the rendering of View templates. It initializes the View engine, manages environment configurations, 
 * and integrates with the Vite asset builder for both development and production environments.
 */
class View
{
  public static $view;
  public static $errorPage;
  private $manifest;

  /**
   * View constructor.
   *
   * @category facades
   * 
   * This constructor checks if environment variables are loaded and initializes the View environment 
   * if it hasn't been initialized yet.
   * 
   * Example usage:
   * $view = new View();
   */
  public function __construct()
  {
    if (!self::$view) {
      self::$view = new Engine();
    }

    try {
      $errorPage = Config::get('view', 'error_page');
    } catch (\Exception $e) {
      $errorPage = 'error';
    }

    self::$errorPage = $errorPage;

    $manifestPath = ROOT_DIR . '/public/assets/.vite/manifest.json';

    if (file_exists($manifestPath)) {
      $this->manifest = json_decode(file_get_contents($manifestPath), true);
    }
  }

  /**
   * Render a View template.
   *
   * @category facades
   * 
   * @param string $template The name of the View template.
   * @param array $data The data to pass to the template (optional).
   * @return string The rendered HTML content.
   *
   * This static function renders a View template with the provided data. It checks if the Vite development 
   * server is running and renders appropriate error messages if required assets are missing.
   * 
   * Example usage:
   * $html = View::render('template', ['key' => 'value']);
   */
  public static function render($template, $data = [], $includeExtension = true)
  {
    $manifest = (new self())->manifest;

    if (!self::$view) {
      new self();
    }

    $hotFile = $_ENV['storage_path'] . '/hot';

    $isDev = file_exists($hotFile);

    $data['isDev'] = $isDev;

    if (Env::get("app.settings.debug")) {
      $data['debug'] = Env::get("app.settings.debug");
    }

    if ($isDev && !self::isViteServerRunning()) {
      $message = "Development server not detected.";
      if (Env::get("app.settings.debug")) {
        $message .= "<br>Hint: Make sure the Vite dev server is running.<br>If this is a mistake, delete the <code>storage/hot</code> file.";
      }
      return self::$view->render(self::$errorPage, ['status' => Response::HTTP_INTERNAL_SERVER_ERROR, 'message' => $message]);
    } elseif (!$isDev && (!isset($manifest) || empty($manifest))) {
      $message = "Manifest file not found";
      if (Env::get("app.settings.debug")) {
        $message .= "<br>Hint: Make sure you have built the project.<br>Not sure? Run <code>npx vite build</code>.";
      }
      return self::$view->render(self::$errorPage, ['status' => Response::HTTP_INTERNAL_SERVER_ERROR, 'message' => $message]);
    } else {
      $view = new self();
      $response = $view->renderView($template, $data, $includeExtension);
      return $response->getContent();
    }
  }

  /**
   * Check if Vite development server is running.
   *
   * @category facades
   * 
   * @return bool True if the Vite server is running, otherwise false.
   *
   * This private function checks the availability of the Vite development server by sending a request to 
   * the specified Vite server URL.
   * 
   * Example usage:
   * $isRunning = View::isViteServerRunning();
   */
  private static function isViteServerRunning(): bool
  {
    $viteServerUrl = Env::get("app.vite_dev") . "/@vite/client";
    $headers = @get_headers($viteServerUrl);

    return $headers && strpos($headers[0], '200') !== false;
  }

  public static function assetUrl(string $path, $data): string
  {
    if ($data['isDev'] == 'true' || $data['settings']['debug'] === false) {
      return Env::get("app.vite_dev") . '/' . ltrim($path, '/');
    }

    return Env::get("app.vite_dev") . '/assets/' . ltrim($path, '/');
  }

  /**
   * Render a view with assets.
   *
   * @category facades
   * 
   * @param string $template The name of the template.
   * @param array $data The data to pass to the template (optional).
   * @return Response The HTTP response.
   *
   * This function renders a View template and resolves Vite assets.
   * 
   * Example usage:
   * $response = $view->renderView('template.vue', ['key' => 'value']);
   */
  private function renderView(string $template, array $data = [], $includeExtension = true): Response
  {
    $extension = substr($template, -4);

    if (in_array($extension, ['.vue', '.ts', '.tsx'])) {
      $template = str_replace($extension, '', $template);

      $data['app'] = array_merge([
        'session' => $_SESSION,
        'settings' => Env::get('app.settings'),
        'page' => $template,
        'routes' => RouteHandler::$routeNames
      ], $data);

      if (isset($_SESSION['password'])) {
        unset($_SESSION['password']);
      }

      $template = 'base';

      try {
        $assets = $this->resolveAssets(Config::get('view', 'entry'));

        self::$view->addFunction(
          'assetUrl',
          function ($path) use ($data) {
            return $this->assetUrl($path, $data['app']);
          }
        );

        $assetsTemplate = self::$view->render('assets', ['assets' => $assets, 'entry' => Config::get('view', 'entry'), 'isDev' => $data['app']['isDev']]);
        $viteReactRefresh = self::$view->render('ViteReactRefresh', ['isDev' => $data['app']['isDev']]);
        $data['assets'] = $assetsTemplate;
        $data['viteReactRefresh'] = $viteReactRefresh;
      } catch (\Exception $e) {
        return self::$view->render(self::$errorPage, [
          'status' => Response::HTTP_INTERNAL_SERVER_ERROR,
          'message' => $e->getMessage(),
        ]);
      }
    }

    return new Response(self::$view->render($template, $data, $includeExtension));
  }

  /**
   * Resolve assets and dependencies.
   *
   * @category facades
   * 
   * @param string $entry The entry point.
   * @return array The resolved assets and dependencies.
   *
   * This function resolves the assets and dependencies for the given entry by processing the manifest file.
   * 
   * Example usage:
   * $assets = $view->resolveAssets('main.js');
   */
  private function resolveAssets(string $entry): array
  {
    if (!isset($this->manifest[$entry])) {
      throw new \RuntimeException("Entry $entry not found in manifest");
    }

    $result = [];
    $stack = [$entry];

    while (!empty($stack)) {
      $currentEntry = array_pop($stack);

      if (!isset($this->manifest[$currentEntry])) {
        continue;
      }

      $entryData = $this->manifest[$currentEntry];

      if (isset($entryData['css'])) {
        foreach ($entryData['css'] as $css) {
          $result[] = $css;
        }
      }

      $result[] = $entryData['file'];

      if (isset($entryData['imports'])) {
        foreach ($entryData['imports'] as $import) {
          $stack[] = $import;
        }
      }

      unset($this->manifest[$currentEntry]);
    }

    return array_unique($result);
  }
}
