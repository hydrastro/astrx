<?php
/** @noinspection PhpUnused */

declare(strict_types = 1);
/**
 * Class Response.
 */
class Response
{
    /**
     * @var int $status_code Response status code.
     */
    private int $status_code = 200;
    /**
     * @var array<string, string> $headers Response headers.
     */
    private array $headers = array();
    /**
     * @var string $content Response content.
     */
    private string $content = "";
    /**
     * @var array<int, array<int, mixed>> $results Results array.
     */
    public array $results = array();
    public const HTTP_CONTINUE = 100;
    public const HTTP_SWITCHING_PROTOCOLS = 101;
    public const HTTP_PROCESSING = 102;
    public const HTTP_EARLY_HINTS = 103;
    public const HTTP_OK = 200;
    public const HTTP_CREATED = 201;
    public const HTTP_ACCEPTED = 202;
    public const HTTP_NON_AUTHORITATIVE_INFORMATION = 203;
    public const HTTP_NO_CONTENT = 204;
    public const HTTP_RESET_CONTENT = 205;
    public const HTTP_PARTIAL_CONTENT = 206;
    public const HTTP_MULTI_STATUS = 207;
    public const HTTP_ALREADY_REPORTED = 208;
    public const HTTP_IM_USED = 226;
    public const HTTP_MULTIPLE_CHOICES = 300;
    public const HTTP_MOVED_PERMANENTLY = 301;
    public const HTTP_FOUND = 302;
    public const HTTP_SEE_OTHER = 303;
    public const HTTP_NOT_MODIFIED = 304;
    public const HTTP_USE_PROXY = 305;
    public const HTTP_RESERVED = 306;
    public const HTTP_TEMPORARY_REDIRECT = 307;
    public const HTTP_PERMANENTLY_REDIRECT = 308;
    public const HTTP_BAD_REQUEST = 400;
    public const HTTP_UNAUTHORIZED = 401;
    public const HTTP_PAYMENT_REQUIRED = 402;
    public const HTTP_FORBIDDEN = 403;
    public const HTTP_NOT_FOUND = 404;
    public const HTTP_METHOD_NOT_ALLOWED = 405;
    public const HTTP_NOT_ACCEPTABLE = 406;
    public const HTTP_PROXY_AUTHENTICATION_REQUIRED = 407;
    public const HTTP_REQUEST_TIMEOUT = 408;
    public const HTTP_CONFLICT = 409;
    public const HTTP_GONE = 410;
    public const HTTP_LENGTH_REQUIRED = 411;
    public const HTTP_PRECONDITION_FAILED = 412;
    public const HTTP_REQUEST_ENTITY_TOO_LARGE = 413;
    public const HTTP_REQUEST_URI_TOO_LONG = 414;
    public const HTTP_UNSUPPORTED_MEDIA_TYPE = 415;
    public const HTTP_REQUESTED_RANGE_NOT_SATISFIABLE = 416;
    public const HTTP_EXPECTATION_FAILED = 417;
    public const HTTP_I_AM_A_TEAPOT = 418;
    public const HTTP_MISDIRECTED_REQUEST = 421;
    public const HTTP_UNPROCESSABLE_ENTITY = 422;
    public const HTTP_LOCKED = 423;
    public const HTTP_FAILED_DEPENDENCY = 424;
    public const HTTP_TOO_EARLY = 425;
    public const HTTP_UPGRADE_REQUIRED = 426;
    public const HTTP_PRECONDITION_REQUIRED = 428;
    public const HTTP_TOO_MANY_REQUESTS = 429;
    public const HTTP_REQUEST_HEADER_FIELDS_TOO_LARGE = 431;
    public const HTTP_UNAVAILABLE_FOR_LEGAL_REASONS = 451;
    public const HTTP_INTERNAL_SERVER_ERROR = 500;
    public const HTTP_NOT_IMPLEMENTED = 501;
    public const HTTP_BAD_GATEWAY = 502;
    public const HTTP_SERVICE_UNAVAILABLE = 503;
    public const HTTP_GATEWAY_TIMEOUT = 504;
    public const HTTP_VERSION_NOT_SUPPORTED = 505;
    public const HTTP_VARIANT_ALSO_NEGOTIATES_EXPERIMENTAL = 506;
    public const HTTP_INSUFFICIENT_STORAGE = 507;
    public const HTTP_LOOP_DETECTED = 508;
    public const HTTP_NOT_EXTENDED = 510;
    public const HTTP_NETWORK_AUTHENTICATION_REQUIRED = 511;
    /**
     * @var array<int, int> $http_status_codes HTTP status codes array.
     */
    public array $http_status_codes
        = array(
            self::HTTP_CONTINUE,
            self::HTTP_SWITCHING_PROTOCOLS,
            self::HTTP_PROCESSING,
            self::HTTP_EARLY_HINTS,
            self::HTTP_OK,
            self::HTTP_CREATED,
            self::HTTP_ACCEPTED,
            self::HTTP_NON_AUTHORITATIVE_INFORMATION,
            self::HTTP_NO_CONTENT,
            self::HTTP_RESET_CONTENT,
            self::HTTP_PARTIAL_CONTENT,
            self::HTTP_MULTI_STATUS,
            self::HTTP_ALREADY_REPORTED,
            self::HTTP_IM_USED,
            self::HTTP_MULTIPLE_CHOICES,
            self::HTTP_MOVED_PERMANENTLY,
            self::HTTP_FOUND,
            self::HTTP_SEE_OTHER,
            self::HTTP_NOT_MODIFIED,
            self::HTTP_USE_PROXY,
            self::HTTP_RESERVED,
            self::HTTP_TEMPORARY_REDIRECT,
            self::HTTP_PERMANENTLY_REDIRECT,
            self::HTTP_BAD_REQUEST,
            self::HTTP_UNAUTHORIZED,
            self::HTTP_PAYMENT_REQUIRED,
            self::HTTP_FORBIDDEN,
            self::HTTP_NOT_FOUND,
            self::HTTP_METHOD_NOT_ALLOWED,
            self::HTTP_NOT_ACCEPTABLE,
            self::HTTP_PROXY_AUTHENTICATION_REQUIRED,
            self::HTTP_REQUEST_TIMEOUT,
            self::HTTP_CONFLICT,
            self::HTTP_GONE,
            self::HTTP_LENGTH_REQUIRED,
            self::HTTP_PRECONDITION_FAILED,
            self::HTTP_REQUEST_ENTITY_TOO_LARGE,
            self::HTTP_REQUEST_URI_TOO_LONG,
            self::HTTP_UNSUPPORTED_MEDIA_TYPE,
            self::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE,
            self::HTTP_EXPECTATION_FAILED,
            self::HTTP_I_AM_A_TEAPOT,
            self::HTTP_MISDIRECTED_REQUEST,
            self::HTTP_UNPROCESSABLE_ENTITY,
            self::HTTP_LOCKED,
            self::HTTP_FAILED_DEPENDENCY,
            self::HTTP_TOO_EARLY,
            self::HTTP_UPGRADE_REQUIRED,
            self::HTTP_PRECONDITION_REQUIRED,
            self::HTTP_TOO_MANY_REQUESTS,
            self::HTTP_REQUEST_HEADER_FIELDS_TOO_LARGE,
            self::HTTP_UNAVAILABLE_FOR_LEGAL_REASONS,
            self::HTTP_INTERNAL_SERVER_ERROR,
            self::HTTP_NOT_IMPLEMENTED,
            self::HTTP_BAD_GATEWAY,
            self::HTTP_SERVICE_UNAVAILABLE,
            self::HTTP_GATEWAY_TIMEOUT,
            self::HTTP_VERSION_NOT_SUPPORTED,
            self::HTTP_VARIANT_ALSO_NEGOTIATES_EXPERIMENTAL,
            self::HTTP_INSUFFICIENT_STORAGE,
            self::HTTP_LOOP_DETECTED,
            self::HTTP_NOT_EXTENDED,
            self::HTTP_NETWORK_AUTHENTICATION_REQUIRED
        );
    public const ERROR_INVALID_HTTP_STATUS_CODE = 0;

    /**
     * Set Status Code.
     * Sets the response status code.
     *
     * @param int $status_code
     *
     * @return bool
     */
    public function setStatusCode(int $status_code)
    : bool {
        if (!in_array($status_code, $this->http_status_codes)) {
            $this->results[] = array(
                self::ERROR_INVALID_HTTP_STATUS_CODE,
                array("status_code" => "$status_code")
            );

            return false;
        }
        $this->status_code = $status_code;

        return true;
    }

    /**
     * Set Content.
     * Sets the response content.
     *
     * @param string $content Content.
     *
     * @return void
     */
    public function setContent(string $content)
    : void {
        $this->content = $content;
    }

    /**
     * Add Header.
     * Adds a header to the response.
     *
     * @param string $header_name  Header name.
     * @param string $header_value Header value.
     *
     * @return void
     */
    public function addHeader(string $header_name, string $header_value)
    : void {
        $this->headers[$header_name] = $header_value;
    }

    /**
     * Send.
     * Send request.
     * @return void
     */
    public function send()
    : void
    {
        http_response_code($this->status_code);
        foreach ($this->headers as $header) {
            header($header, false);
        }
        echo $this->content;
    }
}
