<?php
/** @noinspection PhpUnused */

declare(strict_types = 1);
/**
 * Class Url Handler.
 */
class UrlHandler
{
    public const ERROR_UNDEFINED_PARAMETER_NAME = 0;
    public const ERROR_UNDEFINED_PARAMETER_NAME_2 = 1;
    public const ERROR_UNDEFINED_PARAMETER_NAME_3 = 2;
    /**
     * @var array<int, array<int, mixed>> $results Results array.
     */
    public array $results = array();
    /**
     * @var array<int, string> $current_page_parameters Current page
     * parameters.
     */
    private array $current_page_parameters = array();
    /**
     * @var array<string, string> $parameters_map Parameters map.
     */
    private array $parameters_map = array();
    /**
     * @var int $current_route Current route number.
     */
    private int $current_route = 0;
	/**
	 * @var int $total_routes Total routes number.
	 */
	private int $total_routes = 0;
	/**
	 * @var array $routes Routes array.
	 */
	private array $routes = array();
    /**
     * @var bool $url_rewrite Url rewrite.
     */
    private bool $url_rewrite = true;
    /**
     * @var string $entry_point Entry point.
     */
    private string $entry_point = "index.php";
    /**
     * @var string $base_path Base path.
     */
    private string $base_path = "/";
	/**
	 * @var bool $session_use_cookies Session use cookies flag.
	 */
	private bool $session_use_cookies = false;
	/**
	 * @var string $session_id_regex Session id regex.
	 */
	private string $session_id_regex = "[0-9a-fA-F]{256}";

	public function __construct() {
		// TODO
		$request_uri = $_SERVER['REQUEST_URI']??"";
		$url_path = explode('/', $request_uri);
		$config_base_path = $this->base_path;
		assert(is_string($config_base_path));
		$base_path = explode('/', $config_base_path);
		// Removing base path
		for ($i = 0; $i < count($base_path) - 1; $i++) {
			if ($url_path[$i] === $base_path[$i]) {
				unset($url_path[$i]);
			}
		}
		// Decoding url (%20 -> ' ').
		foreach ($url_path as &$url) {
			$url = urldecode($url);
		}
		// array_filter() removes, if there are any, empty values caused by
		// multiple slashes: example.com/id///page//foo//
		// array_values() reindexes the array.
		$route = array_values(array_filter($url_path));
		$this->total_routes = count($route);
		$this->routes = $route;
	}

    /**
     * Get Configuration Methods.
     * Returns the methods that will be called by the injector.
     * @return array<int, string>
     */
    public function getConfigurationMethods()
    : array
    {
        return array(
            "setEntryPoint",
            "setBasePath",
            "setUrlRewrite",
            "setParametersMap",
			"setSessionUseCookies",
			"setSessionIdRegex",
            "initializeCurrentPageParameters"
        );
    }

	/**
	 * @param string $session_id_regex
	 */
	public function setSessionIdRegex(string $session_id_regex)
	: void {
		$this->session_id_regex = $session_id_regex;
	}

	/**
	 * @param bool $session_use_cookies
	 */
	public function setSessionUseCookies(bool $session_use_cookies)
	: void {
		$this->session_use_cookies = $session_use_cookies;
	}

    /**
     * Set Parameter.
     * Writes a parameter to the global $_GET.
     *
     * @param string $parameter_name Parameter name.
     * @param string $value          Parameter Value.
     *
     * @return bool
     */
    public function setParameter(string $parameter_name, string $value)
    : bool {
        if (!array_key_exists($parameter_name, $this->parameters_map)) {
            $this->results[] = array(
                self::ERROR_UNDEFINED_PARAMETER_NAME,
                array(
                    "parameter_name" => $parameter_name,
                )
            );

            return false;
        }
        $_GET[$this->parameters_map[$parameter_name]] = $value;

        return true;
    }

    /**
     * Set Parameters Map.
     * Sets the current parameters map.
     *
     * @param array<string, string> $parameters_map Parameters map.
     *
     * @return void
     */
    public function setParametersMap(array $parameters_map)
    : void {
        $this->parameters_map = $parameters_map;
    }

    /**
     * Initialize Current Page Parameters.
     * When url rewrite is enabled this function resolves the current page
     * parameters and writes them back to the global $_GET.
     *
     * @param array<int, string> $current_page_parameters_config Current page
     *                                                           parameters
     *                                                           config.
     */
    public function initializeCurrentPageParameters(
        array $current_page_parameters_config
    )
    : void {
        if ($this->url_rewrite) {
			$parameters = array();
            foreach ($current_page_parameters_config as $parameter_config) {
                assert(is_string($parameter_config));
				assert(array_key_exists($parameter_config,
					$this->parameters_map));
				$parameters[] = $this->parameters_map[$parameter_config];
            }
	        // Puts the parameters on the url stack to $_GET
	        $this->setCurrentPageParameters($parameters);
        }
    }


	public function setSessionId(string $session_id_parameter_name) {
		if(!$this->url_rewrite) {
			return;
		}
		if($this->session_use_cookies){
			return;
		}
		if($this->total_routes <= $this->current_route) {
			return;
		}
		if(!preg_match($this->session_id_regex, end($this->routes))) {
			return;
		}
		$_GET[$session_id_parameter_name] = end($this->routes);
	}

    /**
     * Set Current Page Parameters.
     * Sets the parameters for the current page.
     *
     * @param array<int, string> $parameters Page parameters.
     * @param bool               $append     Append flag.
     *
     * @return void
     */
    public function setCurrentPageParameters(
        array $parameters,
        bool $append
        = true
    )
    : void {
        if ($append) {
            $this->current_page_parameters = array_merge(
                $this->current_page_parameters,
                $parameters
            );
            $this->current_route += count($parameters);
        } else {
            $this->current_page_parameters = $parameters;
            $this->current_route = count($parameters);
        }

		$route = $this->routes;

        foreach ($this->current_page_parameters as $key => $parameter) {
            $_GET[$parameter] = (array_key_exists($key, $route)) ?
                $route[$key] : null;
        }
    }

    /**
     * Set Url Rewrite.
     * Sets url rewrite.
     *
     * @param bool $url_rewrite Url Rewrite.
     *
     * @return void
     */
    public function setUrlRewrite(bool $url_rewrite)
    : void {
        $this->url_rewrite = $url_rewrite;
    }

    /**
     * Set Base Path.
     * Sets the current base path.
     *
     * @param string $base_path Base Path.
     *
     * @return void
     */
    public function setBasePath(string $base_path)
    : void {
        $this->base_path = $base_path;
    }

    /**
     * Set Entry Point.
     * Sets the current entry point.
     *
     * @param string $entry_point Entry Point.
     *
     * @return void
     */
    public function setEntryPoint(string $entry_point)
    : void {
        $this->entry_point = $entry_point;
    }

    /**
     * Shift Current Page Parameters.
     * Shifts the current's page parameters array, it's useful when a
     * parameter is weak, like the language parameter.
     *
     * @param int $shift_positions Numbers of positions to shift.
     *
     * @return void
     */
    public function shiftCurrentPageParameters(int $shift_positions)
    : void {
        while ($shift_positions > 0) {
            array_shift($this->current_page_parameters);
            $shift_positions--;
        }
        $this->setCurrentPageParameters($this->current_page_parameters, false);
    }

    /**
     * Get Url.
     * Returns an url given the parameter names.
     *
     * @param array<string, string> $parameters   Parameters array.
     * @param bool                  $keep_current Keep current url flag.
     * @param bool                  $html_encode  HTML encode flag.
     * @param bool                  $full_url     Full url flag.
     *
     * @return string
     */
    public function getUrl(
        array $parameters,
        bool $keep_current = true,
        bool $html_encode = false,
        bool $full_url = false
    )
    : string {
        $url = "";
        if ($full_url) {
            $url = 'http';
            $url .= (array_key_exists("HTTPS", $_SERVER) &&
                     $_SERVER["HTTPS"] === "on") ? "s" : "";
            $url .= "://";
            $server = (array_key_exists("SERVER_NAME", $_SERVER)) ?
                $_SERVER["SERVER_NAME"] : "";
            $url .= ($server === "") ? "localhost" : $server;
            $port = (array_key_exists("SERVER_PORT", $_SERVER)) ?
                $_SERVER["SERVER_PORT"] : "80";
            $url .= ($port === "80") ? "" : $port;
        }

        $data = array();
        if ($keep_current) {
            // Setting the current page parameters data.
            foreach ($this->current_page_parameters as $page_parameter) {
                if (array_key_exists($page_parameter, $_GET)) {
                    $data[$page_parameter] = $_GET[$page_parameter];
                }
            }
        }

        // Overriding/updating and expanding the current url with the
        // provided parameters
        foreach ($parameters as $key => $value) {
            $resolved_key = $this->getParameterName($key);
            if ($resolved_key === null) {
                $this->results[] = array(
                    self::ERROR_UNDEFINED_PARAMETER_NAME_2,
                    array(
                        "parameter_name" => $resolved_key,
                    )
                );
                $resolved_key = "";
            }
            if (array_key_exists($resolved_key, $_GET)) {
                $data[$resolved_key] = $_GET[$resolved_key];
            }
        }

        $url .= $this->base_path;

        // Checking if URL rewrite is enabled.
        if ($this->url_rewrite) {
            // Just putting the values into the urls and returning it.
            foreach ($data as $value) {
                $url .= urlencode($value) . '/';
            }

            return $url;
        }

        // Building the classic url.
        $url .= $this->entry_point;
        $arg_separator = ($html_encode) ? "&amp;" : "&";
        $query = http_build_query(
            $data,
            "",
            $arg_separator
        );
        if ($query !== "") {
            $url .= "?$query";
        }

        return $url;
    }

    /**
     * Get Parameter Name.
     * Returns the resolved name of a parameter.
     *
     * @param string $parameter_name Parameter name.
     *
     * @return string|null
     */
    public function getParameterName(string $parameter_name)
    : string|null {
        if (array_key_exists($parameter_name, $this->parameters_map)) {
            return $this->parameters_map[$parameter_name];
        }

        return null;
    }
}
